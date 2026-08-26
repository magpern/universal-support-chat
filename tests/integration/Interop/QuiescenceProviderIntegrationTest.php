<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop;

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Migration\InProcessLegacyExportClient;
use UniversalSupportChat\Migration\LegacyExportClient;
use UniversalSupportChat\Migration\LegacyMigrationBatchLogRepository;
use UniversalSupportChat\Migration\LegacyMigrationMapEntry;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationRunRepository;
use UniversalSupportChat\Migration\LegacyMigrationValidator;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Migration\PhaseBReconciliationService;
use UniversalSupportChat\Migration\QuiescenceStateProvider;
use UniversalSupportChat\Migration\UniversalTelegramQuiescenceStateProvider;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Tests\Integration\Interop\Support\ExportBatchSideEffectClient;
use UniversalTelegram\Core\Plugin as UtPlugin;
use UniversalTelegram\Core\Security\CredentialVault as UtCredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository as UtDeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate as UtQuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository as UtQuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use WP_UnitTestCase;

if ( ! defined( 'WP_CLI' ) ) {
	// See LegacyExportClientIntegrationTest's identical guard: this suite
	// exercises the real cross-plugin boundary exactly as a real WP-CLI
	// migration process would (Support Chat ADR-0008 §4, Universal
	// Telegram ADR-0039/ADR-0040 §6.1). No other integration test in this
	// repository relies on WP_CLI being undefined.
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.
}

/**
 * Proves `UniversalTelegramQuiescenceStateProvider` and
 * `PhaseBReconciliationService`'s continuous quiescence re-check amendment
 * (`docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md`)
 * against Universal Telegram's real, complete ADR-0040 legacy-chat
 * quiescence implementation — never a fake. Both plugins' real source is
 * loaded in one disposable WordPress install (tests/integration/Interop/bootstrap.php).
 *
 * Real Universal Telegram state transitions are driven the same way
 * `Migration\Cli\QuiescenceCommand` drives them (ADR-0040 §6.1): this test
 * constructs its own `\UniversalTelegram\Migration\QuiescenceGate` from
 * Universal Telegram's own real classes exactly as
 * `QuiescenceGateTest`/`WebhookControllerQuiescenceTest` do on the
 * Universal Telegram side — state lives entirely in Table 1/Table 3
 * (`{$wpdb->prefix}universal_telegram_quiescence_*`), so a separately
 * constructed `QuiescenceGate` object reads/writes the identical rows
 * `Core\Plugin::instance()->quiescence_status()`'s own internally-wired
 * gate does. A real webhook update is buffered via
 * `QuiescenceGate::decide_webhook_disposition()` — the exact call
 * `WebhookController::handle_request()` itself makes (ADR-0040 §3) — and
 * replayed via the identical call sequence `replay-deferred-updates`
 * makes (`decrypt_payload()` → `WebhookController::process_update()` →
 * `mark_replayed()` → `attempt_replaying_to_idle()`), reached through
 * `Core\Plugin::instance()->bot_profile_repository()`/`webhook_controller()`
 * since WP-CLI itself cannot be shelled out to from inside this test
 * process.
 */
final class QuiescenceProviderIntegrationTest extends WP_UnitTestCase {

	private ConversationRepository $sc_conversations;
	private MessageRepository $sc_messages;
	private NoteRepository $sc_notes;
	private LegacyMigrationMapRepository $map;
	private LegacyMigrationMessageMapRepository $message_map;
	private UtQuiescenceGate $ut_gate;
	private UtDeferredUpdateRepository $ut_deferred;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_sc_tables_committed_by_real_transactions();
		$this->truncate_ut_conversation_tables_committed_by_real_transactions();
		$this->reset_ut_quiescence_state_committed_by_real_transactions();

		$health                 = new SchemaHealth();
		$vault                  = new CredentialVault();
		$this->sc_conversations = new ConversationRepository( $health );
		$this->sc_messages      = new MessageRepository( $health, $vault );
		$this->sc_notes         = new NoteRepository( $health, $vault );
		$this->map              = new LegacyMigrationMapRepository( $health );
		$this->message_map      = new LegacyMigrationMessageMapRepository( $health );

