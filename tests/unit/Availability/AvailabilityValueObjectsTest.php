<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Availability;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\DateException;
use UniversalSupportChat\Availability\ExceptionSet;
use UniversalSupportChat\Availability\InvalidScheduleException;
use UniversalSupportChat\Availability\TimeInterval;
use UniversalSupportChat\Availability\WeeklySchedule;

final class AvailabilityValueObjectsTest extends TestCase {

	public function test_time_interval_half_open_boundaries(): void {
		$interval = new TimeInterval( 12 * 60, 15 * 60 );

		$this->assertTrue( $interval->contains( 12 * 60 ) );
		$this->assertTrue( $interval->contains( ( 14 * 60 ) + 59 ) );
		$this->assertFalse( $interval->contains( 15 * 60 ), 'end minute is exclusive' );
		$this->assertFalse( $interval->contains( ( 11 * 60 ) + 59 ) );
	}

	public function test_time_interval_rejects_reversed_and_malformed(): void {
		$this->expectException( InvalidScheduleException::class );
		new TimeInterval( 15 * 60, 12 * 60 );
	}

	public function test_time_interval_from_array_parses_hhmm(): void {
		$interval = TimeInterval::from_array(
			array(
				'start' => '09:30',
				'end'   => '17:00',
			)
		);

		$this->assertSame(
			array(
				'start' => '09:30',
				'end'   => '17:00',
			),
			$interval->to_array()
		);
	}

