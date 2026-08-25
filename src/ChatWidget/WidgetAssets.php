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
 * Minimal accessible launcher/panel. Authenticated visitors use REST;
 * logged-out visitors get a truthful sign-in prompt only.
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

		$logged_in = is_user_logged_in();

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
				'i18n'         => array(
					'open'             => __( 'Open support chat', 'universal-support-chat' ),
					'close'            => __( 'Close support chat', 'universal-support-chat' ),
					'title'            => __( 'Support chat', 'universal-support-chat' ),
					'signIn'           => __( 'Sign in to chat with support.', 'universal-support-chat' ),
					'signInButton'     => __( 'Sign in', 'universal-support-chat' ),
					'placeholder'      => __( 'Type a message…', 'universal-support-chat' ),
					'send'             => __( 'Send', 'universal-support-chat' ),
					'sending'          => __( 'Sending…', 'universal-support-chat' ),
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

		echo '<div id="usc-chat-root" class="usc-chat" data-usc-chat-root hidden>';
		echo '<button type="button" class="usc-chat__launcher" id="usc-chat-launcher" aria-expanded="false" aria-controls="usc-chat-panel">';
		echo esc_html__( 'Chat', 'universal-support-chat' );
		echo '</button>';
		echo '<div id="usc-chat-panel" class="usc-chat__panel" role="dialog" aria-modal="true" aria-labelledby="usc-chat-title" hidden>';
		echo '<div class="usc-chat__header">';
		echo '<h2 id="usc-chat-title" class="usc-chat__title">' . esc_html__( 'Support chat', 'universal-support-chat' ) . '</h2>';
		echo '<button type="button" class="usc-chat__close" id="usc-chat-close">' . esc_html__( 'Close', 'universal-support-chat' ) . '</button>';
		echo '</div>';
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
}
