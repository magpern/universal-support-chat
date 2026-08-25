<?php
/**
 * Fixed Contract v1 operation allow-lists (ADR-0005 §4-§5, ADR-0007 §4).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

/**
 * The operation names this Contract v1 server actually implements. Never
 * invented or extended at runtime — a peer's permitted-operation allow-list
 * (ADR-0007 §2) may only be drawn from ADAPTER_TO_SUPPORT_CHAT, and
 * discovery (ContractDiscovery) may only ever advertise a subset of it.
 *
 * ADR-0005 §5 additionally defines `update_operator_presence` as a Contract
 * v1 adapter → Support Chat operation. It is deliberately **not** in this
 * list: Support Chat has no Availability-boundary storage yet (not
 * authorized until SC-M06, ARCHITECTURE.md), so there is no persisted
 * effect this work package could honestly implement for it. Because it is
 * absent from ADAPTER_TO_SUPPORT_CHAT, no peer can ever be paired with it,
 * discovery never advertises it, and SignatureVerifier's operation-allow-
 * list check fails closed on it with the same uniform ADR-0007 §3 denial as
 * any other unrecognized operation — never a bespoke error path.
 */
final class ContractOperations {

	/**
	 * Adapter → Support Chat operations this server verifies and dispatches.
	 *
	 * @var array<int, string>
	 */
	public const ADAPTER_TO_SUPPORT_CHAT = array(
		'ingest_operator_reply',
		'claim',
		'release',
		'resolve',
		'reopen',
		'update_assignment',
		'report_channel_unavailable',
		'report_delivery_failure',
	);

	/**
	 * Support Chat → adapter operations (outbound; not signed or verified
	 * by this work package — listed only so pairing cannot be granted an
	 * operation name Contract v1 does not define).
	 *
	 * @var array<int, string>
	 */
	public const SUPPORT_CHAT_TO_ADAPTER = array(
		'ensure_channel_case',
		'notify_operators',
		'deliver_transcript_backfill',
		'deliver_message',
	);

	/**
	 * Whether every operation in the list is a real, adapter → Support
	 * Chat Contract v1 operation.
	 *
	 * @param array<int, mixed> $operations Candidate operation names.
	 */
	public static function is_valid_adapter_allow_list( array $operations ): bool {
		if ( array() === $operations ) {
			return false;
		}

		foreach ( $operations as $operation ) {
			if ( ! is_string( $operation ) || ! in_array( $operation, self::ADAPTER_TO_SUPPORT_CHAT, true ) ) {
				return false;
			}
		}

		return true;
	}
}