	/**
	 * @dataProvider bad_interval_provider
	 *
	 * @param mixed $raw Raw value.
	 */
	public function test_time_interval_from_array_rejects_bad_input( $raw ): void {
		$this->expectException( InvalidScheduleException::class );
		TimeInterval::from_array( $raw );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function bad_interval_provider(): array {
		return array(
			'not an array'        => array( '09:00-17:00' ),
			'missing end'         => array( array( 'start' => '09:00' ) ),
			'extra key'           => array(
				array(
					'start' => '09:00',
					'end'   => '17:00',
					'x'     => 1,
				),
			),
			'non hh:mm'           => array(
				array(
					'start' => '9:00',
					'end'   => '17:00',
				),
			),
			'hour out of range'   => array(
				array(
					'start' => '25:00',
					'end'   => '26:00',
				),
			),
			'minute out of range' => array(
				array(
					'start' => '09:75',
					'end'   => '17:00',
				),
			),
			'equal start end'     => array(
				array(
					'start' => '09:00',
					'end'   => '09:00',
				),
			),
		);
	}

	public function test_weekly_schedule_default_is_weekdays_noon_to_three(): void {
		$schedule = WeeklySchedule::default_schedule();

		$monday_1pm = new \DateTimeImmutable( '2026-08-31 13:00', new \DateTimeZone( 'Europe/Stockholm' ) );
		$saturday   = new \DateTimeImmutable( '2026-09-05 13:00', new \DateTimeZone( 'Europe/Stockholm' ) );

		$this->assertTrue( $schedule->is_open_at( $monday_1pm ) );
		$this->assertFalse( $schedule->is_open_at( $saturday ) );
		$this->assertFalse( $schedule->is_empty() );
	}

	public function test_weekly_schedule_round_trips_and_rejects_unknown_day(): void {
		$array    = array(
			'mon' => array(
				array(
					'start' => '08:00',
					'end'   => '12:00',
				),
				array(
					'start' => '13:00',
					'end'   => '16:00',
				),
			),
			'tue' => array(),
		);
		$schedule = WeeklySchedule::from_array( $array );

		$this->assertSame(
			array(
				array(
					'start' => '08:00',
					'end'   => '12:00',
				),
				array(
					'start' => '13:00',
					'end'   => '16:00',
				),
			),
			$schedule->to_array()['mon']
		);

		$this->expectException( InvalidScheduleException::class );
		WeeklySchedule::from_array( array( 'funday' => array() ) );
	}

	public function test_weekly_schedule_rejects_non_list_intervals(): void {
		$this->expectException( InvalidScheduleException::class );
		WeeklySchedule::from_array(
			array(
				'mon' => array(
					'first' => array(
						'start' => '08:00',
						'end'   => '12:00',
					),
				),
			)
		);
	}

	public function test_empty_schedule_is_empty(): void {
		$this->assertTrue( WeeklySchedule::from_array( array() )->is_empty() );
	}

	public function test_exception_set_closed_and_special_hours(): void {
		$set = ExceptionSet::from_array(
			array(
				'2026-12-24' => 'closed',
				'2026-12-31' => array(
					array(
						'start' => '10:00',
						'end'   => '12:00',
					),
				),
			)
		);

		$xmas_eve_noon = new \DateTimeImmutable( '2026-12-24 13:00', new \DateTimeZone( 'Europe/Stockholm' ) );
		$nye_11am      = new \DateTimeImmutable( '2026-12-31 11:00', new \DateTimeZone( 'Europe/Stockholm' ) );
		$nye_1pm       = new \DateTimeImmutable( '2026-12-31 13:00', new \DateTimeZone( 'Europe/Stockholm' ) );

		$this->assertInstanceOf( DateException::class, $set->for_date( $xmas_eve_noon ) );
		$this->assertFalse( $set->for_date( $xmas_eve_noon )->is_open_at( $xmas_eve_noon ) );
		$this->assertTrue( $set->for_date( $nye_11am )->is_open_at( $nye_11am ) );
		$this->assertFalse( $set->for_date( $nye_1pm )->is_open_at( $nye_1pm ) );
		$this->assertNull( $set->for_date( new \DateTimeImmutable( '2026-06-01 13:00', new \DateTimeZone( 'Europe/Stockholm' ) ) ) );
	}

	/**
	 * @dataProvider bad_exception_provider
	 *
	 * @param mixed $raw Raw value.
	 */
	public function test_exception_set_rejects_bad_input( $raw ): void {
		$this->expectException( InvalidScheduleException::class );
		ExceptionSet::from_array( $raw );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function bad_exception_provider(): array {
		return array(
			'not a date key'      => array( array( 'someday' => 'closed' ) ),
			'impossible calendar' => array( array( '2026-02-30' => 'closed' ) ),
			'bad value'           => array( array( '2026-02-10' => 'open' ) ),
			'bad interval'        => array( array( '2026-02-10' => array( array( 'start' => '9' ) ) ) ),
			'duplicate date row'  => array(
				array(
					array(
						'date' => '2026-02-10',
						'mode' => 'closed',
					),
					array(
						'date' => '2026-02-10',
						'mode' => 'closed',
					),
				),
			),
			'unknown mode row'    => array(
				array(
					array(
						'date' => '2026-02-10',
						'mode' => 'weird',
					),
				),
			),
		);
	}

	public function test_exception_rows_round_trip_and_hours_without_times_is_a_closed_day(): void {
		$set = ExceptionSet::from_array(
			array(
				array(
					'date'  => '2026-06-19',
					'mode'  => 'hours',
					'start' => '',
					'end'   => '',
				),
			)
		);

		$this->assertSame( array( '2026-06-19' => 'closed' ), $set->to_array() );
	}

	public function test_override_null_expiry_never_expires(): void {
		$override = new AvailabilityOverride( AvailabilityOverride::MODE_FORCE_OFFLINE, null, 1, 1000 );

		$this->assertTrue( $override->is_active( PHP_INT_MAX ) );
		$this->assertSame( 'force_offline', $override->mode() );
		$this->assertNull( $override->expires_at() );
	}

	public function test_override_non_null_expiry_is_bounded(): void {
		$override = new AvailabilityOverride( AvailabilityOverride::MODE_FORCE_ONLINE, 2000, 1, 1000 );

		$this->assertTrue( $override->is_active( 1999 ) );
		$this->assertFalse( $override->is_active( 2000 ) );
		$this->assertFalse( $override->is_active( 2001 ) );
	}

	public function test_override_from_option_rejects_corrupt_value(): void {
		$this->assertNull( AvailabilityOverride::from_option( null ) );
		$this->assertNull( AvailabilityOverride::from_option( array( 'expires_at' => 5 ) ) );
		$this->assertNull( AvailabilityOverride::from_option( array( 'mode' => 'nonsense' ) ) );

		$ok = AvailabilityOverride::from_option(
			array(
				'mode'       => 'force_online',
				'expires_at' => null,
				'set_by'     => 7,
				'set_at'     => 123,
			)
		);
		$this->assertInstanceOf( AvailabilityOverride::class, $ok );
		$this->assertSame( 7, $ok->set_by() );
	}
}
