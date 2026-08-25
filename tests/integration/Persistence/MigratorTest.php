<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Persistence;

use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

final class MigratorTest extends WP_UnitTestCase {

	public function test_maybe_migrate_creates_audit_table_and_is_idempotent(): void {
		global $wpdb;

		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );

		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 1, (int) get_option( 'universal_support_chat_db_version', 0 ) );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
		$this->assertContains( 'privacy_classification', $columns );

		$migrator->maybe_migrate();
		$this->assertSame( 1, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}
}
