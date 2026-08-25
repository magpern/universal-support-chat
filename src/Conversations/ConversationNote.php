<?php
/**
 * Internal conversation note value object.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

/**
 * Operator-only note. Never exported to visitor REST or channel adapters.
 */
final class ConversationNote {

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
	 * Authoring operator user ID.
	 *
	 * @var int
	 */
	private int $operator_user_id;

	/**
	 * Public note UUID.
	 *
	 * @var string
	 */
	private string $note_uuid;

	/**
	 * Decrypted body, or null when unavailable.
	 *
	 * @var string|null
	 */
	private ?string $plaintext_body;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Constructor.
	 *
	 * @param int         $id               Primary key.
	 * @param int         $conversation_id  Parent conversation ID.
	 * @param int         $operator_user_id Operator user ID.
	 * @param string      $note_uuid        Note UUID.
	 * @param string|null $plaintext_body   Decrypted body.
	 * @param string      $created_at       Created at.
	 */
	public function __construct(
		int $id,
		int $conversation_id,
		int $operator_user_id,
		string $note_uuid,
		?string $plaintext_body,
		string $created_at
	) {
		$this->id               = $id;
		$this->conversation_id  = $conversation_id;
		$this->operator_user_id = $operator_user_id;
		$this->note_uuid        = $note_uuid;
		$this->plaintext_body   = $plaintext_body;
		$this->created_at       = $created_at;
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
	 * Authoring operator user ID.
	 */
	public function operator_user_id(): int {
		return $this->operator_user_id;
	}

	/**
	 * Note UUID.
	 */
	public function uuid(): string {
		return $this->note_uuid;
	}

	/**
	 * Decrypted body, or null when unavailable.
	 */
	public function plaintext_body(): ?string {
		return $this->plaintext_body;
	}

	/**
	 * Creation timestamp.
	 */
	public function created_at(): string {
		return $this->created_at;
	}
}
