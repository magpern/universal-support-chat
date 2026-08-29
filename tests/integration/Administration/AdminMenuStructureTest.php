<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

/**
 * ADR-0015 §1: Conversations, Settings, and Diagnostics are the three
 * submenus of the existing top-level Support Chat menu. No new top-level
 * menu is added. The plugin is already booted by the test bootstrap, so
 * this fires `admin_menu` once against clean menu globals and inspects the
 * result.
 */
final class AdminMenuStructureTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->build_menu();
	}

	private function build_menu(): void {
		global $menu, $submenu;

		// Reset WordPress's own menu registries so a single admin_menu pass
		// can be inspected in isolation.
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation of a core global.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation of a core global.

		set_current_screen( 'dashboard' );
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing a WordPress core hook.
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	private function top_level(): array {
		global $menu;

		return array_values( array_filter( (array) $menu, 'is_array' ) );
	}

	public function test_exactly_one_support_chat_top_level_menu(): void {
		$slugs = array_column( $this->top_level(), 2 );

		$this->assertContains( HubPage::SLUG, $slugs );
		$this->assertSame( 1, count( array_keys( $slugs, HubPage::SLUG, true ) ) );
	}

	public function test_no_new_top_level_menu_for_settings_or_diagnostics(): void {
		$slugs = array_column( $this->top_level(), 2 );

		$this->assertNotContains( SupportChatSettingsPage::SLUG, $slugs );
		$this->assertNotContains( DiagnosticsPage::SLUG, $slugs );
		$this->assertNotContains( 'universal-support-chat', $slugs, 'the retired options-general slug is not a top-level menu' );
	}

	public function test_support_chat_menu_has_conversations_settings_diagnostics_submenus(): void {
		global $submenu;

		$this->assertArrayHasKey( HubPage::SLUG, $submenu );

		$children = array_column( $submenu[ HubPage::SLUG ], 2 );

		$this->assertSame(
			array( HubPage::SLUG, SupportChatSettingsPage::SLUG, DiagnosticsPage::SLUG ),
			$children,
			'submenu order is Conversations, Settings, Diagnostics'
		);
	}

	public function test_first_submenu_is_labelled_conversations(): void {
		global $submenu;

		$first = $submenu[ HubPage::SLUG ][0];

		$this->assertSame( 'Conversations', $first[0] );
		$this->assertSame( HubPage::SLUG, $first[2] );
	}

	public function test_settings_and_diagnostics_submenus_require_manage_capability(): void {
		global $submenu;

		foreach ( $submenu[ HubPage::SLUG ] as $entry ) {
			if ( in_array( $entry[2], array( SupportChatSettingsPage::SLUG, DiagnosticsPage::SLUG ), true ) ) {
				$this->assertSame( CapabilityRegistrar::MANAGE, $entry[1] );
			}
		}
	}

	public function test_legacy_options_general_slug_is_not_registered(): void {
		global $submenu;

		$options_children = isset( $submenu['options-general.php'] ) ? array_column( $submenu['options-general.php'], 2 ) : array();

		$this->assertNotContains( 'universal-support-chat', $options_children );
	}

	public function test_settings_page_capability_filter_is_registered(): void {
		$this->assertNotFalse( has_filter( 'option_page_capability_universal_support_chat_settings_group' ) );
		$this->assertSame(
			CapabilityRegistrar::MANAGE,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook `option_page_capability_{group}` with our prefixed group.
			apply_filters( 'option_page_capability_universal_support_chat_settings_group', 'manage_options' )
		);
	}
}
