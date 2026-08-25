<?php
/**
 * Scheduled nonce replay store cleanup (ADR-0007 §3).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

/**
 * Purges nonce records outside the retention window. Mirrors
 * Conversations\RetentionCleanupHandler's WP-Cron pattern.
 */
final class NonceCleanupHandler {

	public const CRON_HOOK = 'universal_support_chat_contract_nonce_cleanup';

	/**
	 * Nonce replay store.
	 *
	 * @var NonceReplayRepository
	 */
	private NonceReplayRepository $nonces;

	/**
	 * Constructor.
	 *
	 * @param NonceReplayRepository $nonces Nonce replay store.
	 */
	public function __construct( NonceReplayRepository $nonces ) {
		$this->nonces = $nonces;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Ensures the hourly WP-Cron event exists.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * WP-Cron entry point.
	 */
	public function run_scheduled(): void {
		$this->nonces->purge_expired();
	}
}
