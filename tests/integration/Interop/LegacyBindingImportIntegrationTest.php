<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop;

use UniversalSupportChat\Migration\InProcessLegacyBindingImportClient;
use UniversalSupportChat\Migration\LegacyBindingImportService;
use UniversalSupportChat\Migration\LegacyBindingOutcome;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\QuiescenceStateProvider;
use UniversalSupportChat\Migration\UniversalTelegramQuiescenceStateProvider;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalTelegram\Core\Plugin as UtPlugin;
use UniversalTelegram\Core\Security\CredentialVault as UtCredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository as UtDeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate as UtQuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository as UtQuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBinding as UtChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository as UtChannelBindingRepository;
use WP_UnitTestCase;

if ( ! defined( 'WP_CLI' ) ) {
	// See LegacyExportClientIntegrationTest's identical guard.
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.
}

/**
 * Proves `InProcessLegacyBindingImportClient` and `LegacyBindingImportService`
 * against Universal Telegram's real, merged `LegacyBindingImportServiceV1`
 * (Support Chat ADR-0009, Universal Telegram ADR-0041) — not a fake. Both
 * plugins' real source is loaded in one disposable WordPress install
 * (tests/integration/Interop/bootstrap.php). Real Universal Telegram
 * quiescence state is driven the identical way
 * `QuiescenceProviderIntegrationTest` already does, via a directly
 * constructed real `\UniversalTelegram\Migration\QuiescenceGate`.
 */
final class LegacyBindingImportIntegrationTest extends WP_UnitTestCase {

	private LegacyMigrationMapRepository $map;
	private QuiescenceStateProvider $quiescence;
	private LegacyBindingImportService $service;
	private UtQuiescenceGate $ut_gate;
	private UtChannelBindingRepository $ut_bindings;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_tables_committed_by_real_transactions();
		$this->reset_ut_quiescence_state_committed_by_real_transactions();

		$health           = new SchemaHealth();
		$this->map        = new LegacyMigrationMapRepository( $health );
		$this->quiescence = new UniversalTelegramQuiescenceStateProvider();
		$this->service    = new LegacyBindingImportService( $this->map, new InProcessLegacyBindingImportClient(), $this->quiescence );

