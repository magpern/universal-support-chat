<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration\Compat;

use UniversalSupportChat\Administration\Compat\LegacySettingsRedirect;
use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

/**
 * ADR-0015 §4: the legacy `options-general.php?page=universal-support-chat`
 * URL keeps working via a capability-checked 302 to the Diagnostics page.
 * Primary coverage is the pure `resolve_target()`.
 */
final class LegacySettingsRedirectTest extends WP_UnitTestCase {

	private LegacySettingsRedirect $redirect;

	public function set_up(): void {
		parent::set_up();

		( new CapabilityRegistrar() )->grant_to_administrator();
		$this->redirect = new LegacySettingsRedirect();
	}

	public function tear_down(): void {
		unset( $_GET['page'], $GLOBALS['pagenow'] );
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	private function as_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	// ---- pure resolve_target() ----

	public function test_authorised_legacy_url_resolves_to_the_diagnostics_page(): void {
		$this->as_manager();

		$this->assertSame(
			admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG ),
			$this->redirect->resolve_target( 'options-general.php', LegacySettingsRedirect::LEGACY_SLUG )
		);
	}

	public function test_unauthorised_user_gets_no_target(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertNull(
			$this->redirect->resolve_target( 'options-general.php', LegacySettingsRedirect::LEGACY_SLUG )
		);
	}

	public function test_unrelated_requests_get_no_target(): void {
		$this->as_manager();

		$this->assertNull( $this->redirect->resolve_target( 'options-general.php', 'some-other-plugin' ) );
		$this->assertNull( $this->redirect->resolve_target( 'plugins.php', LegacySettingsRedirect::LEGACY_SLUG ) );
		$this->assertNull( $this->redirect->resolve_target( 'admin.php', LegacySettingsRedirect::LEGACY_SLUG ) );
		$this->assertNull( $this->redirect->resolve_target( '', '' ) );
	}

	public function test_the_redirect_target_request_itself_resolves_to_nothing(): void {
		$this->as_manager();

		// The destination is admin.php?page=universal-support-chat-diagnostics.
		$this->assertNull( $this->redirect->resolve_target( 'admin.php', DiagnosticsPage::SLUG ) );
		$this->assertNull( $this->redirect->resolve_target( 'admin.php', SupportChatSettingsPage::SLUG ) );
	}

	public function test_legacy_slug_constant_and_that_it_is_not_a_registered_page(): void {
		$this->assertSame( 'universal-support-chat', LegacySettingsRedirect::LEGACY_SLUG );
		$this->assertNotSame( LegacySettingsRedirect::LEGACY_SLUG, DiagnosticsPage::SLUG );
		$this->assertNotSame( LegacySettingsRedirect::LEGACY_SLUG, SupportChatSettingsPage::SLUG );

		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation of a core global.
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing a WordPress core hook.

		$all_slugs = array();
		foreach ( (array) $submenu as $children ) {
			foreach ( $children as $child ) {
				$all_slugs[] = $child[2];
			}
		}

		$this->assertNotContains( LegacySettingsRedirect::LEGACY_SLUG, $all_slugs );
	}

	// ---- thin maybe_redirect() wrapper ----

	public function test_wrapper_issues_a_302_to_the_target_and_does_not_exit_when_redirect_returns_false(): void {
		$this->as_manager();

		$captured = array();
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$captured ) {
				$captured = array( $location, $status );

				return false; // makes wp_safe_redirect() return false: no header, no exit.
			},
			10,
			2
		);

		$GLOBALS['pagenow'] = 'options-general.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating the admin request context for a core global.
		$_GET['page']       = LegacySettingsRedirect::LEGACY_SLUG;

		$this->redirect->maybe_redirect();
		$reached_after_call = true; // proof the wrapper returned instead of exiting.

		$this->assertTrue( $reached_after_call );
		$this->assertSame(
			array( admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG ), 302 ),
			$captured
		);
	}

	public function test_wrapper_is_a_noop_for_an_unrelated_request(): void {
		$this->as_manager();

		$called = false;
		add_filter(
			'wp_redirect',
			static function () use ( &$called ) {
				$called = true;

				return false;
			},
			10,
			1
		);

		$GLOBALS['pagenow'] = 'plugins.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating the admin request context for a core global.
		$_GET['page']       = 'something-else';

		$this->redirect->maybe_redirect();

		$this->assertFalse( $called );
	}
}
