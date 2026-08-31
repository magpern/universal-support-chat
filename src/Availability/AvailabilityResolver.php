<?php
/**
 * Pure availability resolver.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * Applies the frozen precedence (ADR-0017 §3):
 *
 *   1. active manual override        -> its forced state
 *   2. today's date exception        -> closed => unavailable; special hours => in-window?
 *   3. weekly schedule (Automatic)   -> in a scheduled interval?
 *   4. otherwise                     -> unavailable (fail-safe)
 *
 * This class is pure: no WordPress calls, no I/O, no clock access. `$now`
 * is passed in already expressed in the site timezone. It never throws —
 * the caller ({@see AvailabilityService}) is responsible for the fail-safe
 * when the schedule/exception data itself cannot be built.
 */
final class AvailabilityResolver {

	/**
	 * Resolves the visitor-facing state.
	 *
	 * @param WeeklySchedule            $schedule   The recurring weekly schedule.
	 * @param ExceptionSet              $exceptions Calendar-date exceptions.
	 * @param AvailabilityOverride|null $override   The manual override, if any.
	 * @param \DateTimeImmutable        $now        Site-local moment.
	 */
	public function resolve(
		WeeklySchedule $schedule,
		ExceptionSet $exceptions,
		?AvailabilityOverride $override,
		\DateTimeImmutable $now
	): AvailabilityState {
		if ( null !== $override && $override->is_active( $now->getTimestamp() ) ) {
			return $override->forced_state();
		}

		$exception = $exceptions->for_date( $now );
		if ( null !== $exception ) {
			return $exception->is_open_at( $now )
				? AvailabilityState::AVAILABLE
				: AvailabilityState::UNAVAILABLE;
		}

		return $schedule->is_open_at( $now )
			? AvailabilityState::AVAILABLE
			: AvailabilityState::UNAVAILABLE;
	}
}
