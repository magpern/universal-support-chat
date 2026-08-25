<?php
/**
 * Plugin settings.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Configuration;

/**
 * Sole owner of the universal_support_chat_settings option.
 */
final class Settings {

	public const OPTION_NAME  = 'universal_support_chat_settings';
	public const OPTION_GROUP = 'universal_support_chat_settings_group';

	/**
	 * Registers the option with the Settings API.
	 */
	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Pure defaults.
	 *
	 * @return array{
	 *   remove_data_on_uninstall: bool,
	 *   conversation_inactive_days: int,
	 *   conversation_archived_body_days: int,
	 *   conversation_purge_days: int
	 * }
	 */
	public function defaults(): array {
		return array(
			'remove_data_on_uninstall'        => false,
			'conversation_inactive_days'      => 30,
			'conversation_archived_body_days' => 30,
			'conversation_purge_days'         => 90,
		);
	}

	/**
	 * Returns sanitized settings merged with defaults.
	 *
	 * @return array{
	 *   remove_data_on_uninstall: bool,
	 *   conversation_inactive_days: int,
	 *   conversation_archived_body_days: int,
	 *   conversation_purge_days: int
	 * }
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->sanitize( $stored );
	}

	/**
	 * Sanitizes settings input.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array{
	 *   remove_data_on_uninstall: bool,
	 *   conversation_inactive_days: int,
	 *   conversation_archived_body_days: int,
	 *   conversation_purge_days: int
	 * }
	 */
	public function sanitize( $input ): array {
		$defaults = $this->defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		return array(
			'remove_data_on_uninstall'        => ! empty( $input['remove_data_on_uninstall'] ),
			'conversation_inactive_days'      => $this->positive_int( $input['conversation_inactive_days'] ?? null, $defaults['conversation_inactive_days'] ),
			'conversation_archived_body_days' => $this->positive_int( $input['conversation_archived_body_days'] ?? null, $defaults['conversation_archived_body_days'] ),
			'conversation_purge_days'         => $this->positive_int( $input['conversation_purge_days'] ?? null, $defaults['conversation_purge_days'] ),
		);
	}

	/**
	 * Coerces a positive integer setting.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Default when invalid.
	 */
	private function positive_int( $value, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		$int = (int) $value;

		return $int > 0 ? $int : $fallback;
	}
}
