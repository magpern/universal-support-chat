<?php
/**
 * Contract v1 discovery surface (ADR-0005 §7, ADR-0007 §3).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract;

use UniversalSupportChat\ChannelContract\Auth\ContractIdentity;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use WP_REST_Response;

/**
 * Advertises Contract v1 capabilities truthfully: `channel_available`
 * reflects whether at least one peer is actually paired and usable, and
 * `operations` reflects only the operations that peer(s) are actually
 * permitted to call — never a fixed catalog regardless of pairing state.
 * Unauthenticated and safe: no internal detail is exposed beyond the
 * boolean/array fields below (ADR-0007 §3).
 */
final class ContractDiscovery {

	public const CONTRACT_VERSION_ID = 'support-channel-contract/v1';

	/**
	 * Peer key store.
	 *
	 * @var PeerRepository
	 */
	private PeerRepository $peers;

	/**
	 * Constructor.
	 *
	 * @param PeerRepository $peers Peer key store.
	 */
	public function __construct( PeerRepository $peers ) {
		$this->peers = $peers;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'universal-support-chat/v1',
			'/channel-contract',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_discover' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns truthful Contract v1 discovery metadata. Never reveals which
	 * peer (if any) is paired, only the aggregate availability/operations.
	 */
	public function handle_discover(): WP_REST_Response {
		$operations = array();

		foreach ( $this->peers->list_all() as $peer ) {
			if ( ! $peer->is_usable() ) {
				continue;
			}

			foreach ( $peer->allowed_operations() as $operation ) {
				if ( in_array( $operation, ContractOperations::ADAPTER_TO_SUPPORT_CHAT, true ) && ! in_array( $operation, $operations, true ) ) {
					$operations[] = $operation;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'contract_version'  => self::CONTRACT_VERSION_ID,
				'auth_profile'      => ContractIdentity::AUTH_PROFILE_ID,
				'adapter_required'  => false,
				'channel_available' => array() !== $operations,
				'operations'        => $operations,
			),
			200
		);
	}
}
