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

	/**
	 * Confirms the registry's total column count against the real, live
	 * schema — not just that every column has *some* entry, but that the
	 * registry claims exactly the real physical column count for each
	 * table (27 + 11 + 5 = 43), catching a silently stale/incomplete
	 * registry that happened to register the wrong set of names.
	 */
	public function test_registry_column_counts_match_the_real_live_schema_exactly(): void {
		global $wpdb;

		$expected_counts = array(
			UtMigrator::CONVERSATIONS_TABLE         => 'conversations',
			UtMigrator::CONVERSATION_MESSAGES_TABLE => 'conversation_messages',
			UtMigrator::CONVERSATION_NOTES_TABLE    => 'conversation_notes',
		);

		foreach ( $expected_counts as $ut_table_constant => $registry_table ) {
			$table = $wpdb->prefix . $ut_table_constant;

			$real_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$wpdb->dbname,
					$table
				)
			);

			$this->assertSame(
				$real_count,
				count( LegacyFieldMap::registered_columns( $registry_table ) ),
				"{$registry_table} registry entry count does not match the real live {$table} column count."
			);
		}
	}

	/**
	 * Live semantic proof for the fields this closure's own correction
	 * addressed: each is retained in this engine's migration map tables,
	 * so `LegacyFieldMap` must never mark it `exclude` — verified here
	 * against the real Universal Telegram schema, not only offline
	 * (`LegacyFieldMapTest`'s identical check on the registry alone).
	 */
	public function test_fields_retained_in_the_migration_map_are_not_marked_excluded(): void {
		$registry = LegacyFieldMap::registry();

		$preserved_conversation_fields = array(
			'id',
			'conversation_uuid',
			'bot_id',
			'destination_id',
			'topic_creation_state',
			'telegram_topic_id',
			'topic_lifecycle_state',
		);

		foreach ( $preserved_conversation_fields as $column ) {
			$this->assertNotSame(
				LegacyFieldMap::DISPOSITION_EXCLUDE,
				$registry['conversations'][ $column ],
				"conversations.{$column} is retained in legacy_migration_map and must not be 'exclude'."
			);
		}

		$this->assertNotSame( LegacyFieldMap::DISPOSITION_EXCLUDE, $registry['conversation_messages']['id'] );
		$this->assertNotSame( LegacyFieldMap::DISPOSITION_EXCLUDE, $registry['conversation_messages']['message_uuid'] );
		$this->assertNotSame( LegacyFieldMap::DISPOSITION_EXCLUDE, $registry['conversation_notes']['id'] );
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
