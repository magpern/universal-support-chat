<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use UniversalSupportChat\TelegramDispatch\DispatchWorker;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;
use UniversalSupportChat\Tests\Integration\TelegramDispatch\Support\RecordingAdapterContractClient;
use WP_UnitTestCase;

/**
 * ADR-0014 Amendment 1 — `DispatchWorker::request_immediate_run()` is the
 * visitor / Hub request's only expedite step. Exercised in the **normal
 * deployed state**: the recurring `HOOK` event is already scheduled, so the
 * distinct `IMMEDIATE_HOOK` is what carries the expedited run.
 */
final class DispatchWorkerTest extends WP_UnitTestCase {

	private DispatchOutboxRepository $outbox;
	private MessageRepository $messages;
	private RecordingAdapterContractClient $client;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		global $wpdb;
		foreach ( array( Migrator::TELEGRAM_DISPATCH_TABLE, Migrator::CONVERSATION_MESSAGES_TABLE ) as $t ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}

		wp_clear_scheduled_hook( DispatchWorker::HOOK );
		wp_clear_scheduled_hook( DispatchWorker::IMMEDIATE_HOOK );

		// The normal deployed state: the recurring safety-net sweep is
		// already scheduled on HOOK.
		wp_schedule_event( time() + 60, DispatchWorker::SCHEDULE, DispatchWorker::HOOK );
		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::HOOK ) );

		$health         = new SchemaHealth();
		$this->outbox   = new DispatchOutboxRepository( $health );
		$this->messages = new MessageRepository( $health, new CredentialVault() );
		$this->client   = new RecordingAdapterContractClient();

		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'schedule_event' );
		wp_clear_scheduled_hook( DispatchWorker::HOOK );
		wp_clear_scheduled_hook( DispatchWorker::IMMEDIATE_HOOK );
		parent::tear_down();
	}

	private function worker(): DispatchWorker {
		return new DispatchWorker(
			new TelegramDispatchService(
				new Settings(),
				$this->outbox,
				$this->messages,
				$this->client,
				new AuditLogger( new SchemaHealth(), new Redactor() )
			)
		);
	}

	public function test_it_schedules_exactly_one_due_immediate_event_even_though_the_recurring_hook_exists(): void {
		$this->assertFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );

		DispatchWorker::request_immediate_run();

		$due = wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK );
		$this->assertNotFalse( $due, 'an immediate event was scheduled despite the recurring hook already existing' );
		$this->assertLessThanOrEqual( time(), (int) $due, 'the immediate event is due now' );

		// The recurring event is untouched, still at its future interval.
		$this->assertGreaterThan( time(), (int) wp_next_scheduled( DispatchWorker::HOOK ) );
	}

	public function test_repeated_kicks_do_not_stack_immediate_events(): void {
		DispatchWorker::request_immediate_run();
		$first = wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK );

		DispatchWorker::request_immediate_run();
		DispatchWorker::request_immediate_run();

		$this->assertSame( $first, wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );

		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $events ) {
			if ( isset( $events[ DispatchWorker::IMMEDIATE_HOOK ] ) ) {
				$count += count( $events[ DispatchWorker::IMMEDIATE_HOOK ] );
			}
		}
		$this->assertSame( 1, $count, 'at most one pending immediate event' );
	}

	public function test_the_immediate_hook_invokes_the_same_dispatch_worker(): void {
		$worker = $this->worker();
		$worker->register();

		$message = $this->messages->create( 4242, ConversationMessage::DIRECTION_VISITOR, 'expedite me', 'stored', null );
		$this->assertNotNull( $message );
		$this->assertTrue( $this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' ) );

		// The IMMEDIATE_HOOK is bound to the same run() method as the
		// recurring HOOK.
		$this->assertNotFalse( has_action( DispatchWorker::IMMEDIATE_HOOK, array( $worker, 'run' ) ) );
		$this->assertSame(
			has_action( DispatchWorker::HOOK, array( $worker, 'run' ) ),
			has_action( DispatchWorker::IMMEDIATE_HOOK, array( $worker, 'run' ) ),
			'both hooks route to the identical callback'
		);

		// The callback both hooks share is the worker's run() — invoke it as
		// WP-Cron would when the immediate event fires.
		$worker->run();

		$this->assertCount( 1, $this->client->calls_for( 'deliver_message' ) );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_it_never_throws_when_the_loopback_errors(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'down', 'loopback down' ), 10, 3 );

		DispatchWorker::request_immediate_run();

		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );
	}

	public function test_it_never_throws_when_the_loopback_raises(): void {
		add_filter(
			'pre_http_request',
			static function (): void {
				throw new \RuntimeException( 'catastrophic loopback failure' );
			},
			10,
			3
		);

		DispatchWorker::request_immediate_run();

		$this->addToAssertionCount( 1 );
	}

	public function test_it_never_throws_and_leaves_the_row_recoverable_when_scheduling_is_refused(): void {
		add_filter( 'pre_schedule_event', '__return_false' );

		$message = $this->messages->create( 4242, ConversationMessage::DIRECTION_VISITOR, 'still recoverable', 'stored', null );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		DispatchWorker::request_immediate_run();

		// No immediate event (scheduling refused), but the recurring sweep
		// still owns the row and it is untouched / recoverable.
		$this->assertSame( DispatchRecord::STATE_PENDING, $this->outbox->find( $message->uuid() )->state() );
		remove_filter( 'pre_schedule_event', '__return_false' );
		$this->assertCount( 1, $this->outbox->claim_due( 10 ), 'the recurring worker can still claim it' );
	}

	public function test_unschedule_removes_both_the_recurring_and_the_immediate_hook(): void {
		DispatchWorker::request_immediate_run();
		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );

		DispatchWorker::unschedule();

		$this->assertFalse( wp_next_scheduled( DispatchWorker::HOOK ) );
		$this->assertFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );
	}

	public function test_deactivation_clears_both_hooks(): void {
		DispatchWorker::request_immediate_run();
		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );

		( new \UniversalSupportChat\Core\Lifecycle\Deactivator() )->deactivate();

		$this->assertFalse( wp_next_scheduled( DispatchWorker::HOOK ) );
		$this->assertFalse( wp_next_scheduled( DispatchWorker::IMMEDIATE_HOOK ) );
	}
}
