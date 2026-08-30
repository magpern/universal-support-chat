<?php
/**
 * Plugin settings.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Configuration;

use UniversalSupportChat\Availability\ExceptionSet;
use UniversalSupportChat\Availability\InvalidScheduleException;
use UniversalSupportChat\Availability\WeeklySchedule;

/**
 * Sole owner of the universal_support_chat_settings option.
 */
final class Settings {

	public const OPTION_NAME  = 'universal_support_chat_settings';
	public const OPTION_GROUP = 'universal_support_chat_settings_group';

	/**
	 * Maximum stored length of the availability offline message (ADR-0017).
	 */
	private const OFFLINE_MESSAGE_MAX = 500;

	/**
	 * Default availability offline message (ADR-0017). Plain literal for the
	 * same reason as {@see self::DEFAULT_WIDGET_GREETING}; translated where
	 * rendered.
	 */
	public const DEFAULT_OFFLINE_MESSAGE = "The support team is offline right now. Leave your message here and we'll reply in this chat when we're back.";

	/**
	 * Maximum stored length of the widget title (ADR-0016).
	 */
	private const WIDGET_TITLE_MAX = 80;

	/**
	 * Maximum stored length of the widget greeting (ADR-0016).
	 */
	private const WIDGET_GREETING_MAX = 500;

	/**
	 * Default widget greeting (ADR-0016). Kept as a plain literal — like
	 * every other default in this class — because `register()` runs on
	 * `plugins_loaded`, before translations may be loaded; the string is
	 * still translator-visible where it is rendered.
	 */
	public const DEFAULT_WIDGET_GREETING = 'Hi — how can we help?';

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
	 *   widget_avatar_attachment_id: int,
	 *   availability_schedule: array<string, array<int, array{start: string, end: string}>>,
	 *   availability_exceptions: array<string, string|array<int, array{start: string, end: string}>>,
	 *   availability_offline_message: string,
	 *   availability_online_indicator: bool
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
			'widget_greeting'                 => self::DEFAULT_WIDGET_GREETING,
			'widget_avatar_attachment_id'     => 0,
			'availability_schedule'           => WeeklySchedule::default_schedule()->to_array(),
			'availability_exceptions'         => array(),
			'availability_offline_message'    => self::DEFAULT_OFFLINE_MESSAGE,
			'availability_online_indicator'   => true,
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
	 *   widget_avatar_attachment_id: int,
	 *   availability_schedule: array<string, array<int, array{start: string, end: string}>>,
	 *   availability_exceptions: array<string, string|array<int, array{start: string, end: string}>>,
	 *   availability_offline_message: string,
	 *   availability_online_indicator: bool
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
	 *   widget_avatar_attachment_id: int,
	 *   availability_schedule: array<string, array<int, array{start: string, end: string}>>,
	 *   availability_exceptions: array<string, string|array<int, array{start: string, end: string}>>,
	 *   availability_offline_message: string,
	 *   availability_online_indicator: bool
	 * }
	 */
	public function sanitize( $input ): array {
		$defaults = $this->defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		// The previously stored option, used to preserve a valid availability
		// config when a new submission is rejected (plan v2 §6). Guarded so
		// the pure-PHP unit suite can still exercise `sanitize()` with no
		// WordPress loaded.
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();

		// The schedule + exceptions are validated as ONE all-or-nothing
		// Availability section (ADR-0017; plan v2 §6): if either fails, both
		// prior values are preserved and one settings error is raised.
		$availability = $this->sanitize_availability_section( $input, $stored, $defaults );

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
			'availability_schedule'           => $availability['schedule'],
			'availability_exceptions'         => $availability['exceptions'],
			'availability_offline_message'    => array_key_exists( 'availability_offline_message', $input )
				? $this->offline_message( $input['availability_offline_message'], $defaults['availability_offline_message'] )
				: $defaults['availability_offline_message'],
			'availability_online_indicator'   => array_key_exists( 'availability_online_indicator', $input )
				? ! empty( $input['availability_online_indicator'] )
				: $defaults['availability_online_indicator'],
		);
	}

	/**
	 * All-or-nothing validation of the Availability section — the weekly
	 * schedule and the date exceptions together (ADR-0017; plan v2 §6).
	 *
	 * If EITHER the schedule or the exceptions fails to parse, BOTH prior
	 * values are preserved and exactly one `settings_error` is registered —
	 * a partial save (new exceptions kept, old schedule kept, or vice versa)
	 * is impossible. A section identical to what is stored (e.g. the reparse
	 * {@see self::get()} performs) passes straight through untouched, so
	 * runtime corruption is surfaced by `AvailabilityService`'s own strict
	 * parse rather than being silently reset here.
	 *
	 * @param array<string, mixed> $input    Full submitted array.
	 * @param array<string, mixed> $stored   Current stored option array.
	 * @param array<string, mixed> $defaults Default option array.
	 *
	 * @return array{schedule: mixed, exceptions: mixed}
	 */
	private function sanitize_availability_section( array $input, array $stored, array $defaults ): array {
		$current_schedule   = ( isset( $stored['availability_schedule'] ) && is_array( $stored['availability_schedule'] ) )
			? $stored['availability_schedule']
			: $defaults['availability_schedule'];
		$current_exceptions = ( isset( $stored['availability_exceptions'] ) && is_array( $stored['availability_exceptions'] ) )
			? $stored['availability_exceptions']
			: $defaults['availability_exceptions'];

		$preserved = array(
			'schedule'   => $current_schedule,
			'exceptions' => $current_exceptions,
		);

		$has_schedule   = array_key_exists( 'availability_schedule', $input );
		$has_exceptions = array_key_exists( 'availability_exceptions', $input );

		if ( ! $has_schedule && ! $has_exceptions ) {
			return $preserved;
		}

		$submitted_schedule   = $has_schedule ? $input['availability_schedule'] : $current_schedule;
		$submitted_exceptions = $has_exceptions ? $input['availability_exceptions'] : $current_exceptions;

		if ( $submitted_schedule === $current_schedule && $submitted_exceptions === $current_exceptions ) {
			return $preserved;
		}

		try {
			return array(
				'schedule'   => WeeklySchedule::from_array( is_array( $submitted_schedule ) ? $submitted_schedule : array() )->to_array(),
				'exceptions' => ExceptionSet::from_array( is_array( $submitted_exceptions ) ? $submitted_exceptions : array() )->to_array(),
			);
		} catch ( InvalidScheduleException $exception ) {
			unset( $exception );

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'availability_section',
					__( 'The support schedule or a date exception contained an invalid value, so the whole Availability section was not saved. Your previous support hours and exceptions are unchanged.', 'universal-support-chat' )
				);
			}

			return $preserved;
		}
	}

	/**
	 * Sanitizes the offline message: plain multiline text, length-capped,
	 * never empty (a blank value falls back to the default so a visitor is
	 * never shown an empty offline notice).
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Default message.
	 */
	private function offline_message( $value, string $fallback ): string {
		$clean = $this->plain_multiline_text( $value, self::OFFLINE_MESSAGE_MAX );

		return '' === trim( $clean ) ? $fallback : $clean;
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

		// Reject non-positive values (including negatives) BEFORE absint(),
		// so `-5` never becomes attachment `5` (ADR-0016).
		$id = (int) $value;

		if ( $id < 1 ) {
			return 0;
		}

		return wp_attachment_is_image( $id ) ? $id : 0;
	}
}
