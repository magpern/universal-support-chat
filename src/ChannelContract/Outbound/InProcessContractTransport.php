<?php
/**
 * In-process Contract v1 outbound transport.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Outbound;

use WP_REST_Request;

/**
 * Dispatches an outbound Contract v1 call via WordPress's own in-process
 * REST dispatch (`rest_do_request()`), exactly as this plugin's own
 * `ContractOperationsController` is itself reached, and exactly mirroring
 * how Universal Telegram's `SupportChatContractClient` dispatches its
 * adapter -> Support Chat calls today. Both plugins run on the same
 * WordPress install; ADR-0007 §1 notes this profile does not depend on
 * that co-location, so a genuinely remote adapter is served by a different
 * `ContractTransport` implementation (e.g. `wp_safe_remote_post()` against
 * a peer site URL) without any change to signing or client logic.
 */
final class InProcessContractTransport implements ContractTransport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string                $method   Uppercase HTTP method.
	 * @param string                $route    Canonical route path.
	 * @param array<string, string> $headers  Header name => value map.
	 * @param string                $raw_body Exact raw request body bytes.
	 */
	public function send( string $method, string $route, array $headers, string $raw_body ): array {
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( WP_REST_Request::class ) ) {
			return array(
				'status' => 503,
				'ok'     => false,
				'body'   => array(),
			);
		}

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $raw_body );

		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();
		$body     = is_array( $data ) ? $data : array();
		$ok       = ! $response->is_error() && $status >= 200 && $status < 300 && ! empty( $body['ok'] );

		return array(
			'status' => $status,
			'ok'     => $ok,
			'body'   => $body,
		);
	}
}
