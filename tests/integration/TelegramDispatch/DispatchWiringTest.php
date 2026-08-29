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
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use UniversalSupportChat\TelegramDispatch\DispatchWorker;
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

	// ---- ADR-0014 Amendment 1: the request does zero Telegram I/O ----

	/**
	 * @var array<int, string>
	 */
	private array $http_urls = array();

	private function spy_http(): void {
		$this->http_urls = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->http_urls[] = (string) $url;

				// Fail every outbound request — the point is that the visitor
				// request must not depend on any of them.
				return new \WP_Error( 'blocked_in_test', 'no outbound HTTP in tests' );
			},
			10,
			3
		);
	}

	private function assert_no_telegram_http(): void {
		foreach ( $this->http_urls as $url ) {
			$this->assertStringNotContainsString( 'api.telegram.org', $url, 'the visitor / Hub request made a Telegram API call' );
		}
	}

	public function test_visitor_rest_message_makes_no_telegram_http_and_schedules_the_worker(): void {
		$this->spy_http();
		wp_unschedule_hook( DispatchWorker::HOOK );

		$conversation = $this->open_conversation();
		$response     = $this->post_visitor_message( $this->enqueuer, $conversation, 'no sync telegram please' );

		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertNotNull( $this->messages->find_by_uuid( $response->get_data()['message_uuid'] ) );
		$this->assertNotNull( $this->outbox->find( $response->get_data()['message_uuid'] ) );

		$this->assert_no_telegram_http();
		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::HOOK ), 'the async worker run was scheduled' );
	}

	public function test_the_enqueuer_makes_no_contract_or_telegram_call_for_either_direction(): void {
		$this->spy_http();

		foreach ( array( ConversationMessage::DIRECTION_VISITOR, ConversationMessage::DIRECTION_OPERATOR ) as $direction ) {
			$conversation = $this->open_conversation();
			$message      = $this->enqueuer->persist_and_enqueue(
				$conversation->uuid(),
				fn (): ?ConversationMessage => $this->messages->create( $conversation->id(), $direction, 'body', 'stored', null )
			);

			$this->assertInstanceOf( ConversationMessage::class, $message );
			$this->assertNotNull( $this->outbox->find( $message->uuid() ), "outbox row committed for {$direction}" );
			$this->assertSame( DispatchRecord::STATE_PENDING, $this->outbox->find( $message->uuid() )->state() );
		}

		$this->assert_no_telegram_http();
	}

	public function test_commit_and_response_survive_a_broken_async_kick(): void {
		// Make WP-Cron scheduling itself refuse.
		add_filter( 'schedule_event', '__return_false' );
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'down', 'infra down' ), 10, 3 );

		$conversation = $this->open_conversation();
		$response     = $this->post_visitor_message( $this->enqueuer, $conversation, 'kick is broken but I still commit' );

		$this->assertTrue( $response->get_data()['ok'], 'a broken async kick never fails the visitor response' );
		$record = $this->outbox->find( $response->get_data()['message_uuid'] );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_PENDING, $record->state(), 'the committed outbox row is intact and recoverable by the recurring sweep' );
	}
}
