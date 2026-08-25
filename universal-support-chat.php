<?php
/**
 * Plugin Name:       Universal Support Chat
 * Plugin URI:        https://github.com/magpern/universal-support-chat
 * Description:       Self-contained WordPress support-chat foundation: conversations, Hub inbox, and optional channel adapters in later milestones.
 * Version:           0.0.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Magnus Pernemark
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-support-chat
 *
 * @package UniversalSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.0.1' );
define( 'UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'plugins_loaded',
	static function () {
		\UniversalSupportChat\Core\Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		( new \UniversalSupportChat\Core\Lifecycle\Activator() )->activate( (bool) $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		( new \UniversalSupportChat\Core\Lifecycle\Deactivator() )->deactivate();
	}
);
