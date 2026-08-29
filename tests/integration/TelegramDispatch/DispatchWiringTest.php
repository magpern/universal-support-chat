<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationDispatcher;
use UniversalSupportChat\Conversations\Conversation;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationFailureCode;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;
use UniversalSupportChat\Tests\Integration\TelegramDispatch\Support\RecordingAdapterContractClient;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The enabled path opens a real transaction (ADR-0012), which implicitly
 * commits WP_UnitTestCase's own wrapping transaction, so rows written here
 * survive the test — every test explicitly cleans the relevant tables on
 * both set_up and tear_down.
 */
final class DispatchWiringTest extends WP_UnitTestCase {

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private DispatchOutboxRepository $outbox;
	private DispatchEnqueuer $enqueuer;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_committed_tables();

		$this->health        = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->conversations = new ConversationRepository( $this->health );
		$this->messages      = new MessageRepository( $this->health, $vault );
		$this->outbox        = new DispatchOutboxRepository( $this->health );
		$this->enqueuer      = new DispatchEnqueuer( new Settings(), $this->outbox );

		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) );
	}

	public function tear_down(): void {
		$this->truncate_committed_tables();
		parent::tear_down();
	}

	private function truncate_committed_tables(): void {
		global $wpdb;

		foreach (
			array(
				Migrator::TELEGRAM_DISPATCH_TABLE,
				Migrator::CONVERSATION_NOTES_TABLE,
				Migrator::CONVERSATION_MESSAGES_TABLE,
				Migrator::CHANNEL_STATUS_TABLE,
				Migrator::CONVERSATIONS_TABLE,
				Migrator::AUDIT_LOG_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}
	}

	private function controller( ?DispatchEnqueuer $enqueuer ): ConversationsController {
		return new ConversationsController( $this->health, $this->conversations, $this->messages, $enqueuer );
	}

	private function post_visitor_message( ?DispatchEnqueuer $enqueuer, Conversation $conversation, string $text, ?string $key = null ): WP_REST_Response {
		wp_set_current_user( $conversation->owner_user_id() );
		$_SERVER['HTTP_X_WP_NONCE']   = wp_create_nonce( 'wp_rest' );
		$request                      = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations/' . $conversation->uuid() . '/messages' );
		$request['conversation_uuid'] = $conversation->uuid();
		$request->set_param( 'text', $text );
		if ( null !== $key ) {
			$request->set_param( 'idempotency_key', $key );
		}

		return $this->controller( $enqueuer )->handle_post_message( $request );
	}

	private function open_conversation(): Conversation {
		$user         = self::factory()->user->create();
		$conversation = $this->conversations->create( $user );
		$opened       = $this->conversations->transition( $conversation, ConversationStatus::OPEN );

		return $opened ?? $conversation;
	}

	public function test_visitor_message_and_outbox_row_commit_together(): void {
		$conversation = $this->open_conversation();

		$response = $this->post_visitor_message( $this->enqueuer, $conversation, 'Hi there' );
		$this->assertTrue( $response->get_data()['ok'] );

		$message_uuid = $response->get_data()['message_uuid'];
		$this->assertNotNull( $this->messages->find_by_uuid( $message_uuid ) );

		$record = $this->outbox->find( $message_uuid );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_PENDING, $record->state() );
		$this->assertSame( 'visitor', $record->direction() );
		$this->assertSame( $conversation->uuid(), $record->conversation_uuid() );
	}

	public function test_outbox_write_failure_rolls_back_the_message(): void {
		// An outbox whose schema is unavailable: enqueue() cannot write.
		$broken_schema = new SchemaHealth();
		$broken_schema->mark_unavailable( MigrationFailureCode::STEP_FAILED );
		$broken_enqueuer = new DispatchEnqueuer( new Settings(), new DispatchOutboxRepository( $broken_schema ) );

		$conversation = $this->open_conversation();
		$before       = count( $this->messages->list_for_conversation( $conversation->id() ) );

		$response = $this->post_visitor_message( $broken_enqueuer, $conversation, 'should not persist' );

		// Atomic: no message is left behind without an outbox row.
		$this->assertFalse( $response->get_data()['ok'] );
		$this->assertSame( 503, $response->get_status() );
		$this->assertCount( $before, $this->messages->list_for_conversation( $conversation->id() ) );
		$this->assertSame( array(), $this->outbox->count_by_state() );
	}

	public function test_disabled_path_does_not_touch_the_outbox(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => false ) );

		$conversation = $this->open_conversation();
		$response     = $this->post_visitor_message( $this->enqueuer, $conversation, 'Still works' );

		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertNotEmpty( $response->get_data()['message_uuid'] );
		$this->assertNull( $this->outbox->find( $response->get_data()['message_uuid'] ) );
	}

	public function test_idempotent_message_retry_does_not_roll_back_when_outbox_row_already_exists(): void {
		$conversation = $this->open_conversation();
		$key          = wp_generate_uuid4();

		$first = $this->post_visitor_message( $this->enqueuer, $conversation, 'first', $key );
		$this->assertTrue( $first->get_data()['ok'] );

		// Same idempotency key: MessageRepository returns the existing
		// message and the outbox row already exists — must still be a 200,
		// not a rolled-back 503.
		$second = $this->post_visitor_message( $this->enqueuer, $conversation, 'first', $key );
		$this->assertTrue( $second->get_data()['ok'] );
		$this->assertSame( $first->get_data()['message_uuid'], $second->get_data()['message_uuid'] );
	}

	public function test_telegram_originated_operator_reply_is_marked_suppressed_and_never_loops(): void {
		$conversation = $this->open_conversation();

		$dispatcher = new ContractOperationDispatcher(
			$this->conversations,
			$this->messages,
			new ChannelStatusRepository( $this->health ),
			new AuditLogger( $this->health, new Redactor() ),
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
		$record = $this->outbox->find( $result['body']['message_uuid'] );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_SUPPRESSED, $record->state() );
		$this->assertSame( DispatchRecord::ORIGIN_TELEGRAM, $record->origin() );

		$this->assertSame( array(), $this->outbox->claim_due( 20 ) );
	}

	// ---- ADR-0014 §3: the bounded immediate attempt ----

	private RecordingAdapterContractClient $immediate_client;

	private function enqueuer_with_immediate( ?RecordingAdapterContractClient $client = null ): DispatchEnqueuer {
		$this->immediate_client = $client ?? new RecordingAdapterContractClient();
		$service                = new TelegramDispatchService(
			new Settings(),
			$this->outbox,
			$this->messages,
			$this->immediate_client,
			new AuditLogger( $this->health, new Redactor() )
		);
		$enqueuer               = new DispatchEnqueuer( new Settings(), $this->outbox );
		$enqueuer->set_immediate_dispatch( $service );

		return $enqueuer;
	}

	public function test_immediate_attempt_runs_after_commit_and_delivers_as_interactive_chat(): void {
		$conversation = $this->open_conversation();

		$response = $this->post_visitor_message( $this->enqueuer_with_immediate(), $conversation, 'Interactive please' );
		$this->assertTrue( $response->get_data()['ok'] );

		$deliver = $this->immediate_client->calls_for( 'deliver_message' );
		$this->assertCount( 1, $deliver );
		$this->assertSame( TelegramDispatchService::DELIVERY_CLASS_INTERACTIVE, $deliver[0]['delivery_class'] );

		// The row committed first, then was delivered in-request.
		$record = $this->outbox->find( $response->get_data()['message_uuid'] );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $record->state() );
	}

	public function test_disabled_dispatch_makes_no_immediate_attempt(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => false ) );
		$conversation = $this->open_conversation();

		$response = $this->post_visitor_message( $this->enqueuer_with_immediate(), $conversation, 'no mirror' );

		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( array(), $this->immediate_client->calls );
	}

	public function test_visitor_response_is_ok_when_the_immediate_attempt_throws(): void {
		$throwing     = new class() extends RecordingAdapterContractClient {
			public function ensure_channel_case( string $peer_id, string $conversation_uuid, string $reason_code, array $summary_meta = array() ): array {
				throw new \RuntimeException( 'adapter exploded' );
			}
		};
		$conversation = $this->open_conversation();

		$response = $this->post_visitor_message( $this->enqueuer_with_immediate( $throwing ), $conversation, 'still fine' );

		$this->assertTrue( $response->get_data()['ok'], 'the website response never fails on an immediate-attempt error' );
		$this->assertNotEmpty( $response->get_data()['message_uuid'] );

		// The committed message + outbox row survive and are retryable.
		$record = $this->outbox->find( $response->get_data()['message_uuid'] );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_FAILED, $record->state() );
	}

	public function test_telegram_originated_reply_triggers_no_immediate_attempt(): void {
		$conversation = $this->open_conversation();
		$enqueuer     = $this->enqueuer_with_immediate();

		$dispatcher = new ContractOperationDispatcher(
			$this->conversations,
			$this->messages,
			new ChannelStatusRepository( $this->health ),
			new AuditLogger( $this->health, new Redactor() ),
			$enqueuer
		);

		$dispatcher->dispatch(
			'ingest_operator_reply',
			'universal-telegram',
			array(
				'channel_case_ref' => $conversation->uuid(),
				'body'             => 'from telegram',
				'idempotency_key'  => wp_generate_uuid4(),
			)
		);

		$this->assertSame( array(), $this->immediate_client->calls );
	}
}
