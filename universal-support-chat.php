<?php
/**
 * Plugin Name:       Universal Support Chat
 * Plugin URI:        https://github.com/magpern/universal-support-chat
 * Description:       Self-contained WordPress support-chat foundation: conversations, Hub inbox, and optional channel adapters in later milestones.
 * Version:           0.9.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            magpern
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-support-chat
 *
 * @package UniversalSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.9.1' );
define( 'UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Automatic updates via the private update server. Define PRIVATE_UPDATE_SERVER
 * (scheme + host, no trailing slash) in wp-config.php to enable; when it is not
 * defined the plugin does not check for updates.
 */
if ( defined( 'PRIVATE_UPDATE_SERVER' ) && PRIVATE_UPDATE_SERVER && class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		rtrim( (string) PRIVATE_UPDATE_SERVER, '/' ) . '/?action=get_metadata&slug=universal-support-chat',
		UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE,
		'universal-support-chat'
	);
}

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
