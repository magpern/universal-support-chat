<?php
/**
 * WP-Cron registration for the Support Chat -> Telegram dispatch worker.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

/**
 * Keeps a recurring safety-net sweep scheduled and runs the dispatch
 * service on both the recurring hook and the immediate one-off kicks
 * scheduled by `DispatchEnqueuer`. Follows the same WP-Cron pattern as
 * `RetentionCleanupHandler` — no Action Scheduler, no Universal Telegram
 * dependency at registration time.
 */
final class DispatchWorker {

	/**
	 * The recurring safety-net sweep hook (every {@see INTERVAL_SECONDS}).
	 */
	public const HOOK = 'universal_support_chat_telegram_dispatch_run';

	/**
	 * A separate one-off hook the visitor / Hub request schedules for *now*
	 * (ADR-0014 Amendment 1). It must be distinct from {@see HOOK}: the
	 * recurring event is normally already scheduled on `HOOK`, so a
	 * `wp_next_scheduled( self::HOOK )` guard would never let an expedited
	 * one-off be created. Both hooks call {@see run()}.
	 */
	public const IMMEDIATE_HOOK = 'universal_support_chat_telegram_dispatch_immediate';

	public const SCHEDULE = 'universal_support_chat_telegram_dispatch_interval';

	private const INTERVAL_SECONDS = 60;

	private const BATCH_LIMIT = 25;

	/**
	 * Dispatch service.
	 *
	 * @var TelegramDispatchService
	 */
	private TelegramDispatchService $service;

	/**
	 * Constructor.
	 *
	 * @param TelegramDispatchService $service Dispatch service.
	 */
	public function __construct( TelegramDispatchService $service ) {
		$this->service = $service;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- fixed 60s worker interval.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::IMMEDIATE_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Adds the 60-second worker schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Every minute (Support Chat Telegram dispatch)', 'universal-support-chat' ),
		);

		return $schedules;
	}

	/**
	 * Ensures the recurring safety-net sweep is scheduled.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * WP-Cron / kick entry point.
	 */
	public function run(): void {
		$this->service->dispatch_due( self::BATCH_LIMIT );
	}

	/**
	 * ADR-0014 Amendment 1: the visitor / Hub request's only expedite step.
	 * Schedules the **immediate** one-off hook for now (distinct from the
	 * recurring hook, which is normally already scheduled — see
	 * {@see IMMEDIATE_HOOK}), then fires WordPress core's own non-blocking
	 * cron loopback (`spawn_cron()` — a `wp_remote_post` with
	 * `blocking => false`, `timeout => 0.01`) so the dispatch worker runs in
	 * a **separate** request within about a second, even on a
	 * `DISABLE_WP_CRON` site, without the originating request waiting for it.
	 *
	 * At most one immediate event is ever pending: the `wp_next_scheduled`
	 * guard plus `wp_schedule_single_event`'s own 10-minute same-hook+args
	 * de-duplication collapse repeated kicks under load.
	 *
	 * **Non-throwing across its entire boundary.** A failure of the
	 * scheduling or loopback infrastructure must never touch the caller's
	 * already-committed message / outbox row or its response — the recurring
	 * 60 s safety-net sweep still delivers the row.
	 */
	public static function request_immediate_run(): void {
		try {
			if ( ! wp_next_scheduled( self::IMMEDIATE_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMMEDIATE_HOOK );
			}

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} catch ( \Throwable $exception ) {
			// Swallowed by design — see the docblock.
			unset( $exception );
		}
	}

	/**
	 * Unschedules both the recurring sweep and any pending immediate event
	 * (deactivation / uninstall).
	 */
	public static function unschedule(): void {
		foreach ( array( self::HOOK, self::IMMEDIATE_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}

			wp_clear_scheduled_hook( $hook );
		}
	}
}
