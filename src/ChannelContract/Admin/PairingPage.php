<?php
/**
 * Contract v1 pairing admin page (ADR-0007 §2).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Admin;

use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Shows this plugin's own public key/key ID and every paired peer's
 * pairing state (ADR-0007 §2), and hosts the pairing/rotate/revoke forms.
 * Never renders a private key, a signature, a nonce, or message content.
 */
final class PairingPage {

	public const SLUG = 'universal-support-chat-pairing';

	/**
	 * Own key manager.
	 *
	 * @var OwnKeyManager
	 */
	private OwnKeyManager $own_keys;

	/**
	 * Peer key store.
	 *
	 * @var PeerRepository
	 */
	private PeerRepository $peers;

	/**
	 * Constructor.
	 *
	 * @param OwnKeyManager  $own_keys Own key manager.
	 * @param PeerRepository $peers    Peer key store.
	 */
	public function __construct( OwnKeyManager $own_keys, PeerRepository $peers ) {
		$this->own_keys = $own_keys;
		$this->peers    = $peers;
	}

	/**
	 * Registers the admin page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the Settings submenu page.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Support Chat Channel Pairing', 'universal-support-chat' ),
			__( 'Support Chat Pairing', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the pairing admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		$own_key = $this->own_keys->ensure_key_pair();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Support Chat Channel Pairing', 'universal-support-chat' ) . '</h1>';

		$this->render_notice();
		$this->render_own_key( $own_key );
		$this->render_peer_list();
		$this->render_pair_form();

		echo '</div>';
	}

	/**
	 * Renders a plain-language notice for the last action, if any.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display code, no state change.
		$notice = isset( $_GET['usc_contract_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['usc_contract_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}

	/**
	 * Renders this plugin's own public key/key ID and a rotate action.
	 *
	 * @param array{public_key: string, key_id: string}|null $own_key Own key pair, if generated.
	 */
	private function render_own_key( ?array $own_key ): void {
		echo '<h2>' . esc_html__( 'This site\'s Contract signing key', 'universal-support-chat' ) . '</h2>';

		if ( null === $own_key ) {
			echo '<p>' . esc_html__( 'No signing key could be generated (libsodium unavailable). Contract authentication is unavailable until this is resolved.', 'universal-support-chat' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Key ID', 'universal-support-chat' ) . '</th><td><code>' . esc_html( $own_key['key_id'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Public key (base64)', 'universal-support-chat' ) . '</th><td><code>' . esc_html( $own_key['public_key'] ) . '</code></td></tr>';
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Share only the two values above with an administrator pairing an adapter. The private key never leaves this site.', 'universal-support-chat' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Rotating replaces this key immediately. Every paired peer must re-pair before its calls succeed again. Continue?', 'universal-support-chat' ) ) . '\');">';
		wp_nonce_field( 'usc_contract_pairing', '_usc_contract_nonce' );
		echo '<input type="hidden" name="action" value="usc_contract_rotate_own_key" />';
		submit_button( __( 'Rotate signing key', 'universal-support-chat' ), 'delete' );
		echo '</form>';
	}

	/**
	 * Renders the list of paired peers and their pairing state.
	 */
	private function render_peer_list(): void {
		$peers = $this->peers->list_all();

		echo '<h2>' . esc_html__( 'Paired adapters', 'universal-support-chat' ) . '</h2>';

		if ( array() === $peers ) {
			echo '<p>' . esc_html__( 'No adapter is paired. Support Chat continues to operate fully on its own.', 'universal-support-chat' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
		echo '<th>' . esc_html__( 'Peer', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Key ID', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Allowed operations', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Last used', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'universal-support-chat' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $peers as $peer ) {
			echo '<tr>';
			echo '<td>' . esc_html( $peer->peer_id() ) . '</td>';
			echo '<td>' . esc_html( $peer->pairing_state() ) . '</td>';
			echo '<td><code>' . esc_html( $peer->key_id() ) . '</code></td>';
			echo '<td>' . esc_html( implode( ', ', $peer->allowed_operations() ) ) . '</td>';
			echo '<td>' . esc_html( $peer->last_used_at() ?? __( 'never', 'universal-support-chat' ) ) . '</td>';
			echo '<td>';
			$this->render_peer_action_form( $peer->peer_id(), 'usc_contract_revoke', __( 'Revoke', 'universal-support-chat' ), true );
			if ( 'active' === $peer->pairing_state() ) {
				$this->render_peer_action_form( $peer->peer_id(), 'usc_contract_disable', __( 'Disable', 'universal-support-chat' ), false );
			} elseif ( 'paired_disabled' === $peer->pairing_state() ) {
				$this->render_peer_action_form( $peer->peer_id(), 'usc_contract_enable', __( 'Enable', 'universal-support-chat' ), false );
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one small inline action form for a peer row.
	 *
	 * @param string $peer_id Peer slug.
	 * @param string $action  admin-post action name.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether to show a JS confirmation before submit.
	 */
	private function render_peer_action_form( string $peer_id, string $action, string $label, bool $confirm ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px;"';
		if ( $confirm ) {
			echo ' onsubmit="return confirm(\'' . esc_js( __( 'Are you sure?', 'universal-support-chat' ) ) . '\');"';
		}
		echo '>';
		wp_nonce_field( 'usc_contract_pairing', '_usc_contract_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="peer_id" value="' . esc_attr( $peer_id ) . '" />';
		submit_button( $label, 'small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders the pairing form.
	 */
	private function render_pair_form(): void {
		echo '<h2>' . esc_html__( 'Pair an adapter', 'universal-support-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enter the values the adapter administrator gave you. Pairing requires you to hold both this site\'s Support Chat management capability and the adapter\'s own management capability.', 'universal-support-chat' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'usc_contract_pairing', '_usc_contract_nonce' );
		echo '<input type="hidden" name="action" value="usc_contract_pair" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="usc-peer-id">' . esc_html__( 'Peer slug', 'universal-support-chat' ) . '</label></th><td>';
		echo '<input type="text" id="usc-peer-id" name="peer_id" class="regular-text" placeholder="universal-telegram" required />';
		echo '</td></tr>';

		echo '<tr><th><label for="usc-public-key">' . esc_html__( 'Peer public key (base64)', 'universal-support-chat' ) . '</label></th><td>';
		echo '<input type="text" id="usc-public-key" name="public_key" class="large-text" required />';
		echo '</td></tr>';

		echo '<tr><th><label for="usc-key-id">' . esc_html__( 'Peer key ID', 'universal-support-chat' ) . '</label></th><td>';
		echo '<input type="text" id="usc-key-id" name="key_id" class="regular-text" required />';
		echo '</td></tr>';

		echo '<tr><th><label for="usc-required-cap">' . esc_html__( 'Adapter\'s manage capability', 'universal-support-chat' ) . '</label></th><td>';
		echo '<input type="text" id="usc-required-cap" name="required_peer_capability" class="regular-text" placeholder="' . esc_attr__( 'e.g. the adapter plugin\'s own manage capability', 'universal-support-chat' ) . '" required />';
		echo '<p class="description">' . esc_html__( 'You must hold this WordPress capability yourself for pairing to proceed (ADR-0007 §2).', 'universal-support-chat' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Allowed operations', 'universal-support-chat' ) . '</th><td>';
		foreach ( ContractOperations::ADAPTER_TO_SUPPORT_CHAT as $operation ) {
			echo '<label style="display:block;"><input type="checkbox" name="allowed_operations[]" value="' . esc_attr( $operation ) . '" checked="checked" /> ' . esc_html( $operation ) . '</label>';
		}
		echo '</td></tr>';

		echo '<tr><th></th><td><label><input type="checkbox" name="confirm_replace" value="1" /> ' . esc_html__( 'Replace this peer\'s existing key, if one is already active', 'universal-support-chat' ) . '</label></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Pair', 'universal-support-chat' ) );
		echo '</form>';
	}
}
