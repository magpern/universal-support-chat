<?php
/**
 * A half-open wall-clock interval within a single day.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * `[start, end)` in minutes since midnight, site-local wall clock. The end
 * minute is exclusive: an interval `12:00`–`15:00` does not include `15:00`
 * (ADR-0017 §3; plan v2 §5).
 */
final class TimeInterval {

	/**
	 * Minutes in a day.
	 */
	private const DAY_MINUTES = 1440;

	/**
	 * Constructor.
	 *
	 * @param int $start_minute Inclusive start, `0`–`1439`.
	 * @param int $end_minute   Exclusive end, `1`–`1440`, strictly greater than start.
	 *
	 * @throws InvalidScheduleException When the bounds are out of range or not ordered.
	 */
	public function __construct(
		private readonly int $start_minute,
		private readonly int $end_minute
	) {
		if ( $start_minute < 0 || $start_minute > self::DAY_MINUTES - 1 ) {
			throw new InvalidScheduleException( 'Interval start out of range.' );
		}

		if ( $end_minute < 1 || $end_minute > self::DAY_MINUTES ) {
			throw new InvalidScheduleException( 'Interval end out of range.' );
		}

		if ( $end_minute <= $start_minute ) {
			throw new InvalidScheduleException( 'Interval end must be after its start.' );
		}
	}

	/**
	 * Builds an interval from a `{ "start": "HH:MM", "end": "HH:MM" }` array.
	 *
	 * @param mixed $raw Raw value.
	 *
	 * @throws InvalidScheduleException When the shape or the times are invalid.
	 */
	public static function from_array( $raw ): self {
		if ( ! is_array( $raw ) || ! isset( $raw['start'], $raw['end'] ) || count( $raw ) !== 2 ) {
			throw new InvalidScheduleException( 'Interval must be a { start, end } pair.' );
		}

		return new self( self::parse_hhmm( $raw['start'] ), self::parse_hhmm( $raw['end'] ) );
	}

	/**
	 * Whether a given minute-of-day falls inside this interval.
	 *
	 * @param int $minute_of_day Minutes since midnight, `0`–`1439`.
	 */
	public function contains( int $minute_of_day ): bool {
		return $minute_of_day >= $this->start_minute && $minute_of_day < $this->end_minute;
	}

	/**
	 * Serialises back to `{ "start": "HH:MM", "end": "HH:MM" }`.
	 *
	 * @return array{start: string, end: string}
	 */
	public function to_array(): array {
		return array(
			'start' => self::format_hhmm( $this->start_minute ),
			'end'   => self::format_hhmm( $this->end_minute ),
		);
	}

	/**
	 * Parses `HH:MM` (00:00–23:59, plus 24:00 as the exclusive end of day).
	 *
	 * @param mixed $value Raw value.
	 *
	 * @throws InvalidScheduleException When not a valid `HH:MM` string.
	 */
	private static function parse_hhmm( $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([0-9]{2}):([0-9]{2})$/', $value, $matches ) ) {
			throw new InvalidScheduleException( 'Time must be formatted HH:MM.' );
		}

		$hours   = (int) $matches[1];
		$minutes = (int) $matches[2];

		if ( $minutes > 59 || $hours > 24 || ( 24 === $hours && $minutes > 0 ) ) {
			throw new InvalidScheduleException( 'Time is out of range.' );
		}

		return ( $hours * 60 ) + $minutes;
	}

	/**
	 * Formats minutes-since-midnight back to `HH:MM`.
	 *
	 * @param int $minute Minutes since midnight.
	 */
	private static function format_hhmm( int $minute ): string {
		return sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );
	}
}
