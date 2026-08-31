<?php
/**
 * Conversation message value object.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

/**
 * Immutable message snapshot. Plaintext is only present after decrypt.
 */
final class ConversationMessage {

	public const DIRECTION_VISITOR  = 'visitor';
	public const DIRECTION_OPERATOR = 'operator';
	public const DIRECTION_SYSTEM   = 'system';

	/**
	 * An answer from the AI assistant (ADR-0018 §3, SC-M07). A new value of
	 * the existing free-form `VARCHAR(16)` direction column — no schema
	 * change. Never mirrored to Telegram: `DispatchEnqueuer::is_mirrored_direction()`
	 * matches only `visitor` / `operator`, so an `ai` message structurally
	 * never opens a channel case (master-plan R1).
	 */
	public const DIRECTION_AI = 'ai';

	/**
	 * Primary key.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Parent conversation ID.
	 *
	 * @var int
	 */
	private int $conversation_id;

	/**
	 * Public message UUID.
	 *
	 * @var string
	 */
	private string $message_uuid;

	/**
	 * Message direction.
	 *
	 * @var string
	 */
	private string $direction;

	/**
	 * Decrypted body, or null when unavailable.
	 *
	 * @var string|null
	 */
	private ?string $plaintext_body;

	/**
	 * Delivery state.
	 *
	 * @var string
	 */
	private string $delivery_state;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Idempotency key, if any.
	 *
	 * @var string|null
	 */
	private ?string $idempotency_key;

	/**
	 * Constructor.
	 *
	 * @param int         $id              Primary key.
	 * @param int         $conversation_id Parent conversation ID.
	 * @param string      $message_uuid    Public UUID.
	 * @param string      $direction       Direction.
	 * @param string|null $plaintext_body  Decrypted body.
	 * @param string      $delivery_state  Delivery state.
	 * @param string      $created_at      Created at.
	 * @param string|null $idempotency_key Idempotency key.
	 */
	public function __construct(
		int $id,
		int $conversation_id,
		string $message_uuid,
		string $direction,
		?string $plaintext_body,
		string $delivery_state,
		string $created_at,
		?string $idempotency_key
	) {
		$this->id              = $id;
		$this->conversation_id = $conversation_id;
		$this->message_uuid    = $message_uuid;
		$this->direction       = $direction;
		$this->plaintext_body  = $plaintext_body;
		$this->delivery_state  = $delivery_state;
		$this->created_at      = $created_at;
		$this->idempotency_key = $idempotency_key;
	}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Parent conversation ID.
	 */
	public function conversation_id(): int {
		return $this->conversation_id;
	}

	/**
	 * Public message UUID.
	 */
	public function uuid(): string {
		return $this->message_uuid;
	}

	/**
	 * Message direction.
	 */
	public function direction(): string {
		return $this->direction;
	}

	/**
	 * Decrypted body, or null when unavailable.
	 */
	public function plaintext_body(): ?string {
		return $this->plaintext_body;
	}

	/**
	 * Delivery state.
	 */
	public function delivery_state(): string {
		return $this->delivery_state;
	}

	/**
	 * Creation timestamp.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Idempotency key, if any.
	 */
	public function idempotency_key(): ?string {
		return $this->idempotency_key;
	}
}
