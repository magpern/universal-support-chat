<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI\Support;

use UniversalSupportChat\Persistence\Migrator;

/**
 * Some AI paths (the visitor-request atomic write) commit their transaction,
 * so their rows escape the `WP_UnitTestCase` per-test rollback. Tests that
 * exercise those paths truncate the relevant tables on both ends — the same
 * approach as `DispatchWiringTest`.
 */
trait TruncatesAiTables {

	/**
	 * Truncates the conversation + AI tables.
	 */
	protected function truncate_ai_tables(): void {
		global $wpdb;

		foreach (
			array(
				Migrator::AI_TURNS_TABLE,
				Migrator::AI_KNOWLEDGE_SOURCES_TABLE,
				Migrator::TELEGRAM_DISPATCH_TABLE,
				Migrator::CONVERSATION_NOTES_TABLE,
				Migrator::CONVERSATION_MESSAGES_TABLE,
				Migrator::CONVERSATIONS_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}
	}
}
