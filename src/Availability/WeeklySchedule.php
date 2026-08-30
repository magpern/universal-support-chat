<?php
/**
 * The recurring weekly support schedule.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * Seven weekday keys (`mon`–`sun`), each a possibly empty list of
 * {@see TimeInterval}s, evaluated in the site timezone (ADR-0017 §2). An
 * empty list means "closed that weekday". Overlapping intervals are allowed
 * and treated as a union.
 */
final class WeeklySchedule {

	/**
	 * ISO-8601 weekday number (`1` = Monday) to storage key.
	 *
	 * @var array<int, string>
	 */
	private const DAYS = array(
		1 => 'mon',
		2 => 'tue',
		3 => 'wed',
		4 => 'thu',
		5 => 'fri',
		6 => 'sat',
		7 => 'sun',
	);

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, TimeInterval>> $by_day Weekday key to intervals.
	 */
	private function __construct( private readonly array $by_day ) {}

	/**
	 * The frozen default schedule: Monday–Friday 12:00–15:00 (ADR-0017;
	 * plan v2 §6).
	 */
	public static function default_schedule(): self {
		$weekday = array( new TimeInterval( 12 * 60, 15 * 60 ) );
		$weekend = array();
		$by_day  = array(
			'mon' => $weekday,
			'tue' => $weekday,
			'wed' => $weekday,
			'thu' => $weekday,
			'fri' => $weekday,
			'sat' => $weekend,
			'sun' => $weekend,
		);

		return new self( $by_day );
	}

	/**
	 * Builds a schedule from stored/submitted data. Rejects the whole
	 * payload on any malformed element (ADR-0017; plan v2 §6).
	 *
	 * @param mixed $raw Raw value: `{ "mon": [ { "start": "HH:MM", "end": "HH:MM" }, … ], … }`.
	 *
	 * @throws InvalidScheduleException When any weekday key, list, or interval is invalid.
	 */
	public static function from_array( $raw ): self {
		if ( ! is_array( $raw ) ) {
			throw new InvalidScheduleException( 'Schedule must be an array of weekdays.' );
		}

		$known = array_values( self::DAYS );

		foreach ( array_keys( $raw ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				throw new InvalidScheduleException( 'Unknown weekday key in schedule.' );
			}
		}

		$by_day = array();

		foreach ( $known as $day ) {
			$intervals = $raw[ $day ] ?? array();

			if ( ! is_array( $intervals ) || ( array() !== $intervals && array_is_list( $intervals ) === false ) ) {
				throw new InvalidScheduleException( 'Weekday intervals must be a list.' );
			}

			$by_day[ $day ] = array_map(
				static fn( $interval ) => TimeInterval::from_array( $interval ),
				$intervals
			);
		}

		return new self( $by_day );
	}

	/**
	 * Whether the given moment falls within a scheduled interval. `$now`
	 * must already be expressed in the site timezone.
	 *
	 * @param \DateTimeImmutable $now Site-local moment.
	 */
	public function is_open_at( \DateTimeImmutable $now ): bool {
		$day           = self::DAYS[ (int) $now->format( 'N' ) ];
		$minute_of_day = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );

		foreach ( $this->by_day[ $day ] as $interval ) {
			if ( $interval->contains( $minute_of_day ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Serialises back to the storage array shape.
	 *
	 * @return array<string, array<int, array{start: string, end: string}>>
	 */
	public function to_array(): array {
		$out = array();

		foreach ( $this->by_day as $day => $intervals ) {
			$out[ $day ] = array_map(
				static fn( TimeInterval $interval ) => $interval->to_array(),
				$intervals
			);
		}

		return $out;
	}

	/**
	 * Whether every weekday is closed (no intervals anywhere).
	 */
	public function is_empty(): bool {
		foreach ( $this->by_day as $intervals ) {
			if ( array() !== $intervals ) {
				return false;
			}
		}

		return true;
	}
}