		$ut_schema_health  = new UtSchemaHealth();
		$this->ut_gate     = new UtQuiescenceGate( $ut_schema_health, new UtDeferredUpdateRepository( $ut_schema_health, new UtCredentialVault() ), new UtQuiescenceTransitionRepository() );
		$this->ut_bindings = new UtChannelBindingRepository( $ut_schema_health );
	}

	public function tear_down(): void {
		$this->truncate_tables_committed_by_real_transactions();
		$this->reset_ut_quiescence_state_committed_by_real_transactions();
		parent::tear_down();
	}

	private function truncate_tables_committed_by_real_transactions(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test cleanup only.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test cleanup only.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . UtMigrator::CONVERSATIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test cleanup only.
	}

	/**
	 * See QuiescenceProviderIntegrationTest's identical helper.
	 */
	private function reset_ut_quiescence_state_committed_by_real_transactions(): void {
		global $wpdb;

		$state_table = $wpdb->prefix . UtMigrator::QUIESCENCE_STATE_TABLE;
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$deferred_table = $wpdb->prefix . UtMigrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->query( "DELETE FROM {$deferred_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Seeds a real Universal Telegram conversation with a real created
	 * topic, then a real Support Chat migration-map row already at
	 * `migrated`, pointing at it — this test's own stand-in for a
	 * completed WP3-4 migration, using each repository's own real
	 * persistence directly rather than re-running the full backfill/
	 * reconcile pipeline (already proven end-to-end by
	 * `LegacyExportClientIntegrationTest`/`QuiescenceProviderIntegrationTest`).
	 */
	private function seed_real_bindable_candidate( int $bot_id, int $destination_id, int $telegram_topic_id ): int {
		$ut   = UtPlugin::instance();
		$conv = $ut->conversation_repository()->create( wp_generate_uuid4(), 'hashed-secret', $bot_id, null );
		$this->assertNotNull( $conv, 'Failed to seed a real Universal Telegram conversation — is the sibling checkout really loaded?' );

		$ut->conversation_repository()->mark_topic_created( $conv->id(), $telegram_topic_id, $destination_id );

		$entry = $this->map->create_pending( $conv->id(), $conv->conversation_uuid(), $bot_id, $destination_id, $telegram_topic_id, 'created', 'active' );
		$this->map->mark_backfilled( $entry->id(), 900000 + $conv->id(), 'sc-target-uuid-' . $conv->id(), 1, 1, 0, 0 );
		$this->map->mark_migrated( $entry->id(), true, 1, 0 );

		return $conv->id();
	}

	public function test_real_binding_is_created_prepared_never_active(): void {
		$this->ut_gate->enter();
		$this->ut_gate->confirm();
		$this->assertTrue( $this->quiescence->is_quiescent(), 'Support Chat must observe the real Universal Telegram quiescent state before this test\'s own assertions are meaningful.' );

		$source_id = $this->seed_real_bindable_candidate( 5, 50, 500 );

		$result = $this->service->run( false, 100 );

		$this->assertSame( 1, $result['created'] );

		$entry = $this->map->find_by_source_id( $source_id );
		$this->assertSame( 'created', $entry->binding_status() );
		$this->assertNotNull( $entry->binding_uuid() );

		$binding = $this->ut_bindings->find_by_uuid( $entry->binding_uuid() );
		$this->assertNotNull( $binding, 'The recorded binding_uuid must resolve to a real Universal Telegram binding row.' );
		$this->assertSame( UtChannelBinding::STATUS_PREPARED, $binding->status() );
		$this->assertNotSame( UtChannelBinding::STATUS_ACTIVE, $binding->status() );
	}

	public function test_real_rerun_is_idempotent_no_second_binding_row(): void {
		$this->ut_gate->enter();
		$this->ut_gate->confirm();

		$this->seed_real_bindable_candidate( 5, 50, 500 );
		$this->service->run( false, 100 );

		$second = $this->service->run( false, 100 );

		global $wpdb;
		$table = $wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 1, $count, 'A rerun must never create a second real binding row.' );
	}

	/**
	 * The core correctness property Support Chat ADR-0009 §4 / this
	 * repository's ADR-0041 §2 add: a real, pre-existing `active` binding
	 * must never be silently treated as this boundary's own prior success.
	 */
	public function test_real_matching_active_binding_is_a_conflict_not_idempotent_success(): void {
		$this->ut_gate->enter();
		$this->ut_gate->confirm();

		$source_id = $this->seed_real_bindable_candidate( 5, 50, 500 );
		$entry     = $this->map->find_by_source_id( $source_id );

		$existing = $this->ut_bindings->create(
			wp_generate_uuid4(),
			(string) $entry->target_conversation_uuid(),
			'interop-ensure-key-active',
			5,
			50,
			500,
			UtChannelBinding::STATUS_ACTIVE
		);
		$this->assertNotNull( $existing );

		$result = $this->service->run( false, 100 );

		$this->assertSame( 1, $result['conflict'] );
		$entry = $this->map->find_by_source_id( $source_id );
		$this->assertSame( 'conflict', $entry->binding_status() );
		$this->assertSame( LegacyBindingOutcome::CONFLICT_EXISTING_ACTIVE, $entry->binding_error_reason() );

		global $wpdb;
		$table = $wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 1, $count, 'Must never write a second binding row alongside the real existing active one.' );
	}

	public function test_real_not_quiescent_refuses_and_writes_no_binding(): void {
		// Gate left at its real default 'idle' state.
		$source_id = $this->seed_real_bindable_candidate( 5, 50, 500 );

		$result = $this->service->run( false, 100 );

		$this->assertTrue( $result['refused'] );

		global $wpdb;
		$table = $wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 0, $count );
	}

	public function test_real_dry_run_writes_no_binding_on_either_side(): void {
		$this->ut_gate->enter();
		$this->ut_gate->confirm();

		$source_id = $this->seed_real_bindable_candidate( 5, 50, 500 );

		$result = $this->service->run( true, 100 );

		$this->assertSame( 1, $result['created'] );
		$this->assertNull( $this->map->find_by_source_id( $source_id )->binding_status() );

		global $wpdb;
		$table = $wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 0, $count, 'Dry-run must never commit a real binding write.' );
	}
}
