<?php
/**
 * Plugin uninstall.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

use UniversalSupportChat\ChannelContract\Auth\NonceCleanupHandler;
use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\TelegramDispatch\DispatchWorker;

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

		$nonce_timestamp = wp_next_scheduled( NonceCleanupHandler::CRON_HOOK );
		if ( false !== $nonce_timestamp ) {
			wp_unschedule_event( $nonce_timestamp, NonceCleanupHandler::CRON_HOOK );
		}

		DispatchWorker::unschedule();

		$settings = ( new Settings() )->get();

		if ( true !== $settings['remove_data_on_uninstall'] ) {
			return;
		}

		$this->drop_table( Migrator::TELEGRAM_DISPATCH_TABLE );
		$this->drop_table( Migrator::AUDIT_LOG_TABLE );
		$this->drop_table( Migrator::CONVERSATION_NOTES_TABLE );
		$this->drop_table( Migrator::CONVERSATION_MESSAGES_TABLE );
		$this->drop_table( Migrator::CONVERSATIONS_TABLE );
		$this->drop_table( Migrator::CHANNEL_STATUS_TABLE );
		$this->drop_table( Migrator::CONTRACT_NONCES_TABLE );
		$this->drop_table( Migrator::CHANNEL_PEERS_TABLE );

		// Retired SC-M03 legacy-migration / final-cutover tables (ADR-0013).
		// The retirement itself never drops these — an already-upgraded site
		// keeps them as historical inert data. But when the operator has
		// explicitly opted into full data removal on uninstall, no
		// plugin-owned table should be left orphaned, so they are cleaned
		// here too. The name-only manifest constants live on in Migrator for
		// exactly this compatibility.
		$this->drop_table( Migrator::LEGACY_HANDOFF_MAP_TABLE );
		$this->drop_table( Migrator::LEGACY_MIGRATION_BATCH_LOG_TABLE );
		$this->drop_table( Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE );
		$this->drop_table( Migrator::LEGACY_MIGRATION_MAP_TABLE );
		$this->drop_table( Migrator::LEGACY_MIGRATION_RUNS_TABLE );
		delete_option( Settings::OPTION_NAME );
		delete_option( 'universal_support_chat_db_version' );
		delete_option( 'universal_support_chat_migration_lock' );
		delete_option( 'universal_support_chat_contract_own_key' );
		delete_option( 'universal_support_chat_contract_own_key_secret' );
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
