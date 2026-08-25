<?php
/**
 * Authenticated Contract v1 server (ADR-0007).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Rest;

use UniversalSupportChat\ChannelContract\Auth\SignatureVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `universal-support-chat/v1/contract/{operation}` — the authenticated,
 * capability-checked Contract v1 mutation surface ADR-0005 §5 requires.
 * Discovery-reachable (`permission_callback: __return_true`) by design:
 * authentication is the ADR-0007 signature, not a WordPress REST
 * permission callback or current-user context (ADR-0007 §1).
 */
final class ContractOperationsController {

	public const ROUTE_NAMESPACE = 'universal-support-chat/v1';

	private const MAX_BODY_BYTES = 65536;

	/**
	 * Signature verifier.
	 *
	 * @var SignatureVerifier
	 */
	private SignatureVerifier $verifier;

	/**
	 * Domain dispatcher.
	 *
	 * @var ContractOperationDispatcher
	 */
	private ContractOperationDispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param SignatureVerifier           $verifier   Signature verifier.
	 * @param ContractOperationDispatcher $dispatcher Domain dispatcher.
	 */
	public function __construct( SignatureVerifier $verifier, ContractOperationDispatcher $dispatcher ) {
		$this->verifier   = $verifier;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/contract/(?P<operation>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies and dispatches one Contract v1 mutation call.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$operation = (string) $request->get_param( 'operation' );
		$raw_body  = (string) $request->get_body();

		if ( strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return $this->denied();
		}

		$headers = array(
			'contract_version' => (string) ( $request->get_header( 'X-SC-Contract-Version' ) ?? '' ),
			'auth_profile'     => (string) ( $request->get_header( 'X-SC-Auth-Profile' ) ?? '' ),
			'sender'           => (string) ( $request->get_header( 'X-SC-Sender' ) ?? '' ),
			'audience'         => (string) ( $request->get_header( 'X-SC-Audience' ) ?? '' ),
			'key_id'           => (string) ( $request->get_header( 'X-SC-Key-Id' ) ?? '' ),
			'timestamp'        => (string) ( $request->get_header( 'X-SC-Timestamp' ) ?? '' ),
			'nonce'            => (string) ( $request->get_header( 'X-SC-Nonce' ) ?? '' ),
			'body_sha256'      => (string) ( $request->get_header( 'X-SC-Body-Sha256' ) ?? '' ),
			'signature'        => (string) ( $request->get_header( 'X-SC-Signature' ) ?? '' ),
		);

		$route = $request->get_route();
		if ( '' === $route ) {
			$route = '/' . self::ROUTE_NAMESPACE . '/contract/' . $operation;
		}

		$has_query_params = array() !== $request->get_query_params();

		$result = $this->verifier->verify(
			'POST',
			$route,
			$raw_body,
			$headers,
			$operation,
			$has_query_params
		);

		if ( ! $result->ok() || null === $result->peer_id() ) {
			return $this->denied();
		}

		$decoded = json_decode( $raw_body, true );
		$body    = is_array( $decoded ) ? $decoded : array();

		$outcome = $this->dispatcher->dispatch( $operation, $result->peer_id(), $body );

		return new WP_REST_Response( $outcome['body'], $outcome['status'] );
	}

	/**
	 * ADR-0007 §3's uniform authentication denial. Identical for every
	 * failure cause; never leaks which check failed.
	 */
	private function denied(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'     => false,
				'reason' => 'contract_auth_failed',
			),
			401
		);
	}
}
