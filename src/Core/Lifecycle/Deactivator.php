<?php
/**
 * Plugin deactivation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\TelegramDispatch\DispatchWorker;

/**
 * Deactivation clears scheduled retention; uninstall is the only place
 * data or capabilities are removed.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( RetentionCleanupHandler::CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, RetentionCleanupHandler::CRON_HOOK );
		}

		DispatchWorker::unschedule();
	}
}
