<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

final class DispatchSchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
	}

	public function test_dispatch_table_has_no_content_bearing_column(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		$this->assertContains( 'message_uuid', $columns );
		$this->assertContains( 'conversation_uuid', $columns );
		$this->assertContains( 'state', $columns );
		$this->assertContains( 'next_attempt_at', $columns );

		foreach ( array( 'body', 'body_ciphertext', 'plaintext', 'content_hash', 'digest', 'text' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $columns, "dispatch table must not carry `{$forbidden}`" );
		}
	}

	public function test_message_uuid_is_unique(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;
		$keys  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'message_uuid'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertNotEmpty( $keys );
		$this->assertSame( '0', (string) $keys[0]['Non_unique'] );
	}
}
