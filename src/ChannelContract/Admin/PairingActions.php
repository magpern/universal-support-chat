<?php
/**
 * Admin-post handlers for Contract v1 pairing (ADR-0007 §2).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Admin;

use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PairingService;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Capability + CSRF gated pairing mutations (mirrors
 * Administration\Conversations\HubActions). Pairing additionally requires
 * the current user to hold the peer's own admin-supplied manage capability
 * (ADR-0007 §2's "authorized to manage both plugins" rule) — that
 * capability string travels as admin-entered data, never as a literal
 * reference to any specific adapter plugin, so this class stays generic
 * across future adapters.
 */
final class PairingActions {

	private const NONCE_ACTION = 'usc_contract_pairing';

	private const NONCE_FIELD = '_usc_contract_nonce';

	/**
	 * Pairing service.
	 *
	 * @var PairingService
	 */
	private PairingService $pairing;

	/**
	 * Own key manager.
	 *
	 * @var OwnKeyManager
	 */
	private OwnKeyManager $own_keys;

	/**
	 * Constructor.
	 *
	 * @param PairingService $pairing  Pairing service.
	 * @param OwnKeyManager  $own_keys Own key manager.
	 */
	public function __construct( PairingService $pairing, OwnKeyManager $own_keys ) {
		$this->pairing  = $pairing;
		$this->own_keys = $own_keys;
	}

	/**
	 * Registers admin-post hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_usc_contract_pair', array( $this, 'handle_pair' ) );
		add_action( 'admin_post_usc_contract_revoke', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_usc_contract_disable', array( $this, 'handle_disable' ) );
		add_action( 'admin_post_usc_contract_enable', array( $this, 'handle_enable' ) );
		add_action( 'admin_post_usc_contract_rotate_own_key', array( $this, 'handle_rotate_own_key' ) );
	}

	/**
	 * Handles pairing (create or, with explicit confirmation, replace).
	 */
	public function handle_pair(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$peer_id                  = isset( $_POST['peer_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['peer_id'] ) ) : '';
		$public_key               = isset( $_POST['public_key'] ) ? trim( wp_unslash( (string) $_POST['public_key'] ) ) : '';
		$key_id                   = isset( $_POST['key_id'] ) ? trim( wp_unslash( (string) $_POST['key_id'] ) ) : '';
		$required_peer_capability = isset( $_POST['required_peer_capability'] ) ? sanitize_key( wp_unslash( (string) $_POST['required_peer_capability'] ) ) : '';
		$outbound_route_base_raw  = isset( $_POST['outbound_route_base'] ) ? trim( wp_unslash( (string) $_POST['outbound_route_base'] ) ) : '';
		$outbound_route_base      = '' === $outbound_route_base_raw ? null : $outbound_route_base_raw;
		$confirm_replace          = ! empty( $_POST['confirm_replace'] );
		$raw_operations           = isset( $_POST['allowed_operations'] ) && is_array( $_POST['allowed_operations'] )
			? wp_unslash( $_POST['allowed_operations'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $required_peer_capability || ! current_user_can( $required_peer_capability ) ) {
			$this->redirect( 'forbidden' );
		}

		$allowed_operations = array();
		foreach ( (array) $raw_operations as $operation ) {
			if ( is_string( $operation ) && in_array( $operation, ContractOperations::ADAPTER_TO_SUPPORT_CHAT, true ) ) {
				$allowed_operations[] = $operation;
			}
		}

		$result = $this->pairing->pair(
			$peer_id,
			$public_key,
			$key_id,
			$allowed_operations,
			$required_peer_capability,
			$confirm_replace,
			get_current_user_id(),
			null,
			$outbound_route_base
		);

		$this->redirect( $result->ok() ? $result->reason() : 'pairing_' . $result->reason() );
	}

	/**
	 * Handles revocation.
	 */
	public function handle_revoke(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['peer_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = '' !== $peer_id && $this->pairing->revoke( $peer_id, get_current_user_id() );

		$this->redirect( $ok ? 'revoked' : 'revoke_failed' );
	}

	/**
	 * Handles disabling a peer.
	 */
	public function handle_disable(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['peer_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = '' !== $peer_id && $this->pairing->disable( $peer_id, get_current_user_id() );

		$this->redirect( $ok ? 'disabled' : 'disable_failed' );
	}

	/**
	 * Handles re-enabling a peer.
	 */
	public function handle_enable(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['peer_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = '' !== $peer_id && $this->pairing->enable( $peer_id, get_current_user_id() );

		$this->redirect( $ok ? 'enabled' : 'enable_failed' );
	}

	/**
	 * Handles rotating this plugin's own signing key pair.
	 */
	public function handle_rotate_own_key(): void {
		$this->guard();

		$rotated = $this->own_keys->rotate();

		$this->redirect( null !== $rotated ? 'own_key_rotated' : 'own_key_rotate_failed' );
	}

	/**
	 * Capability + CSRF gate.
	 */
	private function guard(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			$this->redirect( 'forbidden' );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * Redirects back to the pairing admin page with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private function redirect( string $notice ): void {
		$url = add_query_arg(
			'usc_contract_notice',
			$notice,
			admin_url( 'options-general.php?page=' . PairingPage::SLUG )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
