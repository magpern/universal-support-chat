<?php
/**
 * A single calendar-date override of the weekly schedule.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * Either a full closure for that date, or a replacement set of intervals
 * ("special hours") that supersedes the weekly pattern for that date only
 * (ADR-0017 §3).
 */
final class DateException {

	/**
	 * Constructor.
	 *
	 * @param bool                        $closed    Whether the date is fully closed.
	 * @param array<int, TimeInterval>    $intervals Replacement intervals when not closed.
	 */
	private function __construct(
		private readonly bool $closed,
		private readonly array $intervals
	) {}

	/**
	 * A fully-closed date.
	 */
	public static function closed(): self {
		return new self( true, array() );
	}

	/**
	 * A date with special hours.
	 *
	 * @param array<int, TimeInterval> $intervals Replacement intervals.
	 */
	public static function special_hours( array $intervals ): self {
		return new self( false, $intervals );
	}

	/**
	 * Builds from stored/submitted data: the string `"closed"` or a list of
	 * interval arrays.
	 *
	 * @param mixed $raw Raw value.
	 *
	 * @throws InvalidScheduleException When the value is neither `"closed"` nor a valid interval list.
	 */
	public static function from_value( $raw ): self {
		if ( 'closed' === $raw ) {
			return self::closed();
		}

		if ( ! is_array( $raw ) || ( array() !== $raw && ! array_is_list( $raw ) ) ) {
			throw new InvalidScheduleException( 'Exception value must be "closed" or a list of intervals.' );
		}

		return self::special_hours(
			array_map(
				static fn( $interval ) => TimeInterval::from_array( $interval ),
				$raw
			)
		);
	}

	/**
	 * Whether this exception makes the date available at the given moment.
	 *
	 * @param \DateTimeImmutable $now Site-local moment (its date must match this exception).
	 */
	public function is_open_at( \DateTimeImmutable $now ): bool {
		if ( $this->closed ) {
			return false;
		}

		$minute_of_day = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );

		foreach ( $this->intervals as $interval ) {
			if ( $interval->contains( $minute_of_day ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Serialises back to the storage value (`"closed"` or a list of pairs).
	 *
	 * @return string|array<int, array{start: string, end: string}>
	 */
	public function to_value(): string|array {
		if ( $this->closed ) {
			return 'closed';
		}

		return array_map(
			static fn( TimeInterval $interval ) => $interval->to_array(),
			$this->intervals
		);
	}
}
