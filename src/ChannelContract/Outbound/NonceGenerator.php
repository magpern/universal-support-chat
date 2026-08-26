<?php
/**
 * ADR-0007 §3 outbound nonce generation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Outbound;

/**
 * Generates the per-request replay-protection nonce for one outbound
 * signed Contract v1 call. This is purely a transport-replay control
 * (ADR-0007 §3) — never a business idempotency key (ADR-0005 §6); those
 * two concerns are deliberately kept apart (see IdempotencyKeys).
 */
final class NonceGenerator {

	/**
	 * 16 raw random bytes, encoded as unpadded base64url (22 characters).
	 */
	public static function generate(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
		$encoded = base64_encode( random_bytes( 16 ) );

		return rtrim( strtr( $encoded, '+/', '-_' ), '=' );
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
