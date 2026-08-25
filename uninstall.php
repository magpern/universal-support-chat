<?php
/**
 * Uninstall routine.
 *
 * @package UniversalSupportChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

( new UniversalSupportChat\Core\Lifecycle\Uninstaller() )->run();