		$ut_schema_health  = new UtSchemaHealth();
		$this->ut_deferred = new UtDeferredUpdateRepository( $ut_schema_health, new UtCredentialVault() );
		$this->ut_gate     = new UtQuiescenceGate( $ut_schema_health, $this->ut_deferred, new UtQuiescenceTransitionRepository() );
	}

	public function tear_down(): void {
		$this->truncate_ut_conversation_tables_committed_by_real_transactions();
		$this->reset_ut_quiescence_state_committed_by_real_transactions();
		parent::tear_down();
	}

	/**
	 * See LegacyExportClientIntegrationTest's identical helper: both
	 * phases commit real transactions WP_UnitTestCase's own rollback
	 * fixture cannot undo.
	 */
	private function truncate_sc_tables_committed_by_real_transactions(): void {
		global $wpdb;

		foreach (
			array(
				Migrator::LEGACY_MIGRATION_BATCH_LOG_TABLE,
				Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE,
				Migrator::LEGACY_MIGRATION_MAP_TABLE,
				Migrator::LEGACY_MIGRATION_RUNS_TABLE,
				Migrator::CONVERSATION_NOTES_TABLE,
				Migrator::CONVERSATION_MESSAGES_TABLE,
				Migrator::CONVERSATIONS_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
		}
	}

	/**
	 * Real Universal Telegram conversations/messages/notes/bots created by
	 * `seed_real_ut_conversation()`/`buffer_one_real_deferred_update()`
	 * commit real transactions too, exactly like this repository's own SC
	 * tables — and, unlike a single-test-method suite such as
	 * `LegacyExportClientIntegrationTest`, this file's own multiple test
	 * methods each independently re-run Phase A against
	 * `InProcessLegacyExportClient()`, which reads every Universal
	 * Telegram conversation ever committed in this disposable install,
	 * including by an earlier test method in this same file/process (or by
	 * `LegacyExportClientIntegrationTest`, if it ran first). Explicit
	 * per-test isolation of Universal Telegram's own committed tables is
	 * required so each test's map-row/export-batch-call-count expectations
	 * stay exact.
	 */
	private function truncate_ut_conversation_tables_committed_by_real_transactions(): void {
		global $wpdb;

		foreach (
			array(
				UtMigrator::CONVERSATION_NOTES_TABLE,
				UtMigrator::CONVERSATION_MESSAGES_TABLE,
				UtMigrator::CONVERSATIONS_TABLE,
				UtMigrator::BOTS_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
		}
	}

	/**
	 * `QuiescenceGate` transitions are single-UPDATE CAS statements that
	 * commit their own short transaction (see `QuiescenceGate::try_transition()`
	 * on the Universal Telegram side) — real writes WP_UnitTestCase's own
	 * rollback fixture cannot undo. Every test in this file must start and
	 * end from a known `idle`, empty-backlog, empty-transitions-log state,
	 * mirroring `QuiescenceGateTest`/`WebhookControllerQuiescenceTest`'s own
	 * established setUp/tearDown idiom on the Universal Telegram side.
	 */
	private function reset_ut_quiescence_state_committed_by_real_transactions(): void {
		global $wpdb;

		$state_table = $wpdb->prefix . UtMigrator::QUIESCENCE_STATE_TABLE;
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', entered_draining_at = NULL, entered_quiescent_at = NULL, entered_replaying_at = NULL, updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.

		$deferred_table = $wpdb->prefix . UtMigrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->query( "DELETE FROM {$deferred_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.

		$transitions_table = $wpdb->prefix . UtMigrator::QUIESCENCE_TRANSITIONS_TABLE;
		$wpdb->query( "DELETE FROM {$transitions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
	}

	private function backfill_service( LegacyExportClient $export_client ): PhaseABackfillService {
		return new PhaseABackfillService(
			$export_client,
			$this->sc_conversations,
			$this->sc_messages,
			$this->sc_notes,
			$this->map,
			$this->message_map,
			new LegacyMigrationRunRepository( new SchemaHealth() ),
			new LegacyMigrationBatchLogRepository( new SchemaHealth() )
		);
	}

	private function reconcile_service( LegacyExportClient $export_client, QuiescenceStateProvider $quiescence ): PhaseBReconciliationService {
		$validator = new LegacyMigrationValidator( $this->sc_messages, $this->sc_notes, $this->message_map );

		return new PhaseBReconciliationService(
			$export_client,
			$quiescence,
			$this->sc_messages,
			$this->sc_notes,
			$this->map,
			$this->message_map,
			$validator
		);
	}

	/**
	 * Creates one real Universal Telegram conversation (with an owner and
	 * one message) through Universal Telegram's own real repositories —
	 * the identical seeding pattern LegacyExportClientIntegrationTest
	 * already established.
	 */
	private function seed_real_ut_conversation( string $body ): void {
		$ut               = UtPlugin::instance();
		$ut_conversations = $ut->conversation_repository();
		$ut_messages      = $ut->message_repository();

		$owner           = self::factory()->user->create();
		$ut_conversation = $ut_conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, 'sales', null, $owner );
		$this->assertNotNull( $ut_conversation, 'Real Universal Telegram conversation creation failed — is the sibling checkout really loaded?' );

		$ut_messages->create( $ut_conversation->id(), 'visitor', $body );
	}

	/**
	 * Creates a real Universal Telegram bot profile and durably buffers
	 * one real, encrypted deferred-update row against it via
	 * `QuiescenceGate::decide_webhook_disposition()` — the exact call
	 * `WebhookController::handle_request()` itself makes while not `idle`
	 * (ADR-0040 §3). Returns the bot's id, for later replay.
	 */
	private function buffer_one_real_deferred_update( int $update_id ): int {
		$bot = UtPlugin::instance()->bot_profile_repository()->create( 'Interop Test Bot', 'fake-telegram-token' );
		$this->assertNotNull( $bot, 'Real Universal Telegram bot creation failed.' );

		$disposition = $this->ut_gate->decide_webhook_disposition(
			$bot->id(),
			$update_id,
			'message',
			array(
				'update_id' => $update_id,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$this->assertSame( 'buffered', $disposition, 'Expected the real QuiescenceGate to buffer this update, not process it live.' );

		return $bot->id();
	}

	/**
	 * Replays every currently-unreplayed real deferred-update row through
	 * the identical call sequence `Migration\Cli\QuiescenceCommand::replay_deferred_updates()`
	 * makes on the Universal Telegram side, reached via `Core\Plugin`'s own
	 * public accessors since this test process cannot literally shell out
	 * to `wp universal-telegram quiescence replay-deferred-updates`.
	 */
	private function replay_all_real_deferred_updates(): void {
		$ut      = UtPlugin::instance();
		$context = $this->ut_gate->issue_replay_context();
		$this->assertNotNull( $context, 'Real QuiescenceGate refused to issue a replay context — state is not replaying.' );

		$grouped = $this->ut_deferred->unreplayed_grouped_by_bot();

		foreach ( $grouped as $bot_id => $records ) {
			$bot = $ut->bot_profile_repository()->find( $bot_id );
			$this->assertNotNull( $bot );

			foreach ( $records as $record ) {
				$payload = $this->ut_deferred->decrypt_payload( $record );
				$this->assertNotNull( $payload, 'Failed to decrypt a real deferred-update row during replay.' );

				$ut->webhook_controller()->process_update( $bot, $payload, $context );
				$this->ut_deferred->mark_replayed( $record->id() );
			}
		}

		$attempt = $this->ut_gate->attempt_replaying_to_idle();
		$this->assertTrue( $attempt['success'], 'Real replaying -> idle CAS did not succeed after replaying every known row.' );
	}

	/**
	 * ADR-0040 §6: `idle -> draining -> quiescent`, only reachable when
	 * every real drain condition currently holds (a fresh test WP install
	 * with no queued Action Scheduler work satisfies this immediately).
	 */
	private function enter_real_quiescence(): void {
		$this->assertTrue( $this->ut_gate->enter(), 'Real QuiescenceGate::enter() failed.' );

		$confirm = $this->ut_gate->confirm();
		$this->assertTrue( $confirm['success'], 'Real QuiescenceGate::confirm() failed: ' . wp_json_encode( $confirm['breakdown'] ) );
	}

	/**
	 * 1. Fail-closed baseline: before enter() is ever called, Universal
	 * Telegram's real state is `idle` and the real provider must report
	 * not-quiescent — never a permissive default.
	 */
	public function test_is_quiescent_is_false_before_any_real_ut_state_transition(): void {
		$provider = new UniversalTelegramQuiescenceStateProvider();

		$this->assertSame( \UniversalTelegram\Migration\QuiescenceState::IDLE, $this->ut_gate->state() );
		$this->assertFalse( $provider->is_quiescent() );
		$this->assertNull( $provider->since() );
	}

	/**
	 * 2. Phase B refuses while the real Universal Telegram state machine
	 * is `draining` (a real `enter()` transition, not a fake return
	 * value).
	 */
	public function test_run_refuses_while_real_ut_is_draining(): void {
		$this->assertTrue( $this->ut_gate->enter() );
		$this->assertSame( \UniversalTelegram\Migration\QuiescenceState::DRAINING, $this->ut_gate->state() );

		$provider = new UniversalTelegramQuiescenceStateProvider();
		$this->assertFalse( $provider->is_quiescent() );

		$export_client = new InProcessLegacyExportClient();
		$result        = $this->reconcile_service( $export_client, $provider )->run( false );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( PhaseBReconciliationService::REFUSED_NOT_QUIESCENT, $result['reason'] );
	}

	/**
	 * 3. Phase B refuses while the real Universal Telegram state is
	 * `quiescent` but a real, non-empty deferred-update backlog exists —
	 * `is_quiescent()` is false purely because of the live backlog
	 * computation (ADR-0040 §8), with no explicit state transition away
	 * from `quiescent`.
	 */
	public function test_run_refuses_while_real_ut_is_quiescent_with_a_real_nonempty_backlog(): void {
		$this->enter_real_quiescence();

		$provider = new UniversalTelegramQuiescenceStateProvider();
		$this->assertTrue( $provider->is_quiescent(), 'Sanity check: quiescent with an empty backlog must read true before this test buffers anything.' );

		$this->buffer_one_real_deferred_update( 9001 );

		$this->assertSame( \UniversalTelegram\Migration\QuiescenceState::QUIESCENT, $this->ut_gate->state(), 'Buffering a deferred update must never itself change the state machine state.' );
		$this->assertSame( 1, $this->ut_deferred->backlog_count() );
		$this->assertFalse( $provider->is_quiescent(), 'A real, unreplayed backlog must make the frozen accessor report not-quiescent, per ADR-0040 §8.' );

		$export_client = new InProcessLegacyExportClient();
		$result        = $this->reconcile_service( $export_client, $provider )->run( false );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( PhaseBReconciliationService::REFUSED_NOT_QUIESCENT, $result['reason'] );
	}

	/**
	 * 4. Once that real backlog is fully replayed (the real
	 * `replay-deferred-updates` call sequence) and a fresh real quiescence
	 * window is re-entered, Phase B proceeds and promotes real, Phase-A-backfilled
	 * Support Chat map rows to `migrated` — proving the whole boundary,
	 * not just the gate signal in isolation.
	 */
	public function test_run_succeeds_and_promotes_real_backfilled_rows_once_the_real_backlog_is_replayed(): void {
		$this->seed_real_ut_conversation( 'Is my order shipped yet?' );

		$export_client = new InProcessLegacyExportClient();
		$this->backfill_service( $export_client )->run( false, 100 );

		$map_rows = $this->map->find_backfilled();
		$this->assertCount( 1, $map_rows );
		$row_id = $map_rows[0]->source_conversation_id();

		$this->enter_real_quiescence();
		$this->buffer_one_real_deferred_update( 9101 );

		$provider = new UniversalTelegramQuiescenceStateProvider();
		$this->assertFalse( $provider->is_quiescent() );
		$this->assertSame( 'refused', $this->reconcile_service( $export_client, $provider )->run( false )['status'], 'Sanity check: still refused before this test replays the backlog.' );

		// ADR-0040 §6's real exit sequence: quiescent -> replaying, drain
		// the backlog, then replaying -> idle. There is no pathway in the
		// real state machine that replays a backlog while remaining
		// `quiescent` throughout — a fresh quiescence window must be
		// re-entered afterwards, exactly as a real operator re-running
		// `wp universal-telegram quiescence enter && confirm` would.
		$this->assertTrue( $this->ut_gate->exit() );
		$this->replay_all_real_deferred_updates();
		$this->assertSame( 0, $this->ut_deferred->backlog_count() );
		$this->assertSame( \UniversalTelegram\Migration\QuiescenceState::IDLE, $this->ut_gate->state() );

		$this->enter_real_quiescence();
		$this->assertTrue( $provider->is_quiescent(), 'Backlog reached zero and a fresh quiescence window was entered — the real accessor must now report quiescent.' );

		$result = $this->reconcile_service( $export_client, $provider )->run( false );

		$this->assertSame( 'ran', $result['status'] );
		$this->assertSame( 1, $result['checked'] );
		$this->assertSame( 1, $result['validated'] );

		$map_row = $this->map->find_by_source_id( $row_id );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_MIGRATED, $map_row->status() );
		$this->assertTrue( $map_row->validation_passed() );
	}

	/**
	 * 5. Mid-run quiescence loss against the real stack end to end: a
	 * multi-row Phase B run is already in progress against real,
	 * Phase-A-backfilled rows and the real `UniversalTelegramQuiescenceStateProvider`
	 * (never `FakeQuiescenceStateProvider`), when a real Universal Telegram
	 * webhook update is durably buffered — via `ExportBatchSideEffectClient`,
	 * an export-batch call side effect wrapping the real
	 * `InProcessLegacyExportClient`, landing exactly as the second row's own
	 * reconciliation begins, after the first row has already committed its
	 * promotion. `PhaseBReconciliationService`'s continuous re-check
	 * amendment must catch the now-nonempty real backlog at `reconcile_one()`'s
	 * own pre-promotion re-check and refuse the rest of the run: the first
	 * row stays promoted, the second row is never promoted, and the third
	 * row is never reached — proving the full stack (UT's live backlog
	 * count, the in-process accessor, the provider's defensive call, and
	 * Phase B's re-check) together, not each layer in isolation.
	 */
	public function test_run_detects_a_real_mid_run_deferred_update_and_refuses_further_promotion(): void {
		$this->seed_real_ut_conversation( 'First conversation' );
		$this->seed_real_ut_conversation( 'Second conversation' );
		$this->seed_real_ut_conversation( 'Third conversation' );

		$real_export_client = new InProcessLegacyExportClient();
		$this->backfill_service( $real_export_client )->run( false, 100 );

		$map_rows = $this->map->find_backfilled();
		$this->assertCount( 3, $map_rows, 'Expected three real, Phase-A-backfilled map rows ordered by source_conversation_id ascending.' );
		$row_ids = array_map( static fn( LegacyMigrationMapEntry $row ): int => $row->source_conversation_id(), $map_rows );

		$this->enter_real_quiescence();

		$provider = new UniversalTelegramQuiescenceStateProvider();
		$this->assertTrue( $provider->is_quiescent() );

		// Call 1 is run()'s own preflight export_batch(); call 2 is the
		// first row's own reconcile_one() export_batch(); call 3 is the
		// second row's own reconcile_one() export_batch() — the side
		// effect fires immediately before that third call is delegated,
		// i.e. precisely between the first row's completed promotion and
		// the second row's own reconciliation, durably buffering a real
		// Universal Telegram update via the real QuiescenceGate.
		$side_effect_client = new ExportBatchSideEffectClient(
			$real_export_client,
			3,
			function (): void {
				$this->buffer_one_real_deferred_update( 9201 );
			}
		);

		$result = $this->reconcile_service( $side_effect_client, $provider )->run( false );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( PhaseBReconciliationService::REFUSED_NOT_QUIESCENT, $result['reason'] );
		$this->assertSame( 1, $result['checked'] );
		$this->assertSame( 1, $result['validated'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 3, $side_effect_client->calls(), 'Expected exactly one export_batch() call for the preflight plus one for each of the first two rows.' );

		$this->assertFalse( $provider->is_quiescent(), 'The real, now-nonempty backlog must make the real accessor report not-quiescent after the run stopped.' );
		$this->assertSame( 1, $this->ut_deferred->backlog_count() );

		$this->assertSame( LegacyMigrationMapEntry::STATUS_MIGRATED, $this->map->find_by_source_id( $row_ids[0] )->status(), 'The first row committed its promotion before the real interference landed and must remain promoted.' );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $this->map->find_by_source_id( $row_ids[1] )->status(), 'The second row must not be promoted once its own pre-promotion re-check observed the real backlog.' );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $this->map->find_by_source_id( $row_ids[2] )->status(), 'The third row must never be reached once the run refused.' );
	}
}
