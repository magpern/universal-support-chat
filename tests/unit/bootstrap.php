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

/*
 * Minimal unit-test polyfills of fixed WordPress core functions. Real
 * WordPress installs (and the integration test suite) always provide the
 * real implementations; these exist only so pure-PHP unit tests can
 * exercise code paths that call them without bootstrapping WordPress.
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.textFound -- unit-test-only polyfill of a fixed WordPress core function name.
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill.
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill.
	function sanitize_textarea_field( $str ) {
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill.
	function wp_strip_all_tags( $str ) {
		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
		return trim( (string) wp_strip_tags_simple( $str ) );
	}
}

if ( ! function_exists( 'wp_strip_tags_simple' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill.
	function wp_strip_tags_simple( $str ) {
		return strip_tags( (string) $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}

if ( ! function_exists( 'absint' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill.
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- unit-test-only polyfill (no Media Library in unit context).
	function wp_attachment_is_image( $post = 0 ) {
		unset( $post );
		return false;
	}
}
