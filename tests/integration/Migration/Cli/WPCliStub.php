<?php
/**
 * A minimal, test-only stand-in for the real \WP_CLI class, which this
 * repository's integration test environment never loads (there is no real
 * WP-CLI binary running these tests). Records every call so
 * LegacyMigrateCommandTest can assert on the operator-facing output
 * without needing a real WP-CLI process.
 *
 * @package UniversalSupportChat
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- test-only stand-in for a fixed, external, un-prefixed class name.

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Test double for \WP_CLI.
	 */
	class WP_CLI {

		/**
		 * @var array<int, array{method: string, message: string}>
		 */
		public static array $calls = array();

		/**
		 * Resets recorded calls between tests.
		 */
		public static function reset(): void {
			self::$calls = array();
		}

		/**
		 * @param string $message Error message.
		 */
		public static function error( string $message ): void {
			self::$calls[] = array(
				'method'  => 'error',
				'message' => $message,
			);
		}

		/**
		 * @param string $message Success message.
		 */
		public static function success( string $message ): void {
			self::$calls[] = array(
				'method'  => 'success',
				'message' => $message,
			);
		}

		/**
		 * @param string $message Log message.
		 */
		public static function log( string $message ): void {
			self::$calls[] = array(
				'method'  => 'log',
				'message' => $message,
			);
		}

		/**
		 * @param string $message Warning message.
		 */
		public static function warning( string $message ): void {
			self::$calls[] = array(
				'method'  => 'warning',
				'message' => $message,
			);
		}

		/**
		 * @param string   $name             Command name.
		 * @param callable $command_callback Command callback (unused by this stub — no test dispatches through it).
		 */
		public static function add_command( string $name, $command_callback ): void {
			unset( $command_callback );

			self::$calls[] = array(
				'method'  => 'add_command',
				'message' => $name,
			);
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
