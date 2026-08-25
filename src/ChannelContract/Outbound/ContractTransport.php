<?php
/**
 * Outbound Contract v1 request transport abstraction.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Outbound;

/**
 * Dispatches one already-signed Contract v1 HTTP request and reports back
 * a plain, framework-agnostic result. Deliberately decoupled from any
 * specific WordPress HTTP mechanism (`rest_do_request()`, `wp_remote_post()`,
 * ...) so `AdapterContractClient` is unit-testable without a WordPress
 * runtime, and so a future genuinely-remote adapter (ADR-0007 §1) can be
 * supported by adding a new implementation without touching signing,
 * idempotency, or fail-closed gating logic.
 */
interface ContractTransport {

	/**
	 * Sends one request and returns its outcome. Must never throw for an
	 * ordinary transport failure (connection refused, non-2xx, malformed
	 * response) — those are reported through the return value so the
	 * caller's fail-closed handling stays uniform.
	 *
	 * @param string                $method   Uppercase HTTP method.
	 * @param string                $route    Canonical route path (no scheme/host, no query string).
	 * @param array<string, string> $headers  Header name => value map, including all X-SC-* headers.
	 * @param string                $raw_body Exact raw request body bytes to send.
	 *
	 * @return array{status: int, ok: bool, body: array<string, mixed>} `ok` is true only for a
	 *              transport-successful 2xx response whose decoded body itself reports `ok: true`.
	 */
	public function send( string $method, string $route, array $headers, string $raw_body ): array;
}
