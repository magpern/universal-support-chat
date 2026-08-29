<?php
/**
 * Outbound Contract v1 client (Support Chat -> adapter, ADR-0005 §4, ADR-0007).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Outbound;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Privacy\Classification;

/**
 * Implements the four Support-Chat-to-adapter Contract v1 operations
 * (ADR-0005 §4): `ensure_channel_case`, `notify_operators`,
 * `deliver_transcript_backfill`, `deliver_message`. Every call is an
 * ADR-0007 §3 authenticated, Ed25519-signed request built from this
 * plugin's own key pair and dispatched through the injected
 * `ContractTransport` — in-process `rest_do_request()` today
 * (`InProcessContractTransport`), unchanged if a transport targeting a
 * genuinely remote adapter site is added later (ADR-0007 §1).
 *
 * Fails closed (never signs or sends a request) when: the named peer is
 * not paired or not usable (active, unrevoked, unexpired — includes the
 * "adapter disabled" case, since disabling a peer sets its stored status
 * to `disabled`), the peer has no configured outbound route, or this
 * plugin's own signing key is unavailable. Whether a specific operation is
 * one this peer accepts is the *receiver's* authorization decision
 * (ADR-0007 §4: "a receiver verifies the caller's peer record before
 * dispatch") — this client never locally gates on a per-operation
 * allow-list of its own, because `PeerRecord::allowed_operations()` on
 * this side is validated only against `ContractOperations::
 * ADAPTER_TO_SUPPORT_CHAT` (the disjoint, opposite-direction list this
 * plugin uses to verify *inbound* calls) and can never legitimately
 * contain a Support-Chat-to-adapter operation name. A rejection by the
 * adapter's own allow-list surfaces here only as an ordinary failed-call
 * reason from the transport response. Plaintext message bodies passed to
 * `deliver_message`/`deliver_transcript_backfill` exist only in memory
 * for the duration of the call (ADR-0005 §4, ADR-0003) and are never
 * written to audit context.
 */
class AdapterContractClient {

	public const REASON_INVALID_INPUT       = 'invalid_input';
	public const REASON_NOT_PAIRED          = 'peer_not_usable';
	public const REASON_ROUTE_UNCONFIGURED  = 'adapter_route_unconfigured';
	public const REASON_SIGNING_UNAVAILABLE = 'signing_unavailable';
	public const REASON_TRANSPORT_FAILED    = 'transport_failed';

	/**
	 * Default transport delivery class (ADR-0014 §2) — the behaviour every
	 * existing caller already has. Sent on the wire so an adapter without
	 * the counterpart change simply ignores it.
	 */
	public const DELIVERY_CLASS_STANDARD = 'standard';

	/**
	 * Constructor.
	 *
	 * @param PeerRepository   $peers     Peer key store.
	 * @param SignatureSigner  $signer    Outbound request signer.
	 * @param ContractTransport $transport Request transport.
	 * @param AuditLogger      $audit     Audit logger.
	 */
	public function __construct(
		private readonly PeerRepository $peers,
		private readonly SignatureSigner $signer,
		private readonly ContractTransport $transport,
		private readonly AuditLogger $audit
	) {}

