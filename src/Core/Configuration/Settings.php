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
	 * @return array{remove_data_on_uninstall: bool}
	 */
	public function defaults(): array {
		return array(
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Returns sanitized settings merged with defaults.
	 *
	 * @return array{remove_data_on_uninstall: bool}
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
	 * @return array{remove_data_on_uninstall: bool}
	 */
	public function sanitize( $input ): array {
		$defaults = $this->defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		return array(
			'remove_data_on_uninstall' => ! empty( $input['remove_data_on_uninstall'] ),
		);
	}
}
