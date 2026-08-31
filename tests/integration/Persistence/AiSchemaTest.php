<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Persistence;

use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Migration step 13 (ADR-0018, SC-M07) — the table-specific content-column
 * boundary: `ai_turns` metadata-only, `knowledge_sources` encrypted-content-only.
 */
final class AiSchemaTest extends WP_UnitTestCase {

	/**
	 * @return array<int, string>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
	}

	public function test_step_13_creates_both_ai_tables_and_advances_db_version(): void {
		global $wpdb;

		$turns   = $wpdb->prefix . Migrator::AI_TURNS_TABLE;
		$sources = $wpdb->prefix . Migrator::AI_KNOWLEDGE_SOURCES_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$turns}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$sources}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 13, (int) get_option( 'universal_support_chat_db_version', 0 ) );
		$this->assertNotEmpty( $this->columns( $turns ) );
		$this->assertNotEmpty( $this->columns( $sources ) );
	}

	public function test_ai_turns_carries_no_content_column(): void {
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$columns = array_map( 'strtolower', $this->columns( $wpdb->prefix . Migrator::AI_TURNS_TABLE ) );

		foreach ( array( 'body', 'prompt', 'response', 'message_text', 'content', 'text', 'plaintext', 'ciphertext', 'transcript' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $columns, "ai_turns must not have a `{$forbidden}` column" );
		}

		// Metadata references are fine.
		$this->assertContains( 'visitor_message_id', $columns );
		$this->assertContains( 'source_ids', $columns );
		$this->assertContains( 'source_checksums', $columns );
	}

	public function test_knowledge_sources_requires_ciphertext_and_forbids_plaintext_and_pii(): void {
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$columns = array_map( 'strtolower', $this->columns( $wpdb->prefix . Migrator::AI_KNOWLEDGE_SOURCES_TABLE ) );

		$this->assertContains( 'indexed_text_ciphertext', $columns );

		foreach ( array( 'indexed_text', 'body', 'raw_content', 'plaintext', 'content', 'snippet_text' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $columns, "knowledge_sources must not have a plaintext `{$forbidden}` column" );
		}

		foreach ( array( 'owner_user_id', 'user_email', 'visitor_email', 'conversation_id', 'message_uuid' ) as $pii ) {
			$this->assertNotContains( $pii, $columns, "knowledge_sources must not have a `{$pii}` column" );
		}
	}

	public function test_step_13_is_idempotent(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( 13, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}
}