	/**
	 * `ensure_channel_case` (ADR-0005 §4.1). Idempotent on conversation
	 * identity: repeated calls for the same conversation resolve to the
	 * same `channel_case_ref` (IdempotencyKeys::for_ensure_channel_case()).
	 *
	 * @param string               $peer_id           Target adapter peer slug.
	 * @param string               $conversation_uuid Support Chat conversation UUID.
	 * @param string               $reason_code       Escalation reason/code.
	 * @param array<string, mixed> $summary_meta      Optional non-secret summary metadata.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, channel_case_ref: string, case_status: string|null}
	 */
	public function ensure_channel_case( string $peer_id, string $conversation_uuid, string $reason_code, array $summary_meta = array() ): array {
		if ( '' === trim( $conversation_uuid ) ) {
			return $this->invalid_input_ensure();
		}

		$idempotency_key = IdempotencyKeys::for_ensure_channel_case( $conversation_uuid );

		$result = $this->call(
			$peer_id,
			'ensure_channel_case',
			array(
				'conversation_uuid' => $conversation_uuid,
				'idempotency_key'   => $idempotency_key,
				'reason_code'       => $reason_code,
				'summary'           => $summary_meta,
			)
		);

		$this->audit_call( 'ensure_channel_case', $peer_id, $result, array( 'conversation_uuid' => $conversation_uuid ) );

		$body = $result['body'];

		return array(
			'ok'               => $result['ok'],
			'status'           => $result['status'],
			'reason'           => $result['reason'],
			'channel_case_ref' => isset( $body['channel_case_ref'] ) && is_string( $body['channel_case_ref'] ) ? $body['channel_case_ref'] : '',
			'case_status'      => isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : null,
		);
	}

	/**
	 * `notify_operators` (ADR-0005 §4.2). Best-effort; failure never fails
	 * the caller's own Hub ticket creation. Idempotent on channel case,
	 * kind, and summary text unless the caller supplies its own key.
	 *
	 * @param string      $peer_id          Target adapter peer slug.
	 * @param string      $channel_case_ref Opaque channel case reference.
	 * @param string      $kind             Notification kind.
	 * @param string      $summary          Bounded non-secret summary.
	 * @param string|null $idempotency_key  Caller-supplied idempotency key, or null to derive one.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, reused: bool}
	 */
	public function notify_operators( string $peer_id, string $channel_case_ref, string $kind, string $summary = '', ?string $idempotency_key = null ): array {
		if ( '' === trim( $channel_case_ref ) || '' === trim( $kind ) ) {
			return array(
				'ok'     => false,
				'status' => 400,
				'reason' => self::REASON_INVALID_INPUT,
				'reused' => false,
			);
		}

		$key = $idempotency_key ?? IdempotencyKeys::for_notify_operators( $channel_case_ref, $kind, $summary );

		$result = $this->call(
			$peer_id,
			'notify_operators',
			array(
				'channel_case_ref' => $channel_case_ref,
				'idempotency_key'  => $key,
				'kind'             => $kind,
				'summary'          => $summary,
			)
		);

		$this->audit_call(
			'notify_operators',
			$peer_id,
			$result,
			array(
				'channel_case_ref' => $channel_case_ref,
				'kind'             => $kind,
			)
		);

		$body = $result['body'];

		return array(
			'ok'     => $result['ok'],
			'status' => $result['status'],
			'reason' => $result['reason'],
			'reused' => isset( $body['reused'] ) && true === $body['reused'],
		);
	}

