<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_REST_Request;
use WP_UnitTestCase;

final class VisitorRestTest extends WP_UnitTestCase {

	private ConversationsController $controller;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$health           = new SchemaHealth();
		$this->controller = new ConversationsController(
			$health,
			new ConversationRepository( $health ),
			new MessageRepository( $health, new CredentialVault() )
		);
	}

	private function auth_as( int $user_id ): string {
		wp_set_current_user( $user_id );
		$nonce                      = wp_create_nonce( 'wp_rest' );
		$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		return $nonce;
	}

	private function clear_auth(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
	}

	public function test_unauthenticated_start_returns_401(): void {
		$this->clear_auth();
		$request  = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations' );
		$response = $this->controller->handle_start( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_missing_nonce_returns_401_when_logged_in(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );

		$request  = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations' );
		$response = $this->controller->handle_start( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_start_mine_post_poll_happy_path(): void {
		$user_id = self::factory()->user->create();
		$this->auth_as( $user_id );

		$start = $this->controller->handle_start( new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations' ) );
		$this->assertSame( 200, $start->get_status() );
		$data = $start->get_data();
		$this->assertTrue( $data['ok'] );
		$uuid = $data['conversation_uuid'];
		$this->assertNotEmpty( $uuid );

		$mine = $this->controller->handle_mine( new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/mine' ) );
		$this->assertSame( $uuid, $mine->get_data()['conversation_uuid'] );

		$post                      = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations/' . $uuid . '/messages' );
		$post['conversation_uuid'] = $uuid;
		$post->set_param( 'text', 'Hello support' );
		$post->set_param( 'idempotency_key', wp_generate_uuid4() );
		$posted = $this->controller->handle_post_message( $post );
		$this->assertSame( 200, $posted->get_status() );

		$poll                      = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $uuid );
		$poll['conversation_uuid'] = $uuid;
		$polled                    = $this->controller->handle_poll( $poll );
		$this->assertSame( 200, $polled->get_status() );
		$payload = $polled->get_data();
		$this->assertSame( 'open', $payload['status'] );
		$this->assertCount( 1, $payload['messages'] );
		$this->assertSame( 'Hello support', $payload['messages'][0]['text'] );
		$this->assertSame( 'visitor', $payload['messages'][0]['direction'] );
		$this->assertSame( 'You', $payload['messages'][0]['author_label'] );
	}

	public function test_cross_visitor_returns_uniform_404(): void {
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->auth_as( $owner );

		$start = $this->controller->handle_start( new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations' ) );
		$uuid  = $start->get_data()['conversation_uuid'];

		$this->auth_as( $other );
		$poll                      = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $uuid );
		$poll['conversation_uuid'] = $uuid;
		$polled                    = $this->controller->handle_poll( $poll );
		$this->assertSame( 404, $polled->get_status() );
		$this->assertSame( 'not_found', $polled->get_data()['reason'] );

		$missing_uuid                 = wp_generate_uuid4();
		$unknown                      = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $missing_uuid );
		$unknown['conversation_uuid'] = $missing_uuid;
		$missing                      = $this->controller->handle_poll( $unknown );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'not_found', $missing->get_data()['reason'] );
	}

	public function test_invalid_text_rejected(): void {
		$user_id = self::factory()->user->create();
		$this->auth_as( $user_id );
		$uuid = $this->controller->handle_start( new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations' ) )->get_data()['conversation_uuid'];

		$post                      = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations/' . $uuid . '/messages' );
		$post['conversation_uuid'] = $uuid;
		$post->set_param( 'text', '' );
		$response = $this->controller->handle_post_message( $post );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_contract_discovery_is_inert(): void {
		$discovery = new ContractDiscovery();
		$response  = $discovery->handle_discover();
		$data      = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( ContractDiscovery::CONTRACT_VERSION_ID, $data['contract_version'] );
		$this->assertFalse( $data['adapter_required'] );
		$this->assertFalse( $data['channel_available'] );
	}
}
