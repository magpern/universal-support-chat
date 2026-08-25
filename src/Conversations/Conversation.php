<?php
/**
 * Conversation row value object.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

/**
 * Immutable conversation snapshot.
 */
final class Conversation {

	/**
	 * Primary key.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Public conversation UUID.
	 *
	 * @var string
	 */
	private string $conversation_uuid;

	/**
	 * Owning WordPress user ID.
	 *
	 * @var int
	 */
	private int $owner_user_id;

	/**
	 * Lifecycle status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Assigned operator user ID, if any.
	 *
	 * @var int|null
	 */
	private ?int $assigned_operator_id;

	/**
	 * Start idempotency key, if any.
	 *
	 * @var string|null
	 */
	private ?string $start_idempotency_key;

	/**
	 * Creation timestamp (UTC mysql).
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Last update timestamp (UTC mysql).
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Resolution timestamp, if any.
	 *
	 * @var string|null
	 */
	private ?string $resolved_at;

	/**
	 * Optional expiry timestamp.
	 *
	 * @var string|null
	 */
	private ?string $expires_at;

	/**
	 * Last message ID seen by assignee (Hub foundation).
	 *
	 * @var int|null
	 */
	private ?int $assignee_last_seen_message_id;

	/**
	 * Constructor.
	 *
	 * @param int         $id                            Primary key.
	 * @param string      $conversation_uuid             Public UUID.
	 * @param int         $owner_user_id                 Owner user ID.
	 * @param string      $status                        Lifecycle status.
	 * @param int|null    $assigned_operator_id          Assigned operator.
	 * @param string|null $start_idempotency_key         Start idempotency key.
	 * @param string      $created_at                    Created at.
	 * @param string      $updated_at                    Updated at.
	 * @param string|null $resolved_at                   Resolved at.
	 * @param string|null $expires_at                    Expires at.
	 * @param int|null    $assignee_last_seen_message_id Last seen message id.
	 */
	public function __construct(
		int $id,
		string $conversation_uuid,
		int $owner_user_id,
		string $status,
		?int $assigned_operator_id,
		?string $start_idempotency_key,
		string $created_at,
		string $updated_at,
		?string $resolved_at,
		?string $expires_at,
		?int $assignee_last_seen_message_id
	) {
		$this->id                            = $id;
		$this->conversation_uuid             = $conversation_uuid;
		$this->owner_user_id                 = $owner_user_id;
		$this->status                        = $status;
		$this->assigned_operator_id          = $assigned_operator_id;
		$this->start_idempotency_key         = $start_idempotency_key;
		$this->created_at                    = $created_at;
		$this->updated_at                    = $updated_at;
		$this->resolved_at                   = $resolved_at;
		$this->expires_at                    = $expires_at;
		$this->assignee_last_seen_message_id = $assignee_last_seen_message_id;
	}

	/**
	 * Hydrates from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['conversation_uuid'],
			(int) $row['owner_user_id'],
			(string) $row['status'],
			self::nullable_int( $row['assigned_operator_id'] ?? null ),
			self::nullable_string( $row['start_idempotency_key'] ?? null ),
			(string) $row['created_at'],
			(string) $row['updated_at'],
			self::nullable_string( $row['resolved_at'] ?? null ),
			self::nullable_string( $row['expires_at'] ?? null ),
			self::nullable_int( $row['assignee_last_seen_message_id'] ?? null )
		);
	}

	/**
	 * Coerces a nullable integer column.
	 *
	 * @param mixed $value Raw column value.
	 */
	private static function nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Coerces a nullable string column.
	 *
	 * @param mixed $value Raw column value.
	 */
	private static function nullable_string( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Public conversation UUID.
	 */
	public function uuid(): string {
		return $this->conversation_uuid;
	}

	/**
	 * Owning WordPress user ID.
	 */
	public function owner_user_id(): int {
		return $this->owner_user_id;
	}

	/**
	 * Lifecycle status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Assigned operator user ID, if any.
	 */
	public function assigned_operator_id(): ?int {
		return $this->assigned_operator_id;
	}

	/**
	 * Start idempotency key, if any.
	 */
	public function start_idempotency_key(): ?string {
		return $this->start_idempotency_key;
	}

	/**
	 * Creation timestamp.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last update timestamp.
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * Resolution timestamp, if any.
	 */
	public function resolved_at(): ?string {
		return $this->resolved_at;
	}

	/**
	 * Optional expiry timestamp.
	 */
	public function expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * Last message ID seen by assignee.
	 */
	public function assignee_last_seen_message_id(): ?int {
		return $this->assignee_last_seen_message_id;
	}
}