	/**
	 * `deliver_transcript_backfill` (ADR-0005 §4.3). Support Chat has
	 * already selected eligible messages and exports their plaintext here;
	 * this client never applies eligibility filtering itself. Each message
	 * shares the same durable idempotency boundary as `deliver_message`
	 * (ADR-0005 §6), keyed on its own `message_uuid`.
	 *
	 * @param string                          $peer_id          Target adapter peer slug.
	 * @param string                          $channel_case_ref Opaque channel case reference.
	 * @param array<int, array<string, mixed>> $messages         Ordered eligible messages (each with at least
	 *                                                            `message_uuid` and `body` string keys, plus an
	 *                                                            optional `attribution` string key), plaintext
	 *                                                            in memory only. A malformed entry is skipped,
	 *                                                            not fatal.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, accepted: int, failed: int}
	 */
	public function deliver_transcript_backfill( string $peer_id, string $channel_case_ref, array $messages ): array {
		if ( '' === trim( $channel_case_ref ) ) {
			return array(
				'ok'       => false,
				'status'   => 400,
				'reason'   => self::REASON_INVALID_INPUT,
				'accepted' => 0,
				'failed'   => 0,
			);
		}

		$wire_messages = array();
		foreach ( $messages as $message ) {
			$message_uuid = isset( $message['message_uuid'] ) && is_string( $message['message_uuid'] ) ? $message['message_uuid'] : '';
			$body         = isset( $message['body'] ) && is_string( $message['body'] ) ? $message['body'] : '';
			$attribution  = isset( $message['attribution'] ) && is_string( $message['attribution'] ) ? $message['attribution'] : '';

			if ( '' === $message_uuid || '' === $body ) {
				continue;
			}

			$wire_messages[] = array(
				'idempotency_key' => IdempotencyKeys::for_message_delivery( $message_uuid ),
				'body'            => $body,
				'attribution'     => $attribution,
			);
		}

		$result = $this->call(
			$peer_id,
			'deliver_transcript_backfill',
			array(
				'channel_case_ref' => $channel_case_ref,
				'messages'         => $wire_messages,
			)
		);

		$this->audit_call(
			'deliver_transcript_backfill',
			$peer_id,
			$result,
			array(
				'channel_case_ref' => $channel_case_ref,
				'message_count'    => (string) count( $wire_messages ),
			)
		);

		$body = $result['body'];

		return array(
			'ok'       => $result['ok'],
			'status'   => $result['status'],
			'reason'   => $result['reason'],
			'accepted' => isset( $body['accepted'] ) && is_numeric( $body['accepted'] ) ? (int) $body['accepted'] : 0,
			'failed'   => isset( $body['failed'] ) && is_numeric( $body['failed'] ) ? (int) $body['failed'] : 0,
		);
	}

	/**
	 * `deliver_message` (ADR-0005 §4.4). Only for a conversation with an
	 * already-escalated channel case (R1: never for ordinary AI-only
	 * turns) — enforcing that policy is the caller's responsibility, not
	 * this transport-level client's. Idempotent on `message_uuid`,
	 * sharing its boundary with backfill sends (ADR-0005 §6).
	 *
	 * @param string $peer_id          Target adapter peer slug.
	 * @param string $channel_case_ref Opaque channel case reference.
	 * @param string $message_uuid    Support Chat message UUID being delivered.
	 * @param string $body            Plaintext message body, in memory only.
	 * @param string $attribution     Channel-facing attribution label.
	 * @param string $delivery_class  Fixed, server-derived transport class (ADR-0014 §2). Defaults to `standard`; never part of the idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, reused: bool}
	 */
	public function deliver_message( string $peer_id, string $channel_case_ref, string $message_uuid, string $body, string $attribution = '', string $delivery_class = self::DELIVERY_CLASS_STANDARD ): array {
		if ( '' === trim( $channel_case_ref ) || '' === trim( $message_uuid ) || '' === $body ) {
			return array(
				'ok'     => false,
				'status' => 400,
				'reason' => self::REASON_INVALID_INPUT,
				'reused' => false,
			);
		}

		$idempotency_key = IdempotencyKeys::for_message_delivery( $message_uuid );

		$result = $this->call(
			$peer_id,
			'deliver_message',
			array(
				'channel_case_ref' => $channel_case_ref,
				'idempotency_key'  => $idempotency_key,
				'body'             => $body,
				'attribution'      => $attribution,
				'delivery_class'   => '' !== trim( $delivery_class ) ? $delivery_class : self::DELIVERY_CLASS_STANDARD,
			)
		);

		$this->audit_call(
			'deliver_message',
			$peer_id,
			$result,
			array(
				'channel_case_ref' => $channel_case_ref,
				'message_uuid'     => $message_uuid,
			)
		);

		$response_body = $result['body'];

		return array(
			'ok'     => $result['ok'],
			'status' => $result['status'],
			'reason' => $result['reason'],
			'reused' => isset( $response_body['reused'] ) && true === $response_body['reused'],
		);
	}

