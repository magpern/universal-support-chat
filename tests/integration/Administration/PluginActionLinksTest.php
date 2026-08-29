<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Administration\PluginActionLinks;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

/**
 * Plugins-screen "Conversations" / "Settings" quick links — navigation only.
 */
final class PluginActionLinksTest extends WP_UnitTestCase {

	private PluginActionLinks $links;
	private string $plugin_file;

	public function set_up(): void {
		parent::set_up();

		( new CapabilityRegistrar() )->grant_to_administrator();

		$this->plugin_file = WP_PLUGIN_DIR . '/universal-support-chat/universal-support-chat.php';
		$this->links       = new PluginActionLinks( $this->plugin_file );
	}

	private function as_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @return array<int|string, string>
	 */
	private function default_links(): array {
		return array(
			'deactivate' => '<a href="#" class="usc-deactivate">Deactivate</a>',
		);
	}

	public function test_register_hooks_the_row_specific_filter(): void {
		$this->links->register();

		$this->assertSame(
			'plugin_action_links_' . plugin_basename( $this->plugin_file ),
			$this->links->hook_name()
		);
		$this->assertStringStartsWith( 'plugin_action_links_', $this->links->hook_name() );
		$this->assertNotFalse( has_filter( $this->links->hook_name(), array( $this->links, 'add_links' ) ) );
	}

	public function test_links_are_prepended_before_the_default_deactivate_link(): void {
		$this->as_manager();

		$result = array_values( $this->links->add_links( $this->default_links() ) );

		$this->assertCount( 3, $result );
		$this->assertStringContainsString( '>Conversations<', $result[0] );
		$this->assertStringContainsString( '>Settings<', $result[1] );
		$this->assertStringContainsString( '>Deactivate<', $result[2] );
	}

	public function test_conversations_link_targets_the_existing_hub_page(): void {
		$this->as_manager();

		$result   = $this->links->add_links( $this->default_links() );
		$expected = admin_url( 'admin.php?page=' . HubPage::SLUG );

		$this->assertSame( 'universal-support-chat-hub', HubPage::SLUG );
		$this->assertStringContainsString( 'href="' . esc_url( $expected ) . '"', $result['usc-conversations'] );
	}

	public function test_settings_link_targets_the_existing_settings_menu_page(): void {
		$this->as_manager();

		$result   = $this->links->add_links( $this->default_links() );
		$expected = admin_url( 'options-general.php?page=' . DiagnosticsPage::SLUG );

		$this->assertSame( 'universal-support-chat', DiagnosticsPage::SLUG );
		$this->assertStringContainsString( 'href="' . esc_url( $expected ) . '"', $result['usc-settings'] );
	}

	public function test_links_are_hidden_without_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->links->add_links( $this->default_links() );

		$this->assertSame( $this->default_links(), $result );
	}

	public function test_non_array_input_is_handled_safely(): void {
		$this->as_manager();

		$this->assertSame( array(), $this->links->add_links( null ) );
	}

	public function test_filter_runs_end_to_end_for_this_plugin_row(): void {
		$this->as_manager();
		$this->links->register();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WordPress core hook `plugin_action_links_{basename}`.
		$result = array_values( apply_filters( $this->links->hook_name(), $this->default_links() ) );

		$this->assertStringContainsString( '>Conversations<', $result[0] );
		$this->assertStringContainsString( '>Settings<', $result[1] );
		$this->assertStringContainsString( '>Deactivate<', $result[2] );
	}
}
