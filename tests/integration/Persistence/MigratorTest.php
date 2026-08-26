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

		$this->assertSame( 8, (int) get_option( 'universal_support_chat_db_version', 0 ) );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
		$this->assertContains( 'privacy_classification', $columns );

		$migrator->maybe_migrate();
		$this->assertSame( 8, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}

	public function test_upgrade_from_sc_m00_db_version_1_to_4_is_idempotent(): void {
		global $wpdb;

		delete_option( 'universal_support_chat_migration_lock' );

		$conversations = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$messages      = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$messages}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$conversations}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Simulate SC-M00 completed schema (audit only).
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		update_option( 'universal_support_chat_db_version', 1 );
		$wpdb->query( "DROP TABLE IF EXISTS {$messages}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$conversations}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();
		$this->assertSame( 8, (int) get_option( 'universal_support_chat_db_version', 0 ) );

		$conv_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$conversations
			)
		);
		$this->assertContains( 'owner_user_id', $conv_cols );
		$this->assertNotContains( 'telegram_topic_id', $conv_cols );

		$msg_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$messages
			)
		);
		$this->assertContains( 'body_ciphertext', $msg_cols );
		$this->assertNotContains( 'telegram_message_id', $msg_cols );

		$notes     = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;
		$note_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$notes
			)
		);
		$this->assertContains( 'body_ciphertext', $note_cols );

		$migrator->maybe_migrate();
		$this->assertSame( 8, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}
}