	/**
	 * Builds, signs, and dispatches one authenticated Contract v1 call.
	 * Fails closed at every gate before a request is ever signed or sent
	 * — mirrors the gate ordering `SignatureVerifier` applies on the
	 * inbound side, in the opposite direction.
	 *
	 * @param string               $peer_id   Target adapter peer slug.
	 * @param string               $operation Contract operation name.
	 * @param array<string, mixed> $body      Request body (JSON-encoded exactly once).
	 *
	 * @return array{ok: bool, status: int, reason: string|null, body: array<string, mixed>}
	 */
	private function call( string $peer_id, string $operation, array $body ): array {
		if ( ! in_array( $operation, ContractOperations::SUPPORT_CHAT_TO_ADAPTER, true ) ) {
			return $this->unavailable( self::REASON_INVALID_INPUT );
		}

		if ( '' === trim( $peer_id ) ) {
			return $this->unavailable( self::REASON_NOT_PAIRED );
		}

		$peer = $this->peers->find_by_peer_id( $peer_id );
		if ( null === $peer || ! $peer->is_usable() ) {
			return $this->unavailable( self::REASON_NOT_PAIRED );
		}

		$route_base = $peer->outbound_route_base();
		if ( null === $route_base || '' === $route_base ) {
			return $this->unavailable( self::REASON_ROUTE_UNCONFIGURED );
		}

		$raw_body = (string) wp_json_encode( $body );
		$route    = '/' . $route_base . '/' . $operation;

		$headers = $this->signer->sign( 'POST', $route, $raw_body, $peer_id );
		if ( null === $headers ) {
			return $this->unavailable( self::REASON_SIGNING_UNAVAILABLE );
		}

		$response = $this->transport->send( 'POST', $route, $headers, $raw_body );

		if ( $response['ok'] ) {
			$this->peers->touch_last_used( $peer_id );

			return array(
				'ok'     => true,
				'status' => $response['status'],
				'reason' => null,
				'body'   => $response['body'],
			);
		}

		$reason_value = $response['body']['reason'] ?? null;
		$reason       = is_string( $reason_value ) ? $reason_value : self::REASON_TRANSPORT_FAILED;

		return array(
			'ok'     => false,
			'status' => $response['status'] > 0 ? $response['status'] : 503,
			'reason' => $reason,
			'body'   => $response['body'],
		);
	}

	/**
	 * Fail-closed response for a specific gate, before any signing/sending.
	 *
	 * @param string $reason Stable, non-sensitive reason code.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, body: array<string, mixed>}
	 */
	private function unavailable( string $reason ): array {
		return array(
			'ok'     => false,
			'status' => 503,
			'reason' => $reason,
			'body'   => array(),
		);
	}

	/**
	 * `ensure_channel_case`'s own invalid-input shape.
	 *
	 * @return array{ok: bool, status: int, reason: string|null, channel_case_ref: string, case_status: string|null}
	 */
	private function invalid_input_ensure(): array {
		return array(
			'ok'               => false,
			'status'           => 400,
			'reason'           => self::REASON_INVALID_INPUT,
			'channel_case_ref' => '',
			'case_status'      => null,
		);
	}

	/**
	 * Records an outbound Contract call audit event. Never includes
	 * plaintext message bodies, summaries, or key material — only stable
	 * identifiers and the outcome (ADR-0005 §4, ADR-0003).
	 *
	 * @param string                                                           $operation Contract operation name.
	 * @param string                                                           $peer_id   Target adapter peer slug.
	 * @param array{ok: bool, status: int, reason: string|null, body: array<string, mixed>} $result    Call outcome.
	 * @param array<string, string>                                           $context   Additional non-secret identifiers.
	 */
	private function audit_call( string $operation, string $peer_id, array $result, array $context ): void {
		$full_context = array_merge(
			$context,
			array(
				'peer_id' => $peer_id,
				'ok'      => $result['ok'] ? 'true' : 'false',
				'status'  => (string) $result['status'],
			)
		);

		if ( null !== $result['reason'] ) {
			$full_context['reason'] = $result['reason'];
		}

		$map = array();
		foreach ( array_keys( $full_context ) as $key ) {
			$map[ $key ] = Classification::INTERNAL;
		}

		$this->audit->record( 'contract.outbound_' . $operation, 'system', null, $full_context, $map, Classification::INTERNAL );
	}
}
