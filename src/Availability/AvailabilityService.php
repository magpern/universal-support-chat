<?php
/**
 * WordPress-facing availability service.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Privacy\Classification;

// AvailabilityResolver, AvailabilityState, AvailabilityOverride, WeeklySchedule,
// ExceptionSet, InvalidScheduleException are all in this same namespace.

/**
 * The thin seam between WordPress and the pure {@see AvailabilityResolver}:
 * loads the schedule / exceptions from {@see Settings}, the manual override
 * from its own autoloaded option, obtains "now" in the site timezone, reaps
 * an expired override, and answers the questions the widget, the REST
 * controller, the Hub, and Diagnostics ask.
 *
 * Support Chat is the sole availability authority (ADR-0017 §1); nothing
 * here touches Universal Telegram or any adapter.
 */
final class AvailabilityService {

	/**
	 * Autoloaded option holding the manual override (ADR-0017 §6). Absent
	 * until an operator sets it; absence means `Automatic`.
	 */
	public const OVERRIDE_OPTION = 'universal_support_chat_availability_override';

	public const MODE_AUTOMATIC = 'automatic';

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Settings owner (schedule / exceptions / copy).
	 * @param AvailabilityResolver $resolver Pure resolver.
	 * @param AuditLogger|null     $audit    Optional audit logger (for override reaping).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly AvailabilityResolver $resolver,
		private readonly ?AuditLogger $audit = null
	) {}

	/**
	 * The resolved visitor-facing state right now.
	 */
	public function resolve_state(): AvailabilityState {
		[ $schedule, $exceptions ] = $this->load_config();

		return $this->resolver->resolve( $schedule, $exceptions, $this->current_override(), $this->now() );
	}

	/**
	 * Whether the team is currently presented as unavailable (the trigger
	 * for the offline-ticket path, ADR-0017 §7).
	 */
	public function is_unavailable(): bool {
		return AvailabilityState::UNAVAILABLE === $this->resolve_state();
	}

	/**
	 * The operator-authored offline message (plain text, ADR-0016 / ADR-0017).
	 */
	public function offline_message(): string {
		$value = $this->settings->get()['availability_offline_message'];

		return '' !== trim( $value ) ? $value : Settings::DEFAULT_OFFLINE_MESSAGE;
	}

	/**
	 * Whether the subtle "We're online" indicator may be shown (only ever
	 * rendered when the resolved state is genuinely available, ADR-0017 §5).
	 */
	public function online_indicator_enabled(): bool {
		return ! empty( $this->settings->get()['availability_online_indicator'] );
	}

	/**
	 * Whether the stored schedule / exceptions parse cleanly. `false`
	 * means the resolver fell back to fail-safe `unavailable` and an
	 * admin-only Diagnostics warning is warranted (ADR-0017 §4).
	 */
	public function schedule_config_is_valid(): bool {
		return $this->load_config()[2];
	}

	/**
	 * The current operator control mode: `automatic`, `force_online`, or
	 * `force_offline`.
	 */
	public function current_mode(): string {
		$override = $this->current_override();

		return null === $override ? self::MODE_AUTOMATIC : $override->mode();
	}

	/**
	 * The active manual override, or `null`. An expired non-null override
	 * is reaped here (deleted + audited) and treated as absent (ADR-0017 §6).
	 */
	public function current_override(): ?AvailabilityOverride {
		$override = AvailabilityOverride::from_option( get_option( self::OVERRIDE_OPTION, null ) );

		if ( null === $override ) {
			// Absent, or corrupt — either way, clear any corrupt row and
			// fall through to `Automatic`.
			if ( false !== get_option( self::OVERRIDE_OPTION, false ) ) {
				delete_option( self::OVERRIDE_OPTION );
			}

			return null;
		}

		if ( ! $override->is_active( $this->now()->getTimestamp() ) ) {
			$this->reap_expired( $override );

			return null;
		}

		return $override;
	}

	/**
	 * Deletes an expired override and records the audit event.
	 *
	 * @param AvailabilityOverride $override The expired override.
	 */
	private function reap_expired( AvailabilityOverride $override ): void {
		delete_option( self::OVERRIDE_OPTION );

		if ( null === $this->audit ) {
			return;
		}

		$this->audit->record(
			'availability.override_expired',
			'system',
			null,
			array( 'mode' => $override->mode() ),
			array( 'mode' => Classification::PUBLIC ),
			Classification::INTERNAL
		);
	}

	/**
	 * "Now" in the WordPress site timezone.
	 */
	private function now(): \DateTimeImmutable {
		if ( function_exists( 'current_datetime' ) ) {
			return current_datetime();
		}

		return new \DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Loads and strictly parses the schedule + exceptions.
	 *
	 * @return array{0: WeeklySchedule, 1: ExceptionSet, 2: bool} Schedule, exceptions, and whether both parsed.
	 */
	private function load_config(): array {
		$values = $this->settings->get();

		try {
			$schedule   = WeeklySchedule::from_array( $values['availability_schedule'] );
			$exceptions = ExceptionSet::from_array( $values['availability_exceptions'] );
		} catch ( InvalidScheduleException $exception ) {
			unset( $exception );

			// Fail-safe: an empty schedule with no exceptions always resolves
			// to `unavailable` (ADR-0017 §4). The stored value is NOT rewritten.
			return array( WeeklySchedule::from_array( array() ), ExceptionSet::none(), false );
		}

		return array( $schedule, $exceptions, true );
	}
}
