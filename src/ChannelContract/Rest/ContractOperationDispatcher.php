<?php
/**
 * Adapter → Support Chat Contract v1 domain dispatch (ADR-0005 §5).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Rest;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository;
use UniversalSupportChat\Conversations\Conversation;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Privacy\Classification;

/**
 * Runs only after SignatureVerifier has accepted a call (ADR-0007 §4): every
 * method here assumes authentication, allow-list membership, and replay
 * protection already passed. `channel_case_ref` is Support Chat's own
 * `conversation_uuid` for this work package — no adapter binding/
 * `ensure_channel_case` exists yet (SC-M03 plan v2 work package 1+); this is
 * a deliberate, documented interim convention, not a schema invention.
 */
final class ContractOperationDispatcher {

	private const MAX_TEXT_CHARS = 4096;

	/**
	 * Conversation repository.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Message repository.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Channel status repository.
	 *
	 * @var ChannelStatusRepository
	 */
	private ChannelStatusRepository $channel_status;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository  $conversations  Conversation repository.
	 * @param MessageRepository       $messages       Message repository.
	 * @param ChannelStatusRepository $channel_status Channel status repository.
	 * @param AuditLogger             $audit          Audit logger.
	 */
	public function __construct(
		ConversationRepository $conversations,
		MessageRepository $messages,
		ChannelStatusRepository $channel_status,
		AuditLogger $audit
	) {
		$this->conversations  = $conversations;
		$this->messages       = $messages;
		$this->channel_status = $channel_status;
		$this->audit          = $audit;
	}

