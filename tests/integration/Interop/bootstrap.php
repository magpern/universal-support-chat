<?php
/**
 * Cross-plugin interoperability bootstrap: loads BOTH
 * universal-support-chat (this checkout) and universal-telegram (the real
 * sibling checkout, linked into wp-content/plugins by
 * tests/bin/install-universal-telegram.sh) as "MU plugins" for the
 * WordPress test framework, so the interop suite exercises real
 * `LegacyExportServiceV1` (Universal Telegram, ADR-0008) code against this
 * repository's real migration engine in one disposable WordPress install.
 *
 * @package UniversalSupportChat
 */

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests/bin/install-wp.sh <wp-version>, source /tmp/usc-wp-env.sh, then tests/bin/install-universal-telegram.sh first.\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Loads both plugins under test. Universal Telegram migrates its own
 * schema and boots `LegacyExportServiceV1` on its own `plugins_loaded`
 * hook — no separate installer call is needed, mirroring how the
 * Universal Telegram repository's own interop bootstrap treats this
 * repository.
 */
function universal_support_chat_interop_manually_load_plugins() {
	$ut_main = WP_PLUGIN_DIR . '/universal-telegram/universal-telegram.php';

	if ( ! file_exists( $ut_main ) ) {
		fwrite( STDERR, "universal-telegram is not linked into wp-content/plugins. Run tests/bin/install-universal-telegram.sh first.\n" );
		exit( 1 );
	}

	// Universal Telegram's own composer autoloader (UniversalTelegram\\...),
	// loaded alongside this plugin's autoloader (UniversalSupportChat\\...)
	// — two independent PSR-4 autoloaders coexist without collision.
	require WP_PLUGIN_DIR . '/universal-telegram/vendor/autoload.php';
	require $ut_main;

	require dirname( __DIR__, 3 ) . '/universal-support-chat.php';
}
tests_add_filter( 'muplugins_loaded', 'universal_support_chat_interop_manually_load_plugins' );

require $wp_tests_dir . '/includes/bootstrap.php';
