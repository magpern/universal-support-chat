<?php
/**
 * Contract v1 signature verification (ADR-0007 §3-§4).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\ContractDiscovery;

/**
 * Verifies one authenticated Contract v1 request against ADR-0007's exact
 * ten-line canonical string. A pure, HTTP-framework-agnostic class: the
 * caller extracts headers/method/path/body from its own request object.
 */
class SignatureVerifier {

	private const TIMESTAMP_WINDOW_SECONDS = 300;

	private const REQUIRED_HEADERS = array(
		'contract_version',
		'auth_profile',
		'sender',
		'audience',
		'key_id',
		'timestamp',
		'nonce',
		'body_sha256',
		'signature',
	);

	/**
	 * Peer key store.
	 *
	 * @var PeerRepository
	 */
	private PeerRepository $peers;

	/**
	 * Nonce replay store.
	 *
	 * @var NonceReplayRepository
	 */
	private NonceReplayRepository $nonces;

	/**
	 * Constructor.
	 *
	 * @param PeerRepository        $peers  Peer key store.
	 * @param NonceReplayRepository $nonces Nonce replay store.
	 */
	public function __construct( PeerRepository $peers, NonceReplayRepository $nonces ) {
		$this->peers  = $peers;
		$this->nonces = $nonces;
	}

	/**
	 * Verifies one request.
	 *
	 * @param string                $method           Uppercase HTTP method.
	 * @param string                $path             Canonical route path (no query string).
	 * @param string                $raw_body         Exact raw request body bytes.
	 * @param array<string, string> $headers          Normalized headers: contract_version, auth_profile,
	 *                                                 sender, audience, key_id, timestamp, nonce,
	 *                                                 body_sha256, signature — only keys actually present.
	 * @param string                $operation        The Contract operation this request targets.
	 * @param bool                  $has_query_params Whether the request carried any query parameter.
	 */
	public function verify(
		string $method,
		string $path,
		string $raw_body,
		array $headers,
		string $operation,
		bool $has_query_params
	): VerificationResult {
		if ( $has_query_params ) {
			return VerificationResult::denied();
		}

		foreach ( self::REQUIRED_HEADERS as $name ) {
			if ( ! isset( $headers[ $name ] ) || '' === $headers[ $name ] ) {
				return VerificationResult::denied();
			}
		}

		if ( ContractDiscovery::CONTRACT_VERSION_ID !== $headers['contract_version'] ) {
			return VerificationResult::denied();
		}

		if ( ContractIdentity::AUTH_PROFILE_ID !== $headers['auth_profile'] ) {
			return VerificationResult::denied();
		}

		if ( ContractIdentity::SELF_ID !== $headers['audience'] ) {
			return VerificationResult::denied();
		}

		if ( ! in_array( $operation, ContractOperations::ADAPTER_TO_SUPPORT_CHAT, true ) ) {
			return VerificationResult::denied();
		}

		$sender = $headers['sender'];
		$peer   = $this->peers->find_by_peer_id( $sender );

		if ( null === $peer || ! $peer->is_usable() ) {
			return VerificationResult::denied();
		}

		if ( ! hash_equals( $peer->key_id(), $headers['key_id'] ) ) {
			return VerificationResult::denied();
		}

		if ( ! $peer->allows( $operation ) ) {
			return VerificationResult::denied();
		}

		if ( ! $this->timestamp_within_window( $headers['timestamp'] ) ) {
			return VerificationResult::denied();
		}

		if ( ! $this->is_valid_nonce_format( $headers['nonce'] ) ) {
			return VerificationResult::denied();
		}

		$expected_body_hash = hash( 'sha256', $raw_body );
		if ( ! hash_equals( $expected_body_hash, strtolower( $headers['body_sha256'] ) ) ) {
			return VerificationResult::denied();
		}

		$public_key = $peer->public_key_raw();
		if ( null === $public_key ) {
			return VerificationResult::denied();
		}

		$signature = base64_decode( $headers['signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding, not obfuscation.
		if ( false === $signature || 64 !== strlen( $signature ) ) {
			return VerificationResult::denied();
		}

		$canonical = $this->canonical_string( $headers, $method, $path );

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return VerificationResult::denied();
		}

		$signature_valid = sodium_crypto_sign_verify_detached( $signature, $canonical, $public_key );
		if ( ! $signature_valid ) {
			return VerificationResult::denied();
		}

		// Signature valid — now atomically claim the nonce. A duplicate
		// tuple at this point is a genuine replay, never a false positive
		// from an earlier failed/forged attempt (those never reach here).
		if ( ! $this->nonces->record_if_new( $sender, $headers['key_id'], $headers['nonce'] ) ) {
			return VerificationResult::denied();
		}

		$this->peers->touch_last_used( $sender );

		return VerificationResult::accepted( $sender );
	}

	/**
	 * Whether the timestamp is numeric and within the acceptance window.
	 *
	 * @param string $timestamp Raw timestamp header value.
	 */
	private function timestamp_within_window( string $timestamp ): bool {
		if ( 1 !== preg_match( '/^\d{1,10}$/', $timestamp ) ) {
			return false;
		}

		$delta = abs( time() - (int) $timestamp );

		return $delta <= self::TIMESTAMP_WINDOW_SECONDS;
	}

	/**
	 * Whether the nonce is well-formed unpadded base64url of 16 raw bytes
	 * (22 characters).
	 *
	 * @param string $nonce Raw nonce header value.
	 */
	private function is_valid_nonce_format( string $nonce ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/', $nonce );
	}

	/**
	 * Builds ADR-0007 §3's exact ten-line canonical string.
	 *
	 * @param array<string, string> $headers Normalized headers.
	 * @param string                $method  Uppercase HTTP method.
	 * @param string                $path    Canonical route path.
	 */
	private function canonical_string( array $headers, string $method, string $path ): string {
		return implode(
			"\n",
			array(
				ContractIdentity::AUTH_PROFILE_ID,
				ContractDiscovery::CONTRACT_VERSION_ID,
				$headers['sender'],
				$headers['audience'],
				$headers['key_id'],
				$headers['timestamp'],
				$headers['nonce'],
				$method,
				$path,
				$headers['body_sha256'],
			)
		);
	}
}
