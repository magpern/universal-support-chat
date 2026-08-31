<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityResolver;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Availability\Admin\OverrideAction;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use WP_UnitTestCase;

final class AvailabilityAdminTest extends WP_UnitTestCase {

	private SchemaHealth $health;
	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();

		$this->health   = new SchemaHealth();
		$this->settings = new Settings();
		delete_option( Settings::OPTION_NAME );
		delete_option( AvailabilityService::OVERRIDE_OPTION );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		delete_option( AvailabilityService::OVERRIDE_OPTION );
		unset( $_POST, $_REQUEST );
		parent::tear_down();
	}

	private function availability(): AvailabilityService {
		return new AvailabilityService( $this->settings, new AvailabilityResolver() );
	}

	/**
	 * @return array<int, string>
	 */
	private function recent_audit_actions(): array {
		return array_map(
			static fn( $row ) => $row['action'],
			( new AuditLogRepository( $this->health ) )->recent( 20 )
		);
	}

	// ---- WP3: atomic settings validation ----

	public function test_valid_schedule_round_trips_to_the_canonical_map(): void {
		$result = $this->settings->sanitize(
			array(
				'availability_schedule' => array(
					'mon' => array(
						array(
							'start' => '09:00',
							'end'   => '12:00',
						),
						array(
							'start' => '',
							'end'   => '',
						),
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'start' => '09:00',
					'end'   => '12:00',
				),
			),
			$result['availability_schedule']['mon']
		);
		$this->assertSame( array(), $result['availability_schedule']['tue'] );
	}

	public function test_malformed_schedule_submission_is_rejected_and_prior_value_preserved(): void {
		$good = $this->settings->sanitize(
			array(
				'availability_schedule' => array(
					'wed' => array(
						array(
							'start' => '08:00',
							'end'   => '10:00',
						),
					),
				),
			)
		);
		update_option( Settings::OPTION_NAME, $good );

		$after = $this->settings->sanitize(
			array(
				'availability_schedule' => array(
					'wed' => array(
						array(
							'start' => '08:00',
							'end'   => '07:00',
						),
					),
				),
			)
		);

		// The prior valid Wednesday hours survive — the bad submission is not
		// normalised to the default either.
		$this->assertSame(
			array(
				array(
					'start' => '08:00',
					'end'   => '10:00',
				),
			),
			$after['availability_schedule']['wed']
		);
	}

	public function test_bad_schedule_with_good_exceptions_rejects_the_whole_section(): void {
		$good = $this->settings->sanitize(
			array(
				'availability_schedule'   => array(
					'thu' => array(
						array(
							'start' => '09:00',
							'end'   => '11:00',
						),
					),
				),
				'availability_exceptions' => array(),
			)
		);
		update_option( Settings::OPTION_NAME, $good );

		$after = $this->settings->sanitize(
			array(
				'availability_schedule'   => array(
					'thu' => array(
						array(
							'start' => '11:00',
							'end'   => '09:00',
						),
					),
				),
				'availability_exceptions' => array(
					array(
						'date' => '2026-12-24',
						'mode' => 'closed',
					),
				),
			)
		);

		// Neither half is applied — the new exception is NOT saved just
		// because it was individually valid.
		$this->assertSame(
			array(
				array(
					'start' => '09:00',
					'end'   => '11:00',
				),
			),
			$after['availability_schedule']['thu']
		);
		$this->assertSame( array(), $after['availability_exceptions'] );
	}

	public function test_good_schedule_with_bad_exceptions_rejects_the_whole_section(): void {
		$good = $this->settings->sanitize(
			array(
				'availability_schedule'   => array(
					'fri' => array(
						array(
							'start' => '13:00',
							'end'   => '14:00',
						),
					),
				),
				'availability_exceptions' => array(),
			)
		);
		update_option( Settings::OPTION_NAME, $good );

		$after = $this->settings->sanitize(
			array(
				'availability_schedule'   => array(
					'fri' => array(
						array(
							'start' => '10:00',
							'end'   => '12:00',
						),
					),
				),
				'availability_exceptions' => array(
					array(
						'date'  => '2026-12-31',
						'mode'  => 'hours',
						'start' => '18:00',
						'end'   => '09:00',
					),
				),
			)
		);

		// The individually-valid new schedule is NOT saved.
		$this->assertSame(
			array(
				array(
					'start' => '13:00',
					'end'   => '14:00',
				),
			),
			$after['availability_schedule']['fri']
		);
		$this->assertSame( array(), $after['availability_exceptions'] );
	}

	// ---- audit events ----
	//
	// The audit hooks live on `SupportChatSettingsPage`, which is already
	// constructed and `register()`-ed by the plugin's own `plugins_loaded`
	// bootstrap in this WP test install — a second instance is not created
	// here, so these assertions exercise the real, single, live wiring.

	public function test_schedule_and_exception_changes_are_audited_without_leaking_contents(): void {
		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule'   => array(
						'mon' => array(
							array(
								'start' => '13:37',
								'end'   => '14:37',
							),
						),
					),
					'availability_exceptions' => array(
						array(
							'date' => '2026-12-24',
							'mode' => 'closed',
						),
					),
				)
			)
		);

		$actions = $this->recent_audit_actions();
		$this->assertContains( 'availability.schedule_updated', $actions );
		$this->assertContains( 'availability.exceptions_updated', $actions );

		// No schedule times or exception dates in any audit context.
		foreach ( ( new AuditLogRepository( $this->health ) )->recent( 20 ) as $row ) {
			$this->assertStringNotContainsString( '13:37', (string) wp_json_encode( $row ) );
			$this->assertStringNotContainsString( '2026-12-24', (string) wp_json_encode( $row ) );
		}
	}

	/**
	 * When there is no logged-in user (e.g. a WP-CLI or programmatic save),
	 * the schedule/exceptions audit events must record `system`/0, never a
	 * fabricated `operator`/0 pair.
	 */
	public function test_schedule_change_with_no_logged_in_user_is_audited_as_system(): void {
		wp_set_current_user( 0 );

		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule' => array(
						'wed' => array(
							array(
								'start' => '10:00',
								'end'   => '11:00',
							),
						),
					),
				)
			)
		);

		$row = null;
		foreach ( ( new AuditLogRepository( $this->health ) )->recent( 20 ) as $candidate ) {
			if ( 'availability.schedule_updated' === $candidate['action'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'system', $row['actor_type'] );
		$this->assertSame( 0, (int) $row['actor_id'] );
	}

	/**
	 * With a logged-in operator, the same events must record `operator` and
	 * the real user id, not `system`.
	 */
	public function test_schedule_change_with_a_logged_in_operator_is_audited_as_operator(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule' => array(
						'thu' => array(
							array(
								'start' => '10:00',
								'end'   => '11:00',
							),
						),
					),
				)
			)
		);

		$row = null;
		foreach ( ( new AuditLogRepository( $this->health ) )->recent( 20 ) as $candidate ) {
			if ( 'availability.schedule_updated' === $candidate['action'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'operator', $row['actor_type'] );
		$this->assertSame( $user_id, (int) $row['actor_id'] );
	}

	public function test_a_save_that_does_not_touch_availability_is_not_audited(): void {
		// A schedule change of its own is legitimately audited once here —
		// captured as the "before" baseline so the assertion below is about
		// the *next* save, not this seeding step.
		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule' => array(
						'mon' => array(
							array(
								'start' => '08:00',
								'end'   => '09:00',
							),
						),
					),
				)
			)
		);
		$before = self::count_of( 'availability.schedule_updated', $this->recent_audit_actions() );

		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize( array( 'widget_enabled' => '0' ) )
		);

		$after = self::count_of( 'availability.schedule_updated', $this->recent_audit_actions() );
		$this->assertSame( $before, $after, 'a save that does not touch availability must not add a new schedule_updated event' );
		$this->assertSame( 0, self::count_of( 'availability.exceptions_updated', $this->recent_audit_actions() ) );
	}

	public function test_a_rejected_availability_save_is_not_audited(): void {
		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule' => array(
						'tue' => array(
							array(
								'start' => '09:00',
								'end'   => '10:00',
							),
						),
					),
				)
			)
		);
		// Baseline AFTER the one legitimate save above — this is what the
		// rejected save below must not add to.
		$before = self::count_of( 'availability.schedule_updated', $this->recent_audit_actions() );

		update_option(
			Settings::OPTION_NAME,
			$this->settings->sanitize(
				array(
					'availability_schedule' => array(
						'tue' => array(
							array(
								'start' => '10:00',
								'end'   => '09:00',
							),
						),
					),
				)
			)
		);

		$after = self::count_of( 'availability.schedule_updated', $this->recent_audit_actions() );
		$this->assertSame( $before, $after, 'a rejected (invalid) submission must not be audited as a change' );
	}

	/**
	 * @param array<int, string> $actions Action list.
	 */
	private static function count_of( string $action, array $actions ): int {
		return count(
			array_filter(
				$actions,
				static fn( string $recorded ) => $recorded === $action
			)
		);
	}

	public function test_exception_rows_are_converted_to_the_canonical_date_map(): void {
		$result = $this->settings->sanitize(
			array(
				'availability_exceptions' => array(
					array(
						'date'  => '2026-12-24',
						'mode'  => 'closed',
						'start' => '',
						'end'   => '',
					),
					array(
						'date'  => '2026-12-31',
						'mode'  => 'hours',
						'start' => '10:00',
						'end'   => '12:00',
					),
					array(
						'date'  => '',
						'mode'  => 'closed',
						'start' => '',
						'end'   => '',
					),
				),
			)
		);

		$this->assertSame( 'closed', $result['availability_exceptions']['2026-12-24'] );
		$this->assertSame(
			array(
				array(
					'start' => '10:00',
					'end'   => '12:00',
				),
			),
			$result['availability_exceptions']['2026-12-31']
		);
		$this->assertArrayNotHasKey( '', $result['availability_exceptions'] );
	}

	// ---- WP4: override action ----

	private function run_override_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness sets a real nonce for the action under test.
		$_REQUEST['_wpnonce'] = wp_create_nonce( OverrideAction::NONCE );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		$catch = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );

		try {
			( new OverrideAction( null ) )->handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
	}

	public function test_override_action_requires_manage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['override_op']   = 'set';
		$_POST['override_mode'] = AvailabilityOverride::MODE_FORCE_OFFLINE;
		$_REQUEST['_wpnonce']   = wp_create_nonce( OverrideAction::NONCE );

		$this->expectException( \WPDieException::class );
		( new OverrideAction( null ) )->handle();
	}

	public function test_override_set_then_clear_returns_to_automatic(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['override_op']   = 'set';
		$_POST['override_mode'] = AvailabilityOverride::MODE_FORCE_OFFLINE;
		$this->run_override_action();

		$this->assertSame( AvailabilityOverride::MODE_FORCE_OFFLINE, $this->availability()->current_mode() );

		$_POST = array( 'override_op' => 'clear' );
		$this->run_override_action();

		$this->assertSame( AvailabilityService::MODE_AUTOMATIC, $this->availability()->current_mode() );
		$this->assertFalse( get_option( AvailabilityService::OVERRIDE_OPTION, false ) );
	}

	public function test_override_rejects_a_past_expiry(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['override_op']         = 'set';
		$_POST['override_mode']       = AvailabilityOverride::MODE_FORCE_ONLINE;
		$_POST['override_expires_at'] = gmdate( 'Y-m-d\TH:i', time() - 3600 );
		$this->run_override_action();

		$this->assertFalse( get_option( AvailabilityService::OVERRIDE_OPTION, false ) );
	}

	// ---- WP6: Hub Waiting view ----

	public function test_hub_waiting_view_membership_and_order(): void {
		$conversations = new ConversationRepository( $this->health );
		$user          = self::factory()->user->create();

		$new  = $conversations->create( $user );
		$open = $conversations->create( $user );
		$conversations->transition( $open, ConversationStatus::OPEN );
		$waiting_old = $conversations->create( $user );
		$conversations->transition( $conversations->transition( $waiting_old, ConversationStatus::OPEN ), ConversationStatus::WAITING_FOR_OPERATOR );
		$waiting_new = $conversations->create( $user );
		$conversations->transition( $conversations->transition( $waiting_new, ConversationStatus::OPEN ), ConversationStatus::WAITING_FOR_OPERATOR );

		// Age the "old" waiting row so ascending order is observable.
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture, fixed table name.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET updated_at = %s WHERE id = %d", '2000-01-01 00:00:00', $waiting_old->id() ) );

		$result = $conversations->list_waiting( 1, 20 );
		$ids    = array_map( static fn( $c ) => $c->id(), $result['items'] );

		$this->assertContains( $waiting_old->id(), $ids );
		$this->assertContains( $waiting_new->id(), $ids );
		$this->assertContains( $new->id(), $ids, 'legacy new rows are a documented transitional inclusion' );
		$this->assertNotContains( $open->id(), $ids );
		$this->assertSame( $waiting_old->id(), $ids[0], 'oldest waiting first' );
	}

	// ---- WP7: Diagnostics availability block ----

	private function diagnostics_html(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new DiagnosticsPage(
			$this->health,
			new AuditLogRepository( $this->health ),
			new CredentialVault(),
			$this->settings,
			new \UniversalSupportChat\ChannelContract\Auth\PeerRepository( $this->health ),
			new DispatchOutboxRepository( $this->health ),
			$this->availability()
		);

		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_diagnostics_shows_availability_aggregates_and_no_schedule_contents(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION_NAME );
		update_option(
			Settings::OPTION_NAME,
			array(
				'availability_schedule' => array(
					'mon' => array(
						array(
							'start' => '13:37',
							'end'   => '14:37',
						),
					),
				),
			)
				+ $this->settings->defaults()
		);
		update_option(
			AvailabilityService::OVERRIDE_OPTION,
			array(
				'mode'       => AvailabilityOverride::MODE_FORCE_OFFLINE,
				'expires_at' => null,
				'set_by'     => 1,
				'set_at'     => time(),
			)
		);

		$html = $this->diagnostics_html();

		$this->assertStringContainsString( 'Availability', $html );
		$this->assertStringContainsString( 'until cleared', $html );
		$this->assertStringContainsString( 'schedule config valid', $html );
		// Redaction: the actual scheduled times never appear on Diagnostics.
		$this->assertStringNotContainsString( '13:37', $html );
	}

	public function test_diagnostics_warns_when_schedule_config_is_invalid(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION_NAME );
		update_option(
			Settings::OPTION_NAME,
			array(
				'availability_schedule' => array(
					'mon' => array(
						array(
							'start' => '40:00',
							'end'   => '41:00',
						),
					),
				),
			)
				+ $this->settings->defaults()
		);

		$this->assertStringContainsString( 'fail-safe', $this->diagnostics_html() );
	}
}
