<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Availability;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityResolver;
use UniversalSupportChat\Availability\AvailabilityState;
use UniversalSupportChat\Availability\ExceptionSet;
use UniversalSupportChat\Availability\WeeklySchedule;

final class AvailabilityResolverTest extends TestCase {

	private AvailabilityResolver $resolver;
	private \DateTimeZone $tz;

	protected function setUp(): void {
		$this->resolver = new AvailabilityResolver();
		$this->tz       = new \DateTimeZone( 'Europe/Stockholm' );
	}

	private function moment( string $when ): \DateTimeImmutable {
		return new \DateTimeImmutable( $when, $this->tz );
	}

	private function default_schedule(): WeeklySchedule {
		return WeeklySchedule::default_schedule();
	}

	public function test_in_hours_weekday_is_available(): void {
		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-08-31 12:00' ) )
		);
	}

	public function test_exactly_at_start_is_available_and_at_end_is_not(): void {
		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-08-31 12:00' ) )
		);
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-08-31 15:00' ) )
		);
		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-08-31 14:59' ) )
		);
	}

	public function test_out_of_hours_and_weekend_are_unavailable(): void {
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-08-31 09:00' ) )
		);
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), null, $this->moment( '2026-09-05 13:00' ) )
		);
	}

	public function test_empty_schedule_is_fail_safe_unavailable(): void {
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( WeeklySchedule::from_array( array() ), ExceptionSet::none(), null, $this->moment( '2026-08-31 13:00' ) )
		);
	}

	public function test_closed_exception_beats_in_hours_schedule(): void {
		$exceptions = ExceptionSet::from_array( array( '2026-08-31' => 'closed' ) );

		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), $exceptions, null, $this->moment( '2026-08-31 13:00' ) )
		);
	}

	public function test_special_hours_exception_replaces_the_weekday(): void {
		$exceptions = ExceptionSet::from_array(
			array(
				'2026-08-31' => array(
					array(
						'start' => '18:00',
						'end'   => '20:00',
					),
				),
			)
		);

		// 13:00 is in the normal weekly window but NOT in the special hours.
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), $exceptions, null, $this->moment( '2026-08-31 13:00' ) )
		);
		// 19:00 is outside the normal window but inside the special hours.
		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), $exceptions, null, $this->moment( '2026-08-31 19:00' ) )
		);
	}

	public function test_force_offline_override_beats_in_hours(): void {
		$override = new AvailabilityOverride( AvailabilityOverride::MODE_FORCE_OFFLINE, null, 1, 1 );

		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), $override, $this->moment( '2026-08-31 13:00' ) )
		);
	}

	public function test_force_online_override_beats_out_of_hours_and_closed_exception(): void {
		$override   = new AvailabilityOverride( AvailabilityOverride::MODE_FORCE_ONLINE, null, 1, 1 );
		$exceptions = ExceptionSet::from_array( array( '2026-09-05' => 'closed' ) );

		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), $exceptions, $override, $this->moment( '2026-09-05 03:00' ) )
		);
	}

	public function test_expired_override_is_ignored(): void {
		$now      = $this->moment( '2026-08-31 09:00' );
		$override = new AvailabilityOverride( AvailabilityOverride::MODE_FORCE_ONLINE, $now->getTimestamp() - 1, 1, 1 );

		// Override expired -> fall through to the schedule -> 09:00 is out of hours.
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $this->default_schedule(), ExceptionSet::none(), $override, $now )
		);
	}

	public function test_dst_spring_forward_day_resolves_without_error(): void {
		// 2026-03-29: clocks jump 02:00 -> 03:00 in Europe/Stockholm.
		$schedule = WeeklySchedule::from_array(
			array(
				'sun' => array(
					array(
						'start' => '01:00',
						'end'   => '06:00',
					),
				),
			)
		);

		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $schedule, ExceptionSet::none(), null, $this->moment( '2026-03-29 04:30' ) )
		);
		$this->assertSame(
			AvailabilityState::UNAVAILABLE,
			$this->resolver->resolve( $schedule, ExceptionSet::none(), null, $this->moment( '2026-03-29 07:30' ) )
		);
	}

	public function test_dst_fall_back_day_resolves_without_error(): void {
		// 2026-10-25: clocks fall back 03:00 -> 02:00 in Europe/Stockholm.
		$schedule = WeeklySchedule::from_array(
			array(
				'sun' => array(
					array(
						'start' => '01:00',
						'end'   => '06:00',
					),
				),
			)
		);

		$this->assertSame(
			AvailabilityState::AVAILABLE,
			$this->resolver->resolve( $schedule, ExceptionSet::none(), null, $this->moment( '2026-10-25 02:30' ) )
		);
	}
}