	/**
	 * Dispatches one verified, authenticated operation.
	 *
	 * @param string               $operation Contract operation name.
	 * @param string               $peer_id   Verified sender peer ID.
	 * @param array<string, mixed> $body      Decoded JSON request body.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	public function dispatch( string $operation, string $peer_id, array $body ): array {
		switch ( $operation ) {
			case 'ingest_operator_reply':
				return $this->ingest_operator_reply( $peer_id, $body );
			case 'claim':
				return $this->claim( $peer_id, $body );
			case 'release':
				return $this->release( $peer_id, $body );
			case 'resolve':
				return $this->resolve( $peer_id, $body );
			case 'reopen':
				return $this->reopen( $peer_id, $body );
			case 'update_assignment':
				return $this->update_assignment( $peer_id, $body );
			case 'report_channel_unavailable':
				return $this->report_channel_unavailable( $peer_id, $body );
			case 'report_delivery_failure':
				return $this->report_delivery_failure( $peer_id, $body );
			default:
				return $this->error( 400, 'unsupported_operation' );
		}
	}

	/**
	 * `ingest_operator_reply`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function ingest_operator_reply( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$text = isset( $body['body'] ) && is_string( $body['body'] ) ? trim( $body['body'] ) : '';
		if ( '' === $text || strlen( $text ) > self::MAX_TEXT_CHARS ) {
			return $this->error( 400, 'invalid_body' );
		}

		$idempotency_key  = $this->string_field( $body, 'idempotency_key' );
		$operator_user_id = isset( $body['operator_user_id'] ) && is_numeric( $body['operator_user_id'] )
			? (int) $body['operator_user_id']
			: 0;

		$message = $this->messages->create(
			$conversation->id(),
			ConversationMessage::DIRECTION_OPERATOR,
			$text,
			'stored',
			$idempotency_key
		);

		if ( null === $message ) {
			return $this->error( 503, 'request_failed' );
		}

		$this->advance_after_operator_reply( $conversation );

		$this->audit_op(
			'contract.ingest_operator_reply',
			$peer_id,
			array(
				'conversation_uuid' => $conversation->uuid(),
				'message_uuid'      => $message->uuid(),
			),
			$operator_user_id > 0 ? $operator_user_id : null
		);

		return $this->ok( array( 'message_uuid' => $message->uuid() ) );
	}

	/**
	 * `claim`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function claim( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$operator_user_id = $this->required_operator_id( $body );
		if ( null === $operator_user_id ) {
			return $this->error( 400, 'invalid_operator' );
		}

		$claimed = $this->conversations->claim( $conversation, $operator_user_id );
		if ( null === $claimed ) {
			return $this->error( 409, 'already_claimed' );
		}

		$this->audit_op( 'contract.claim', $peer_id, array( 'conversation_uuid' => $conversation->uuid() ), $operator_user_id );

		return $this->ok( array( 'status' => $claimed->status() ) );
	}

	/**
	 * `release`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function release( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$operator_user_id = $this->required_operator_id( $body );
		if ( null === $operator_user_id ) {
			return $this->error( 400, 'invalid_operator' );
		}

		$released = $this->conversations->release( $conversation, $operator_user_id );
		if ( null === $released ) {
			return $this->error( 409, 'claimed_by_other' );
		}

		$this->audit_op( 'contract.release', $peer_id, array( 'conversation_uuid' => $conversation->uuid() ), $operator_user_id );

		return $this->ok( array( 'status' => $released->status() ) );
	}

	/**
	 * `resolve`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function resolve( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		if ( ConversationStatus::RESOLVED === $conversation->status() ) {
			return $this->ok( array( 'status' => $conversation->status() ) );
		}

		$transitioned = $this->conversations->transition( $conversation, ConversationStatus::RESOLVED );
		if ( null === $transitioned ) {
			return $this->error( 409, 'invalid_transition' );
		}

		$this->audit_op( 'contract.resolve', $peer_id, array( 'conversation_uuid' => $conversation->uuid() ), $this->optional_operator_id( $body ) );

		return $this->ok( array( 'status' => $transitioned->status() ) );
	}

	/**
	 * `reopen`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function reopen( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		if ( ConversationStatus::is_active( $conversation->status() ) ) {
			return $this->ok( array( 'status' => $conversation->status() ) );
		}

		$transitioned = $this->conversations->transition( $conversation, ConversationStatus::OPEN );
		if ( null === $transitioned ) {
			return $this->error( 409, 'invalid_transition' );
		}

		$this->audit_op( 'contract.reopen', $peer_id, array( 'conversation_uuid' => $conversation->uuid() ), $this->optional_operator_id( $body ) );

		return $this->ok( array( 'status' => $transitioned->status() ) );
	}

	/**
	 * `update_assignment`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function update_assignment( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$operator_user_id = $this->required_operator_id( $body );
		if ( null === $operator_user_id ) {
			return $this->error( 400, 'invalid_operator' );
		}

		$assigned = $this->conversations->assign( $conversation, $operator_user_id );
		if ( null === $assigned ) {
			return $this->error( 503, 'request_failed' );
		}

		$this->audit_op( 'contract.update_assignment', $peer_id, array( 'conversation_uuid' => $conversation->uuid() ), $operator_user_id );

		return $this->ok( array( 'status' => $assigned->status() ) );
	}

	/**
	 * `report_channel_unavailable`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function report_channel_unavailable( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$reason_code = $this->string_field( $body, 'reason_code' ) ?? 'unspecified';

		$this->channel_status->mark_degraded( $conversation->id(), $reason_code );

		$this->audit_op(
			'contract.channel_unavailable_reported',
			$peer_id,
			array(
				'conversation_uuid' => $conversation->uuid(),
				'reason_code'       => $reason_code,
			),
			null
		);

		return $this->ok( array() );
	}

	/**
	 * `report_delivery_failure`.
	 *
	 * @param string               $peer_id Verified sender peer ID.
	 * @param array<string, mixed> $body    Decoded JSON request body.
	 */
	private function report_delivery_failure( string $peer_id, array $body ): array {
		$conversation = $this->resolve_conversation( $body );
		if ( null === $conversation ) {
			return $this->error( 404, 'not_found' );
		}

		$reason_code = $this->string_field( $body, 'reason_code' ) ?? 'delivery_failed';

		// Safe to repeat: an UPSERT on the conversation's single channel-status
		// row, keyed by `conversation_id` (ADR-0005 §3: Support Chat stores
		// only the opaque reference plus channel status derived from adapter
		// callbacks) — never Telegram-native IDs, message bodies, or queue state.
		$this->channel_status->mark_degraded( $conversation->id(), $reason_code );

		$this->audit_op(
			'contract.delivery_failure_reported',
			$peer_id,
			array(
				'conversation_uuid' => $conversation->uuid(),
				'reason_code'       => $reason_code,
			),
			null
		);

		return $this->ok( array() );
	}

