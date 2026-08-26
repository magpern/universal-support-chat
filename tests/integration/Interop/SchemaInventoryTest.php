<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop;

use UniversalSupportChat\Migration\LegacyFieldMap;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use WP_UnitTestCase;

/**
 * Introspects Universal Telegram's own real, live, merged schema (this
 * plugin is loaded for real by tests/integration/Interop/bootstrap.php,
 * not a fixture) and fails if any physical column is missing a registered
 * disposition in `LegacyFieldMap::registry()` — the CI-enforced drift
 * guard sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.1 requires.
 */
final class SchemaInventoryTest extends WP_UnitTestCase {

	public function test_every_real_conversations_column_has_a_registered_disposition(): void {
		$this->assert_every_column_registered( UtMigrator::CONVERSATIONS_TABLE, 'conversations' );
	}

	public function test_every_real_conversation_messages_column_has_a_registered_disposition(): void {
		$this->assert_every_column_registered( UtMigrator::CONVERSATION_MESSAGES_TABLE, 'conversation_messages' );
	}

	public function test_every_real_conversation_notes_column_has_a_registered_disposition(): void {
		$this->assert_every_column_registered( UtMigrator::CONVERSATION_NOTES_TABLE, 'conversation_notes' );
	}

	private function assert_every_column_registered( string $ut_table_constant, string $registry_table ): void {
		global $wpdb;

		$table = $wpdb->prefix . $ut_table_constant;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		$this->assertNotEmpty( $columns, "Universal Telegram's {$table} table was not found — is it really loaded and migrated?" );

		$registered = LegacyFieldMap::registered_columns( $registry_table );
		$missing    = array_diff( $columns, $registered );

		$this->assertSame(
			array(),
			$missing,
			'Universal Telegram schema column(s) missing a LegacyFieldMap disposition: ' . implode( ', ', $missing )
		);
	}
}
