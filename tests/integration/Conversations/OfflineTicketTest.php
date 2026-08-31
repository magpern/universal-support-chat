<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityResolver;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Conversations\Conversation;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class OfflineTicketTest extends WP_UnitTestCase {

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->health        = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->health );
		$this->messages      = new MessageRepository( $this->health, new CredentialVault() );

		// The offline path opens an explicit transaction and COMMITs it,
		// which implicitly commits WP_UnitTestCase's wrapping transaction, so
		// rows and options written by these tests are not rolled back
		// automatically.
		delete_option( AvailabilityService::OVERRIDE_OPTION );
		delete_option( Settings::OPTION_NAME );
		$this->truncate();
	}

	public function tear_down(): void {
		delete_option( AvailabilityService::OVERRIDE_OPTION );
		delete_option( Settings::OPTION_NAME );
		$this->truncate();
		parent::tear_down();
	}

	private function truncate(): void {
		global $wpdb;
		foreach (
			array(
				Migrator::CONVERSATION_MESSAGES_TABLE,
				Migrator::CONVERSATION_NOTES_TABLE,
				Migrator::TELEGRAM_DISPATCH_TABLE,
				Migrator::AUDIT_LOG_TABLE,
				Migrator::CONVERSATIONS_TABLE,
			) as $t
		) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup after committed transactions.
		}
	}

	private function auth_as( int $user_id ): void {
		wp_set_current_user( $user_id );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
	}

	private function force( string $mode ): void {
		update_option(
			AvailabilityService::OVERRIDE_OPTION,
			array(
				'mode'       => $mode,
				'expires_at' => null,
				'set_by'     => 1,
				'set_at'     => time(),
			)
		);
	}

	private function controller( ?ConversationRepository $conversations = null ): ConversationsController {
		$conversations = $conversations ?? $this->conversations;

		return new ConversationsController(
			$this->health,
			$conversations,
			$this->messages,
			null,
			new AvailabilityService( new Settings(), new AvailabilityResolver() )
		);
	}

	private function start( ConversationsController $controller ): string {
		$res = $controller->handle_start( new WP_REST_Request( 'POST', '/x' ) );
		return $res->get_data()['conversation_uuid'];
	}

	private function post_message( ConversationsController $controller, string $uuid, string $text ): \WP_REST_Response {
		$req                      = new WP_REST_Request( 'POST', '/x' );
		$req['conversation_uuid'] = $uuid;
		$req->set_param( 'text', $text );
		$req->set_param( 'idempotency_key', wp_generate_uuid4() );
		return $controller->handle_post_message( $req );
	}

	public function test_unavailable_visitor_message_creates_waiting_for_operator_ticket_without_telegram(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );

		$controller = $this->controller();
		$uuid       = $this->start( $controller );

		$posted = $this->post_message( $controller, $uuid, 'I need help after hours' );
		$this->assertSame( 200, $posted->get_status() );
		$this->assertSame( 'unavailable', $posted->get_data()['availability'] );

		$conversation = $this->conversations->find_by_uuid( $uuid );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $conversation->status() );

		$stored = $this->messages->list_for_conversation( $conversation->id(), 0, 10 );
		$this->assertCount( 1, $stored );
	}

	public function test_unavailable_transition_from_new_uses_the_direct_edge(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();

		// A conversation still in `new` (not started through handle_start).
		$conversation = $this->conversations->create( $user );
		$this->assertSame( ConversationStatus::NEW, $conversation->status() );

		$posted = $this->post_message( $controller, $conversation->uuid(), 'first message, after hours' );
		$this->assertSame( 200, $posted->get_status() );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_uuid( $conversation->uuid() )->status() );
	}

	public function test_unavailable_transitions_from_open_and_waiting_for_visitor(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();

		// From open.
		$uuid = $this->start( $controller );
		$this->conversations->transition( $this->conversations->find_by_uuid( $uuid ), ConversationStatus::OPEN );
		$this->post_message( $controller, $uuid, 'hi' );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_uuid( $uuid )->status() );

		// From waiting_for_visitor.
		$c = $this->conversations->find_by_uuid( $uuid );
		$this->conversations->transition( $c, ConversationStatus::WAITING_FOR_VISITOR );
		$this->post_message( $controller, $uuid, 'still there?' );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_uuid( $uuid )->status() );
	}

	public function test_available_path_is_unchanged(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_ONLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();

		$uuid   = $this->start( $controller );
		$posted = $this->post_message( $controller, $uuid, 'hello' );
		$this->assertSame( 'available', $posted->get_data()['availability'] );
		$this->assertSame( ConversationStatus::OPEN, $this->conversations->find_by_uuid( $uuid )->status() );
	}

	public function test_start_and_poll_responses_carry_availability(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();

		$start = $controller->handle_start( new WP_REST_Request( 'POST', '/x' ) );
		$this->assertSame( 'unavailable', $start->get_data()['availability'] );

		$uuid                      = $start->get_data()['conversation_uuid'];
		$poll                      = new WP_REST_Request( 'GET', '/x' );
		$poll['conversation_uuid'] = $uuid;
		$this->assertSame( 'unavailable', $controller->handle_poll( $poll )->get_data()['availability'] );

		// Flip the override; the next poll reflects it.
		delete_option( AvailabilityService::OVERRIDE_OPTION );
		$this->force( AvailabilityOverride::MODE_FORCE_ONLINE );
		$this->assertSame( 'available', $controller->handle_poll( $poll )->get_data()['availability'] );
	}

	public function test_idempotent_resubmit_creates_no_duplicate_ticket(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();
		$uuid       = $this->start( $controller );

		$key                      = wp_generate_uuid4();
		$req                      = new WP_REST_Request( 'POST', '/x' );
		$req['conversation_uuid'] = $uuid;
		$req->set_param( 'text', 'dup' );
		$req->set_param( 'idempotency_key', $key );

		$controller->handle_post_message( $req );
		$controller->handle_post_message( $req );

		$conversation = $this->conversations->find_by_uuid( $uuid );
		$this->assertCount( 1, $this->messages->list_for_conversation( $conversation->id(), 0, 10 ) );
		$this->assertSame( 1, $this->conversations->list_waiting( 1, 20 )['total'] );
	}

	public function test_forced_transition_failure_rolls_the_message_back(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );

		$blocking = new class( $this->health ) extends ConversationRepository {
			public function transition( Conversation $conversation, string $to ): ?Conversation {
				unset( $conversation, $to );
				return null; // simulate a transition failure
			}
		};

		// A fresh `new` conversation so the offline path must transition it.
		$conversation = $this->conversations->create( $user );

		$controller = $this->controller( $blocking );
		$posted     = $this->post_message( $controller, $conversation->uuid(), 'should roll back' );
		$this->assertSame( 503, $posted->get_status() );

		$fresh = $this->conversations->find_by_uuid( $conversation->uuid() );
		$this->assertSame( ConversationStatus::NEW, $fresh->status() );
		$this->assertCount( 0, $this->messages->list_for_conversation( $fresh->id(), 0, 10 ) );
	}

	public function test_operator_reply_from_waiting_for_operator_returns_to_waiting_for_visitor(): void {
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );
		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$controller = $this->controller();
		$uuid       = $this->start( $controller );
		$this->post_message( $controller, $uuid, 'help' );

		$conversation = $this->conversations->find_by_uuid( $uuid );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $conversation->status() );

		// The existing Hub reply routes waiting_for_operator -> open -> waiting_for_visitor.
		$opened = $this->conversations->transition( $conversation, ConversationStatus::OPEN );
		$this->conversations->transition( $opened, ConversationStatus::WAITING_FOR_VISITOR );
		$this->assertSame( ConversationStatus::WAITING_FOR_VISITOR, $this->conversations->find_by_uuid( $uuid )->status() );
	}

	public function test_dispatch_enabled_offline_message_commits_one_outbox_row_without_calling_telegram(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) + ( new Settings() )->defaults() );
		$this->force( AvailabilityOverride::MODE_FORCE_OFFLINE );

		$outbox            = new DispatchOutboxRepository( $this->health );
		$enqueuer          = new DispatchEnqueuer( new Settings(), $outbox );
		$telegram_requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$telegram_requests ) {
				unset( $args );
				if ( is_string( $url ) && false !== strpos( $url, 'telegram' ) ) {
					++$telegram_requests;
				}
				return new \WP_Error( 'blocked', 'no external http in tests' );
			},
			10,
			3
		);

		$controller = new ConversationsController(
			$this->health,
			$this->conversations,
			$this->messages,
			$enqueuer,
			new AvailabilityService( new Settings(), new AvailabilityResolver() )
		);

		$user = self::factory()->user->create();
		$this->auth_as( $user );
		$uuid = $this->start( $controller );

		$posted = $this->post_message( $controller, $uuid, 'after hours, dispatch on' );
		$this->assertSame( 200, $posted->get_status() );
		$this->assertSame( 'unavailable', $posted->get_data()['availability'] );

		$conversation = $this->conversations->find_by_uuid( $uuid );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $conversation->status() );

		// Exactly one content-free outbox row was committed in the same
		// transaction — and no Telegram HTTP call happened in the request
		// (all delivery is the async worker's job).
		$counts = $outbox->count_by_state();
		$this->assertSame( 1, (int) array_sum( $counts ) );
		$this->assertSame( 0, $telegram_requests );
	}
}
