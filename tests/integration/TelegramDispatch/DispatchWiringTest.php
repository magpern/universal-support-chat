<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository;
use UniversalSupportChat\ChannelContract\HandoffMapRepository;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationDispatcher;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use WP_REST_Request;
use WP_UnitTestCase;

final class DispatchWiringTest extends WP_UnitTestCase {

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private DispatchOutboxRepository $outbox;
	private DispatchEnqueuer $enqueuer;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->health        = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->conversations = new ConversationRepository( $this->health );
		$this->messages      = new MessageRepository( $this->health, $vault );
		$this->outbox        = new DispatchOutboxRepository( $this->health );
		$this->enqueuer      = new DispatchEnqueuer( new Settings(), $this->outbox );

		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) );
	}

	private function controller(): ConversationsController {
		return new ConversationsController( $this->health, $this->conversations, $this->messages, $this->enqueuer );
	}

	private function post_visitor_message( string $conversation_uuid, int $user_id, string $text ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$_SERVER['HTTP_X_WP_NONCE']   = wp_create_nonce( 'wp_rest' );
		$request                      = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations/' . $conversation_uuid . '/messages' );
		$request['conversation_uuid'] = $conversation_uuid;
		$request->set_param( 'text', $text );

		return $this->controller()->handle_post_message( $request );
	}

	public function test_visitor_message_is_enqueued_when_enabled(): void {
		$user         = self::factory()->user->create();
		$conversation = $this->conversations->create( $user );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$response = $this->post_visitor_message( $conversation->uuid(), $user, 'Hi there' );
		$this->assertTrue( $response->get_data()['ok'] );

		$record = $this->outbox->find( $response->get_data()['message_uuid'] );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_PENDING, $record->state() );
		$this->assertSame( 'visitor', $record->direction() );
		$this->assertSame( $conversation->uuid(), $record->conversation_uuid() );
	}

	public function test_visitor_message_is_not_enqueued_when_disabled(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => false ) );
		$user         = self::factory()->user->create();
		$conversation = $this->conversations->create( $user );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$response = $this->post_visitor_message( $conversation->uuid(), $user, 'Hi there' );

		$this->assertNull( $this->outbox->find( $response->get_data()['message_uuid'] ) );
	}

	public function test_normal_visitor_flow_is_preserved_when_outbox_schema_is_gone(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$user         = self::factory()->user->create();
		$conversation = $this->conversations->create( $user );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$response = $this->post_visitor_message( $conversation->uuid(), $user, 'Still works' );

		// The message is still stored and the response is still a success —
		// dispatch failure must never surface to the visitor.
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertNotEmpty( $response->get_data()['message_uuid'] );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();
	}

	public function test_telegram_originated_operator_reply_is_marked_suppressed_and_never_loops(): void {
		$user         = self::factory()->user->create();
		$conversation = $this->conversations->create( $user );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$dispatcher = new ContractOperationDispatcher(
			$this->conversations,
			$this->messages,
			new ChannelStatusRepository( $this->health ),
			new AuditLogger( $this->health, new Redactor() ),
			new HandoffMapRepository( $this->health ),
			$this->enqueuer
		);

		$result = $dispatcher->dispatch(
			'ingest_operator_reply',
			'universal-telegram',
			array(
				'channel_case_ref' => $conversation->uuid(),
				'body'             => 'Reply typed in Telegram',
				'idempotency_key'  => wp_generate_uuid4(),
			)
		);

		$this->assertSame( 200, $result['status'] );
		$message_uuid = $result['body']['message_uuid'];

		$record = $this->outbox->find( $message_uuid );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_SUPPRESSED, $record->state() );
		$this->assertSame( DispatchRecord::ORIGIN_TELEGRAM, $record->origin() );

		// The worker must never pick it up.
		$this->assertSame( array(), $this->outbox->claim_due( 20 ) );
	}
}
