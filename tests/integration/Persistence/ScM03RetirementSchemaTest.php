<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Persistence;

use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * ADR-0013 — the retired SC-M03 legacy-migration / final-cutover steps
 * (9-11) are inert: a fresh install creates none of their tables, and an
 * install that already has them upgrades without touching them.
 */
final class ScM03RetirementSchemaTest extends WP_UnitTestCase {

	/**
	 * @var array<int, string>
	 */
	private const RETIRED_TABLES = array(
		Migrator::LEGACY_MIGRATION_RUNS_TABLE,
		Migrator::LEGACY_MIGRATION_MAP_TABLE,
		Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE,
		Migrator::LEGACY_MIGRATION_BATCH_LOG_TABLE,
		Migrator::LEGACY_HANDOFF_MAP_TABLE,
	);

	public function set_up(): void {
		parent::set_up();
		$this->drop_retired_tables();
	}

	public function tear_down(): void {
		$this->drop_retired_tables();
		parent::tear_down();
	}

	public function test_fresh_migration_reaches_12_and_creates_no_retired_sc_m03_table(): void {
		global $wpdb;

		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 12, (int) get_option( 'universal_support_chat_db_version', 0 ) );

		foreach ( self::RETIRED_TABLES as $unprefixed ) {
			$table = $wpdb->prefix . $unprefixed;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertNull( $found, $table . ' must not be created on a fresh install' );
		}

		// The ADR-0012 dispatch table (step 12) still installs.
		$dispatch = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;
		$this->assertSame( $dispatch, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dispatch ) ) );
	}

	public function test_upgraded_install_keeps_pre_existing_legacy_tables_and_data_untouched(): void {
		global $wpdb;

		delete_option( 'universal_support_chat_migration_lock' );

		// Simulate a site that completed the old steps 1-9 before retirement:
		// migrate fully, then hand-build a legacy table with a data row and
		// wind db_version back to just before the retired steps.
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$legacy = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;
		$wpdb->query( "CREATE TABLE {$legacy} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source_conversation_uuid CHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			$legacy,
			array(
				'source_conversation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				'status'                   => 'migrated',
			)
		);

		update_option( 'universal_support_chat_db_version', 8 );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 12, (int) get_option( 'universal_support_chat_db_version', 0 ) );

		// The historical table and its row are still present, byte-for-byte.
		$this->assertSame( $legacy, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) );
		$row = $wpdb->get_row( "SELECT source_conversation_uuid, status FROM {$legacy}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$this->assertSame(
			array(
				'source_conversation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				'status'                   => 'migrated',
			),
			$row
		);

		// Re-running is still a no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 12, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}

	private function drop_retired_tables(): void {
		global $wpdb;

		foreach ( self::RETIRED_TABLES as $unprefixed ) {
			$table = $wpdb->prefix . $unprefixed;
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}
	}
}
