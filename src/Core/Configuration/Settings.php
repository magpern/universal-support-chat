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
	 * Maximum stored length of the widget title (ADR-0016).
	 */
	private const WIDGET_TITLE_MAX = 80;

	/**
	 * Maximum stored length of the widget greeting (ADR-0016).
	 */
	private const WIDGET_GREETING_MAX = 500;

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
	 *   conversation_purge_days: int,
	 *   widget_enabled: bool,
	 *   telegram_dispatch_enabled: bool,
	 *   widget_title: string,
	 *   widget_greeting: string,
	 *   widget_avatar_attachment_id: int
	 * }
	 */
	public function defaults(): array {
		return array(
			'remove_data_on_uninstall'        => false,
			'conversation_inactive_days'      => 30,
			'conversation_archived_body_days' => 30,
			'conversation_purge_days'         => 90,
			'widget_enabled'                  => true,
			'telegram_dispatch_enabled'       => false,
			'widget_title'                    => '',
			'widget_greeting'                 => __( 'Hi — how can we help?', 'universal-support-chat' ),
			'widget_avatar_attachment_id'     => 0,
		);
	}

	/**
	 * Returns sanitized settings merged with defaults.
	 *
	 * @return array{
	 *   remove_data_on_uninstall: bool,
	 *   conversation_inactive_days: int,
	 *   conversation_archived_body_days: int,
	 *   conversation_purge_days: int,
	 *   widget_enabled: bool,
	 *   telegram_dispatch_enabled: bool,
	 *   widget_title: string,
	 *   widget_greeting: string,
	 *   widget_avatar_attachment_id: int
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
	 *   conversation_purge_days: int,
	 *   widget_enabled: bool,
	 *   telegram_dispatch_enabled: bool,
	 *   widget_title: string,
	 *   widget_greeting: string,
	 *   widget_avatar_attachment_id: int
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
			'widget_enabled'                  => array_key_exists( 'widget_enabled', $input )
				? ! empty( $input['widget_enabled'] )
				: $defaults['widget_enabled'],
			'telegram_dispatch_enabled'       => array_key_exists( 'telegram_dispatch_enabled', $input )
				? ! empty( $input['telegram_dispatch_enabled'] )
				: $defaults['telegram_dispatch_enabled'],
			'widget_title'                    => array_key_exists( 'widget_title', $input )
				? $this->plain_text( $input['widget_title'], self::WIDGET_TITLE_MAX )
				: $defaults['widget_title'],
			'widget_greeting'                 => array_key_exists( 'widget_greeting', $input )
				? $this->plain_multiline_text( $input['widget_greeting'], self::WIDGET_GREETING_MAX )
				: $defaults['widget_greeting'],
			'widget_avatar_attachment_id'     => array_key_exists( 'widget_avatar_attachment_id', $input )
				? $this->image_attachment_id( $input['widget_avatar_attachment_id'] )
				: $defaults['widget_avatar_attachment_id'],
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

	/**
	 * Sanitizes operator-authored plain text (ADR-0016): tags stripped,
	 * single-line, truncated to a hard character cap. Never HTML.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum character length.
	 */
	private function plain_text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = sanitize_text_field( (string) $value );

		return mb_substr( $clean, 0, $max );
	}

	/**
	 * Sanitizes operator-authored plain multiline text (ADR-0016): tags
	 * stripped, newlines preserved, truncated to a hard character cap.
	 * Never HTML.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum character length.
	 */
	private function plain_multiline_text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = sanitize_textarea_field( (string) $value );

		return mb_substr( $clean, 0, $max );
	}

	/**
	 * Validates a Media Library image attachment id (ADR-0016). Any
	 * non-image, unknown, or non-positive value becomes `0` (no avatar).
	 * Server-side validation is authoritative regardless of how the value
	 * was submitted.
	 *
	 * @param mixed $value Raw value.
	 */
	private function image_attachment_id( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$id = absint( $value );

		if ( $id <= 0 ) {
			return 0;
		}

		return wp_attachment_is_image( $id ) ? $id : 0;
	}
}
