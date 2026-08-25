<?php
/**
 * Plugin uninstall.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\Migrator;

/**
 * Always revokes capabilities. Optionally removes plugin data when
 * remove_data_on_uninstall is enabled.
 */
final class Uninstaller {

	/**
	 * Runs the uninstall routine.
	 */
	public function run(): void {
		( new CapabilityRegistrar() )->revoke_from_all_roles();

		$timestamp = wp_next_scheduled( RetentionCleanupHandler::CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, RetentionCleanupHandler::CRON_HOOK );
		}

		$settings = ( new Settings() )->get();

		if ( true !== $settings['remove_data_on_uninstall'] ) {
			return;
		}

		$this->drop_table( Migrator::AUDIT_LOG_TABLE );
		$this->drop_table( Migrator::CONVERSATION_NOTES_TABLE );
		$this->drop_table( Migrator::CONVERSATION_MESSAGES_TABLE );
		$this->drop_table( Migrator::CONVERSATIONS_TABLE );
		delete_option( Settings::OPTION_NAME );
		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );
	}

	/**
	 * Drops one plugin table by unprefixed name.
	 *
	 * @param string $unprefixed Table name without `$wpdb->prefix`.
	 */
	private function drop_table( string $unprefixed ): void {
		global $wpdb;

		$table = $wpdb->prefix . $unprefixed;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
