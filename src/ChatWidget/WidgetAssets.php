<?php
/**
 * Front-end chat widget enqueue and shell.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChatWidget;

use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Professional accessible launcher/panel (SC-M05). Authenticated visitors
 * use REST; logged-out visitors get a truthful sign-in prompt only.
 *
 * The panel is a non-modal `role="dialog"` (ADR-0016 / plan v2 D8): no
 * `aria-modal`, no Tab focus trap. Operator-authored presentation text
 * (title, greeting) is plain text only — the title is server-escaped with
 * `esc_html()`, the greeting is delivered as a raw string and rendered by
 * the widget script with `.textContent`. No widget code path uses innerHTML.
 */
final class WidgetAssets {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings      Settings.
	 * @param SchemaHealth $schema_health Schema health.
	 */
	public function __construct( Settings $settings, SchemaHealth $schema_health ) {
		$this->settings      = $settings;
		$this->schema_health = $schema_health;
	}

	/**
	 * Registers front-end hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_shell' ), 30 );
	}

	/**
	 * Enqueues widget assets when enabled.
	 */
	public function enqueue(): void {
		$settings = $this->settings->get();
		if ( empty( $settings['widget_enabled'] ) ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'universal-support-chat-widget',
			plugins_url( 'assets/css/chat-widget.css', UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE ),
			array(),
			UNIVERSAL_SUPPORT_CHAT_VERSION
		);

		wp_enqueue_script(
			'universal-support-chat-widget',
			plugins_url( 'assets/js/chat-widget.js', UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE ),
			array(),
			UNIVERSAL_SUPPORT_CHAT_VERSION,
			true
		);

		$logged_in    = is_user_logged_in();
		$presentation = new WidgetPresentation( $settings );

		wp_localize_script(
			'universal-support-chat-widget',
			'uscChatWidget',
			array(
				'restBase'     => esc_url_raw( rest_url( ConversationsController::ROUTE_NAMESPACE ) ),
				'nonce'        => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
				'loggedIn'     => $logged_in,
				'schemaOk'     => $this->schema_health->is_available(),
				'loginUrl'     => esc_url_raw( wp_login_url( get_permalink() ? (string) get_permalink() : home_url( '/' ) ) ),
				'pollInterval' => 4000,
				// Operator-authored greeting: a raw plain-text string, rendered
				// by the widget script with `.textContent` (ADR-0016). The
				// resolved title and the avatar URL are deliberately NOT in
				// this payload — both are rendered server-side only.
				'greeting'     => $presentation->greeting(),
				'i18n'         => array(
					'open'             => __( 'Open support chat', 'universal-support-chat' ),
					'close'            => __( 'Close support chat', 'universal-support-chat' ),
					'title'            => __( 'Support chat', 'universal-support-chat' ),
					'signIn'           => __( 'Sign in to chat with support.', 'universal-support-chat' ),
					'signInButton'     => __( 'Sign in', 'universal-support-chat' ),
					'placeholder'      => __( 'Type a message…', 'universal-support-chat' ),
					'send'             => __( 'Send', 'universal-support-chat' ),
					'sending'          => __( 'Sending…', 'universal-support-chat' ),
					'loading'          => __( 'Connecting…', 'universal-support-chat' ),
					'you'              => __( 'You', 'universal-support-chat' ),
					'supportTeam'      => __( 'Support team', 'universal-support-chat' ),
					'errorGeneric'     => __( 'Something went wrong. Please try again.', 'universal-support-chat' ),
					'errorAuth'        => __( 'Your session expired. Please sign in again.', 'universal-support-chat' ),
					'errorUnavailable' => __( 'Chat is temporarily unavailable.', 'universal-support-chat' ),
					'empty'            => __( 'No messages yet. Say hello.', 'universal-support-chat' ),
				),
			)
		);
	}

	/**
	 * Prints the widget shell markup.
	 */
	public function render_shell(): void {
		$settings = $this->settings->get();
		if ( empty( $settings['widget_enabled'] ) ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		$presentation = new WidgetPresentation( $settings );
		$title        = $presentation->title();
		$avatar_url   = $presentation->avatar_image_url();
		$close_label  = __( 'Close support chat', 'universal-support-chat' );

		echo '<div id="usc-chat-root" class="usc-chat" data-usc-chat-root hidden>';

		echo '<button type="button" class="usc-chat__launcher" id="usc-chat-launcher" aria-expanded="false" aria-haspopup="dialog" aria-controls="usc-chat-panel">';
		echo self::icon_bubble(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static original inline SVG, no dynamic data.
		echo self::icon_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static original inline SVG, no dynamic data.
		echo '</button>';

		echo '<div id="usc-chat-panel" class="usc-chat__panel" role="dialog" aria-labelledby="usc-chat-title" aria-describedby="usc-chat-intro" hidden>';

		echo '<div class="usc-chat__header">';
		if ( '' !== $avatar_url ) {
			echo '<img class="usc-chat__avatar" alt="" src="' . esc_url( $avatar_url ) . '" width="28" height="28" />';
		}
		echo '<h2 id="usc-chat-title" class="usc-chat__title">' . esc_html( $title ) . '</h2>';
		echo '<button type="button" class="usc-chat__close" id="usc-chat-close" aria-label="' . esc_attr( $close_label ) . '">';
		echo self::icon_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static original inline SVG, no dynamic data.
		echo '</button>';
		echo '</div>';

		echo '<div id="usc-chat-intro" class="usc-chat__intro"></div>';
		echo '<div id="usc-chat-status" class="usc-chat__status" role="status" aria-live="polite"></div>';
		echo '<div id="usc-chat-messages" class="usc-chat__messages" role="log" aria-live="polite" aria-relevant="additions"></div>';
		echo '<form id="usc-chat-form" class="usc-chat__form" hidden>';
		echo '<label for="usc-chat-input" class="screen-reader-text">' . esc_html__( 'Message', 'universal-support-chat' ) . '</label>';
		echo '<textarea id="usc-chat-input" class="usc-chat__input" rows="2" maxlength="4096" required></textarea>';
		echo '<button type="submit" class="usc-chat__send" id="usc-chat-send">' . esc_html__( 'Send', 'universal-support-chat' ) . '</button>';
		echo '</form>';
		echo '<div id="usc-chat-signin" class="usc-chat__signin" hidden></div>';
		echo '</div></div>';
	}

	/**
	 * Original inline speech-bubble glyph shown on the closed launcher.
	 */
	private static function icon_bubble(): string {
		return '<svg class="usc-chat__icon" data-usc-icon="bubble" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1Z" />'
			. '</svg>';
	}

	/**
	 * Original inline X glyph shown on the open launcher and the close button.
	 */
	private static function icon_close(): string {
		return '<svg class="usc-chat__icon" data-usc-icon="close" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M6 6l12 12M18 6 6 18" />'
			. '</svg>';
	}
}