	/**
	 * Advances conversation status after an operator reply arrives via the
	 * channel, mirroring the Hub reply status flow.
	 *
	 * @param Conversation $conversation Conversation snapshot.
	 */
	private function advance_after_operator_reply( Conversation $conversation ): void {
		$current = $conversation;

		if ( ConversationStatus::NEW === $current->status() || ConversationStatus::WAITING_FOR_OPERATOR === $current->status() ) {
			$opened  = $this->conversations->transition( $current, ConversationStatus::OPEN );
			$current = $opened ?? $current;
		}

		if ( ConversationStatus::OPEN === $current->status() ) {
			$this->conversations->transition( $current, ConversationStatus::WAITING_FOR_VISITOR );
		} else {
			$this->conversations->touch( $current );
		}
	}

	/**
	 * Resolves `channel_case_ref` to a conversation. Interim convention:
	 * `channel_case_ref` is the Support Chat `conversation_uuid`.
	 *
	 * @param array<string, mixed> $body Decoded JSON request body.
	 */
	private function resolve_conversation( array $body ): ?Conversation {
		$ref = $this->string_field( $body, 'channel_case_ref' );
		if ( null === $ref || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $ref ) ) {
			return null;
		}

		return $this->conversations->find_by_uuid( $ref );
	}

	/**
	 * A required positive operator user ID, or null if missing/invalid.
	 *
	 * @param array<string, mixed> $body Decoded JSON request body.
	 */
	private function required_operator_id( array $body ): ?int {
		if ( ! isset( $body['operator_user_id'] ) || ! is_numeric( $body['operator_user_id'] ) ) {
			return null;
		}

		$id = (int) $body['operator_user_id'];

		return $id > 0 ? $id : null;
	}

	/**
	 * An optional positive operator user ID, or null.
	 *
	 * @param array<string, mixed> $body Decoded JSON request body.
	 */
	private function optional_operator_id( array $body ): ?int {
		$id = $this->required_operator_id( $body );

		return $id;
	}

	/**
	 * A trimmed, non-empty string field, or null.
	 *
	 * @param array<string, mixed> $body  Decoded JSON request body.
	 * @param string                $field Field name.
	 */
	private function string_field( array $body, string $field ): ?string {
		if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
			return null;
		}

		$value = trim( $body[ $field ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Records a Contract operation audit event. Never includes plaintext
	 * message bodies or key material.
	 *
	 * @param string                $action        Audit action name.
	 * @param string                $peer_id       Verified sender peer ID.
	 * @param array<string, string> $context       Non-secret context fields.
	 * @param int|null              $actor_user_id Attributed operator user ID, if any.
	 * @param array<string, Classification>|null $extra_map Classification overrides for $context keys.
	 */
	private function audit_op( string $action, string $peer_id, array $context, ?int $actor_user_id, ?array $extra_map = null ): void {
		$context['peer_id'] = $peer_id;
		$map                = array( 'peer_id' => Classification::INTERNAL );

		foreach ( array_keys( $context ) as $key ) {
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = Classification::INTERNAL;
			}
		}

		if ( null !== $extra_map ) {
			$map = array_merge( $map, $extra_map );
		}

		$this->audit->record( $action, 'adapter', $actor_user_id, $context, $map, Classification::INTERNAL );
	}

	/**
	 * Success envelope.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function ok( array $data ): array {
		return array(
			'status' => 200,
			'body'   => array_merge( array( 'ok' => true ), $data ),
		);
	}

	/**
	 * Error envelope (post-authentication; never the uniform 401 denial).
	 *
	 * @param int    $status HTTP status.
	 * @param string $reason Machine-readable reason.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function error( int $status, string $reason ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'ok'     => false,
				'reason' => $reason,
			),
		);
	}
}
