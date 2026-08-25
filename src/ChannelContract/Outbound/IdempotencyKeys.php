<?php
/**
 * Deterministic business idempotency keys for outbound Contract v1 calls
 * (ADR-0005 §6).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Outbound;

/**
 * ADR-0005 §6 fixes durable idempotency boundaries per operation, keyed on
 * stable business identity (conversation/message UUIDs) — never on the
 * per-request cryptographic nonce (ADR-0007 §3), which exists solely to
 * defeat request replay and must never double as a business dedupe key.
 * Every method here is a pure, deterministic function of its inputs: the
 * same inputs always yield the same key, so a retried call is safe to
 * re-send with the same key after an uncertain failure.
 */
final class IdempotencyKeys {

	/**
	 * `ensure_channel_case`: idempotent on conversation identity alone
	 * (ADR-0005 §4.1, §6) — repeated ensure calls for the same conversation
	 * must resolve to the same `channel_case_ref` regardless of how many
	 * times or why they are retried.
	 *
	 * @param string $conversation_uuid Support Chat conversation UUID.
	 */
	public static function for_ensure_channel_case( string $conversation_uuid ): string {
		return self::derive( 'ensure_channel_case', array( $conversation_uuid ) );
	}

	/**
	 * `notify_operators`: idempotent on the bound case, notification kind,
	 * and summary text (ADR-0005 §4.2) — identical notifications dedupe;
	 * distinct notification kinds/content do not collide.
	 *
	 * @param string $channel_case_ref Opaque channel case reference.
	 * @param string $kind             Notification kind.
	 * @param string $summary          Bounded non-secret summary.
	 */
	public static function for_notify_operators( string $channel_case_ref, string $kind, string $summary ): string {
		return self::derive( 'notify_operators', array( $channel_case_ref, $kind, $summary ) );
	}

	/**
	 * `deliver_message` and per-message `deliver_transcript_backfill` sends
	 * share one idempotency boundary (ADR-0005 §6: "Adapter outbound
	 * delivery (deliver_message / backfill sends)") — both are keyed on the
	 * Support Chat message identity being delivered, so a message can never
	 * be duplicated to the adapter regardless of which call path sent it.
	 *
	 * @param string $message_uuid Support Chat message UUID being delivered.
	 */
	public static function for_message_delivery( string $message_uuid ): string {
		return self::derive( 'deliver_message', array( $message_uuid ) );
	}

	/**
	 * Derives a stable, opaque key from a fixed operation namespace and an
	 * ordered list of business-identity fields.
	 *
	 * @param string              $op_namespace Fixed per-operation namespace.
	 * @param array<int, string>  $parts        Ordered identity fields.
	 */
	private static function derive( string $op_namespace, array $parts ): string {
		return hash( 'sha256', $op_namespace . ':' . implode( ':', $parts ) );
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
