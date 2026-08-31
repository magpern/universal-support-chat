<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Availability;

use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityResolver;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Availability\AvailabilityState;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use WP_UnitTestCase;

final class AvailabilityServiceTest extends WP_UnitTestCase {

	private SchemaHealth $health;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->health = new SchemaHealth();
		delete_option( Settings::OPTION_NAME );
		delete_option( AvailabilityService::OVERRIDE_OPTION );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		delete_option( AvailabilityService::OVERRIDE_OPTION );
		parent::tear_down();
	}

	private function service(): AvailabilityService {
		return new AvailabilityService(
			new Settings(),
			new AvailabilityResolver(),
			new AuditLogger( $this->health, new Redactor() )
		);
	}

	public function test_absent_override_is_automatic(): void {
		$service = $this->service();
		$this->assertSame( AvailabilityService::MODE_AUTOMATIC, $service->current_mode() );
		$this->assertNull( $service->current_override() );
	}

	public function test_force_offline_override_resolves_unavailable(): void {
		update_option(
			AvailabilityService::OVERRIDE_OPTION,
			array(
				'mode'       => AvailabilityOverride::MODE_FORCE_OFFLINE,
				'expires_at' => null,
				'set_by'     => 1,
				'set_at'     => time(),
			)
		);

		$service = $this->service();
		$this->assertSame( AvailabilityState::UNAVAILABLE, $service->resolve_state() );
		$this->assertTrue( $service->is_unavailable() );
		$this->assertSame( AvailabilityOverride::MODE_FORCE_OFFLINE, $service->current_mode() );
	}

	public function test_null_expiry_override_persists_and_is_reported(): void {
		update_option(
			AvailabilityService::OVERRIDE_OPTION,
			array(
				'mode'       => AvailabilityOverride::MODE_FORCE_ONLINE,
				'expires_at' => null,
				'set_by'     => 3,
				'set_at'     => time(),
			)
		);

		$override = $this->service()->current_override();
		$this->assertNotNull( $override );
		$this->assertNull( $override->expires_at() );

		// A second read still finds it — it is never reaped.
		$this->assertNotNull( $this->service()->current_override() );
	}

	public function test_expired_non_null_override_is_reaped_and_audited(): void {
		update_option(
			AvailabilityService::OVERRIDE_OPTION,
			array(
				'mode'       => AvailabilityOverride::MODE_FORCE_OFFLINE,
				'expires_at' => time() - 10,
				'set_by'     => 1,
				'set_at'     => time() - 100,
			)
		);

		$service = $this->service();
		$this->assertNull( $service->current_override() );
		$this->assertFalse( get_option( AvailabilityService::OVERRIDE_OPTION, false ) );

		$actions = array_map(
			static fn( $row ) => $row['action'],
			( new AuditLogRepository( $this->health ) )->recent( 10 )
		);
		$this->assertContains( 'availability.override_expired', $actions );
	}

	public function test_corrupt_override_option_falls_back_to_automatic_and_is_cleared(): void {
		update_option( AvailabilityService::OVERRIDE_OPTION, array( 'mode' => 'garbage' ) );

		$service = $this->service();
		$this->assertNull( $service->current_override() );
		$this->assertSame( AvailabilityService::MODE_AUTOMATIC, $service->current_mode() );
		$this->assertFalse( get_option( AvailabilityService::OVERRIDE_OPTION, false ) );
	}

	public function test_corrupt_stored_schedule_fails_closed_without_rewrite(): void {
		// Seed a corrupt stored value directly, bypassing the atomic
		// sanitiser (which would otherwise reject/normalise it on write).
		remove_all_filters( 'sanitize_option_' . Settings::OPTION_NAME );
		update_option(
			Settings::OPTION_NAME,
			array(
				'availability_schedule' => array(
					'mon' => array(
						array(
							'start' => '99:99',
							'end'   => '10:00',
						),
					),
				),
			)
				+ ( new Settings() )->defaults()
		);

		$service = $this->service();
		$this->assertFalse( $service->schedule_config_is_valid() );
		$this->assertSame( AvailabilityState::UNAVAILABLE, $service->resolve_state() );

		// The stored (bad) value is left untouched — not normalised to default.
		$stored = get_option( Settings::OPTION_NAME );
		$this->assertSame( '99:99', $stored['availability_schedule']['mon'][0]['start'] );
	}

	public function test_offline_message_falls_back_to_default_when_blank(): void {
		update_option(
			Settings::OPTION_NAME,
			array( 'availability_offline_message' => '   ' ) + ( new Settings() )->defaults()
		);

		$this->assertSame( Settings::DEFAULT_OFFLINE_MESSAGE, $this->service()->offline_message() );
	}
}
