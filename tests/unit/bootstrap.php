<?php
/**
 * Unit-test bootstrap.
 *
 * @package UniversalSupportChat
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'UNIVERSAL_SUPPORT_CHAT_CREDENTIAL_KEY', str_repeat( 'ab', 32 ) );

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal unit-test polyfill. Real WordPress installs (and the
	 * integration test suite) always provide the real implementation;
	 * this exists only so pure-PHP unit tests can exercise code paths
	 * that call it without bootstrapping WordPress.
	 *
	 * @param mixed $data Data to encode.
	 *
	 * @return string|false
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill of a fixed WordPress core function name.
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit-test-only polyfill.
	}
}
