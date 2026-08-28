<?php
/**
 * Outbox row snapshot for Support Chat -> Telegram automatic dispatch.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

/**
 * Immutable snapshot of one `universal_support_chat_telegram_dispatch` row.
 * Never carries message plaintext: the body is pulled live from
 * `MessageRepository` (decrypted in memory only) at delivery time.
 */
final class DispatchRecord {

	public const STATE_PENDING    = 'pending';
	public const STATE_DELIVERING = 'delivering';
	public const STATE_DELIVERED  = 'delivered';
	public const STATE_FAILED     = 'failed';
	public const STATE_ABANDONED  = 'abandoned';
	public const STATE_SUPPRESSED = 'suppressed';

	public const ORIGIN_SUPPORT_CHAT = 'support_chat';
	public const ORIGIN_TELEGRAM     = 'telegram';

	/**
	 * Constructor.
	 *
	 * @param int         $id                Primary key.
	 * @param string      $message_uuid      Support Chat message UUID being mirrored.
	 * @param int         $conversation_id   Parent conversation primary key.
	 * @param string      $conversation_uuid Parent conversation UUID.
	 * @param string      $direction         Message direction (visitor|operator).
	 * @param string      $origin            Where the message was written (support_chat|telegram).
	 * @param string      $state             Delivery state.
	 * @param int         $attempts          Delivery attempts so far.
	 * @param string|null $channel_case_ref  Resolved adapter channel case ref, if any.
	 * @param string|null $last_reason       Last non-secret failure/skip reason.
	 * @param string      $next_attempt_at   When the row next becomes due (UTC mysql).
	 * @param string|null $claimed_at        When the current `delivering` claim was taken (UTC mysql), if any.
	 * @param string|null $lease_expires_at  When the current `delivering` claim lease expires (UTC mysql), if any.
	 * @param string      $created_at        Created at (UTC mysql).
	 * @param string      $updated_at        Updated at (UTC mysql).
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $message_uuid,
		private readonly int $conversation_id,
		private readonly string $conversation_uuid,
		private readonly string $direction,
		private readonly string $origin,
		private readonly string $state,
		private readonly int $attempts,
		private readonly ?string $channel_case_ref,
		private readonly ?string $last_reason,
		private readonly string $next_attempt_at,
		private readonly ?string $claimed_at,
		private readonly ?string $lease_expires_at,
		private readonly string $created_at,
		private readonly string $updated_at
	) {}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Support Chat message UUID being mirrored.
	 */
	public function message_uuid(): string {
		return $this->message_uuid;
	}

	/**
	 * Parent conversation primary key.
	 */
	public function conversation_id(): int {
		return $this->conversation_id;
	}

	/**
	 * Parent conversation UUID (the adapter's `channel_case_ref`, ADR-0011).
	 */
	public function conversation_uuid(): string {
		return $this->conversation_uuid;
	}

	/**
	 * Message direction (visitor|operator).
	 */
	public function direction(): string {
		return $this->direction;
	}

	/**
	 * Where the message was written (support_chat|telegram).
	 */
	public function origin(): string {
		return $this->origin;
	}

	/**
	 * Delivery state.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Delivery attempts so far.
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Resolved adapter channel case ref, if any.
	 */
	public function channel_case_ref(): ?string {
		return $this->channel_case_ref;
	}

	/**
	 * Last non-secret failure/skip reason.
	 */
	public function last_reason(): ?string {
		return $this->last_reason;
	}

	/**
	 * When the row next becomes due (UTC mysql).
	 */
	public function next_attempt_at(): string {
		return $this->next_attempt_at;
	}

	/**
	 * When the current `delivering` claim was taken (UTC mysql), if any.
	 */
	public function claimed_at(): ?string {
		return $this->claimed_at;
	}

	/**
	 * When the current `delivering` claim lease expires (UTC mysql), if any.
	 */
	public function lease_expires_at(): ?string {
		return $this->lease_expires_at;
	}

	/**
	 * Created at (UTC mysql).
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Updated at (UTC mysql).
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * Hydrates from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['message_uuid'],
			(int) $row['conversation_id'],
			(string) $row['conversation_uuid'],
			(string) $row['direction'],
			(string) $row['origin'],
			(string) $row['state'],
			(int) $row['attempts'],
			isset( $row['channel_case_ref'] ) && '' !== (string) $row['channel_case_ref'] ? (string) $row['channel_case_ref'] : null,
			isset( $row['last_reason'] ) && '' !== (string) $row['last_reason'] ? (string) $row['last_reason'] : null,
			(string) $row['next_attempt_at'],
			isset( $row['claimed_at'] ) && '' !== (string) $row['claimed_at'] ? (string) $row['claimed_at'] : null,
			isset( $row['lease_expires_at'] ) && '' !== (string) $row['lease_expires_at'] ? (string) $row['lease_expires_at'] : null,
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}
}
