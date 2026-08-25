<?php
/**
 * Plugin uninstall.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

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

		$settings = ( new Settings() )->get();

		if ( true !== $settings['remove_data_on_uninstall'] ) {
			return;
		}

		$this->drop_audit_table();
		delete_option( Settings::OPTION_NAME );
		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );
	}

	/**
	 * Drops the plugin audit-log table.
	 */
	private function drop_audit_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
