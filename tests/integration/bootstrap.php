<?php
/**
 * Integration-test bootstrap.
 *
 * @package UniversalSupportChat
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests/bin/install-wp.sh <wp-version> and source /tmp/usc-wp-env.sh first.\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin under test.
 */
function universal_support_chat_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/universal-support-chat.php';
}
tests_add_filter( 'muplugins_loaded', 'universal_support_chat_manually_load_plugin' );

require $wp_tests_dir . '/includes/bootstrap.php';
