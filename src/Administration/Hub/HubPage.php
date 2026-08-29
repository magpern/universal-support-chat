<?php
/**
 * Support Chat Hub admin shell.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Hub;

use UniversalSupportChat\Administration\Conversations\ConversationDetailPage;
use UniversalSupportChat\Administration\Conversations\ConversationInboxPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Top-level admin menu for operator inbox / conversation detail.
 */
final class HubPage {

	public const SLUG = 'universal-support-chat-hub';

	/**
	 * Inbox list renderer.
	 *
	 * @var ConversationInboxPage
	 */
	private ConversationInboxPage $inbox;

	/**
	 * Detail renderer.
	 *
	 * @var ConversationDetailPage
	 */
	private ConversationDetailPage $detail;

	/**
	 * Constructor.
	 *
	 * @param ConversationInboxPage  $inbox  Inbox list.
	 * @param ConversationDetailPage $detail Conversation detail.
	 */
	public function __construct( ConversationInboxPage $inbox, ConversationDetailPage $detail ) {
		$this->inbox  = $inbox;
		$this->detail = $detail;
	}

	/**
	 * Registers the admin menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the top-level Hub menu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Support Chat Hub', 'universal-support-chat' ),
			__( 'Support Chat', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			58
		);

		// Explicit first submenu so the auto-cloned child reads
		// "Conversations", not "Support Chat" (ADR-0015 §1). The Settings
		// and Diagnostics submenus are registered by their own classes.
		add_submenu_page(
			self::SLUG,
			__( 'Support Chat Conversations', 'universal-support-chat' ),
			__( 'Conversations', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues Hub CSS on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'universal-support-chat-hub',
			plugins_url( 'assets/css/hub.css', UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE ),
			array(),
			UNIVERSAL_SUPPORT_CHAT_VERSION
		);
	}

	/**
	 * Renders inbox or detail.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-support-chat' ) );
		}

		echo '<div class="wrap usc-hub">';
		echo '<h1>' . esc_html__( 'Support Chat Hub', 'universal-support-chat' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$conversation_id = isset( $_GET['conversation_id'] ) ? (int) $_GET['conversation_id'] : 0;

		if ( $conversation_id > 0 ) {
			$this->detail->render( $conversation_id );
		} else {
			$this->inbox->render();
		}

		echo '</div>';
	}
}
