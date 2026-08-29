<?php
/**
 * Plugins-screen action links for Universal Support Chat.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Adds "Conversations" and "Settings" quick links to this plugin's row on the
 * WordPress Plugins screen, ahead of the default "Deactivate" link. Pure
 * navigation to already-registered admin pages — no new route, menu, option,
 * or runtime behaviour.
 */
final class PluginActionLinks {

	/**
	 * Absolute path to the main plugin file (for `plugin_basename()`).
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Registers the filter for this plugin's row only.
	 */
	public function register(): void {
		add_filter( $this->hook_name(), array( $this, 'add_links' ) );
	}

	/**
	 * The row-specific filter hook name for this plugin.
	 */
	public function hook_name(): string {
		return 'plugin_action_links_' . plugin_basename( $this->plugin_file );
	}

	/**
	 * Prepends the "Conversations" and "Settings" links.
	 *
	 * @param mixed $links Existing action links (associative or list array).
	 *
	 * @return array<int|string, string> Links with ours prepended, unchanged input otherwise.
	 */
	public function add_links( $links ): array {
		if ( ! is_array( $links ) ) {
			return array();
		}

		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return $links;
		}

		$ours = array(
			'usc-conversations' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG ) ),
				esc_html__( 'Conversations', 'universal-support-chat' )
			),
			'usc-settings'      => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . DiagnosticsPage::SLUG ) ),
				esc_html__( 'Settings', 'universal-support-chat' )
			),
		);

		return array_merge( $ours, $links );
	}
}
