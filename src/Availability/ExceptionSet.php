<?php
/**
 * The set of calendar-date exceptions to the weekly schedule.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * A map of `Y-m-d` (site timezone) to {@see DateException}. Precedence tier
 * 2 in ADR-0017 §3 — a match for "today" supersedes the weekly schedule.
 */
final class ExceptionSet {

	/**
	 * Constructor.
	 *
	 * @param array<string, DateException> $by_date Date string to exception.
	 */
	private function __construct( private readonly array $by_date ) {}

	/**
	 * The empty set (the frozen default — no exceptions).
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Builds from stored/submitted data, rejecting the whole payload on any
	 * malformed key or value (ADR-0017; plan v2 §6).
	 *
	 * @param mixed $raw Raw value: `{ "YYYY-MM-DD": "closed" | [ intervals ], … }`.
	 *
	 * @throws InvalidScheduleException When a date key or an exception value is invalid.
	 */
	public static function from_array( $raw ): self {
		if ( ! is_array( $raw ) ) {
			throw new InvalidScheduleException( 'Exceptions must be a map of dates.' );
		}

		$by_date = array();

		foreach ( $raw as $date => $value ) {
			if ( ! is_string( $date ) || ! self::is_calendar_date( $date ) ) {
				throw new InvalidScheduleException( 'Exception key must be a YYYY-MM-DD date.' );
			}

			$by_date[ $date ] = DateException::from_value( $value );
		}

		return new self( $by_date );
	}

	/**
	 * The exception for the given moment's date, or `null` when none applies.
	 *
	 * @param \DateTimeImmutable $now Site-local moment.
	 */
	public function for_date( \DateTimeImmutable $now ): ?DateException {
		return $this->by_date[ $now->format( 'Y-m-d' ) ] ?? null;
	}

	/**
	 * Serialises back to the storage array shape.
	 *
	 * @return array<string, string|array<int, array{start: string, end: string}>>
	 */
	public function to_array(): array {
		$out = array();

		foreach ( $this->by_date as $date => $exception ) {
			$out[ $date ] = $exception->to_value();
		}

		return $out;
	}

	/**
	 * Whether `$value` is a real `Y-m-d` calendar date.
	 *
	 * @param string $value Candidate date string.
	 */
	private static function is_calendar_date( string $value ): bool {
		if ( 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
