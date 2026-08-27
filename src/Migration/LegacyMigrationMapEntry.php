<?php
/**
 * One conversation-level source-to-target migration map row.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Immutable snapshot of `universal_support_chat_legacy_migration_map`.
 */
final class LegacyMigrationMapEntry {

	public const STATUS_PENDING    = 'pending';
	public const STATUS_BACKFILLED = 'backfilled';
	public const STATUS_MIGRATED   = 'migrated';
	public const STATUS_SKIPPED    = 'skipped';
	public const STATUS_FAILED     = 'failed';

	/**
	 * Constructor.
	 *
	 * @param int         $id                            Primary key.
	 * @param int         $source_conversation_id        Legacy numeric conversation id.
	 * @param string      $source_conversation_uuid      Legacy conversation UUID.
	 * @param int|null    $target_conversation_id        Target conversation primary key, if migrated.
	 * @param string|null $target_conversation_uuid      Target conversation UUID, if migrated.
	 * @param string      $status                        One of the STATUS_* constants.
	 * @param int|null    $legacy_bot_id                 Preserved for work package 5.
	 * @param int|null    $legacy_destination_id         Preserved for work package 5.
	 * @param int|null    $legacy_telegram_topic_id      Preserved for work package 5.
	 * @param string|null $legacy_topic_creation_state   Preserved for work package 5.
	 * @param string|null $legacy_topic_lifecycle_state  Preserved for work package 5.
	 * @param int         $message_count_source          Source message count.
	 * @param int         $message_count_target          Target message count.
	 * @param int         $note_count_source             Source note count.
	 * @param int         $note_count_target             Target note count.
	 * @param bool|null   $validation_passed             Phase B validation result, if validated.
	 * @param string|null $validated_at                  When Phase B last validated this row.
	 * @param string|null $error_reason                  A stable, typed reason if skipped/failed.
	 * @param string|null $migrated_at                   When Phase B promoted this row to migrated.
	 * @param string      $created_at                    Created at.
	 * @param string      $updated_at                    Updated at.
	 * @param string|null $binding_status                One of `created`|`skipped`|`conflict`, or null (work package 5, never attempted terminally).
	 * @param string|null $binding_error_reason          A stable, typed reason if `binding_status` is `skipped`/`conflict`.
	 * @param string|null $binding_uuid                  The resulting Universal Telegram binding UUID, if `binding_status` is `created`.
	 * @param string|null $binding_attempted_at          When this row last reached a terminal binding outcome.
	 * @param string|null $binding_last_attempt_at       When this row was last attempted, terminal or retryable.
	 * @param string|null $binding_last_attempt_reason   The retryable-outcome reason from the most recent attempt, if it was retryable.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $source_conversation_id,
		private readonly string $source_conversation_uuid,
		private readonly ?int $target_conversation_id,
		private readonly ?string $target_conversation_uuid,
		private readonly string $status,
		private readonly ?int $legacy_bot_id,
		private readonly ?int $legacy_destination_id,
		private readonly ?int $legacy_telegram_topic_id,
		private readonly ?string $legacy_topic_creation_state,
		private readonly ?string $legacy_topic_lifecycle_state,
		private readonly int $message_count_source,
		private readonly int $message_count_target,
		private readonly int $note_count_source,
		private readonly int $note_count_target,
		private readonly ?bool $validation_passed,
		private readonly ?string $validated_at,
		private readonly ?string $error_reason,
		private readonly ?string $migrated_at,
		private readonly string $created_at,
		private readonly string $updated_at,
		private readonly ?string $binding_status = null,
		private readonly ?string $binding_error_reason = null,
		private readonly ?string $binding_uuid = null,
		private readonly ?string $binding_attempted_at = null,
		private readonly ?string $binding_last_attempt_at = null,
		private readonly ?string $binding_last_attempt_reason = null
	) {}

	/**
	 * Hydrates from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['source_conversation_id'],
			(string) $row['source_conversation_uuid'],
			isset( $row['target_conversation_id'] ) ? (int) $row['target_conversation_id'] : null,
			isset( $row['target_conversation_uuid'] ) ? (string) $row['target_conversation_uuid'] : null,
			(string) $row['status'],
			isset( $row['legacy_bot_id'] ) ? (int) $row['legacy_bot_id'] : null,
			isset( $row['legacy_destination_id'] ) ? (int) $row['legacy_destination_id'] : null,
			isset( $row['legacy_telegram_topic_id'] ) ? (int) $row['legacy_telegram_topic_id'] : null,
			isset( $row['legacy_topic_creation_state'] ) ? (string) $row['legacy_topic_creation_state'] : null,
			isset( $row['legacy_topic_lifecycle_state'] ) ? (string) $row['legacy_topic_lifecycle_state'] : null,
			(int) $row['message_count_source'],
			(int) $row['message_count_target'],
			(int) $row['note_count_source'],
			(int) $row['note_count_target'],
			isset( $row['validation_passed'] ) ? (bool) $row['validation_passed'] : null,
			isset( $row['validated_at'] ) ? (string) $row['validated_at'] : null,
			isset( $row['error_reason'] ) ? (string) $row['error_reason'] : null,
			isset( $row['migrated_at'] ) ? (string) $row['migrated_at'] : null,
			(string) $row['created_at'],
			(string) $row['updated_at'],
			isset( $row['binding_status'] ) ? (string) $row['binding_status'] : null,
			isset( $row['binding_error_reason'] ) ? (string) $row['binding_error_reason'] : null,
			isset( $row['binding_uuid'] ) ? (string) $row['binding_uuid'] : null,
			isset( $row['binding_attempted_at'] ) ? (string) $row['binding_attempted_at'] : null,
			isset( $row['binding_last_attempt_at'] ) ? (string) $row['binding_last_attempt_at'] : null,
			isset( $row['binding_last_attempt_reason'] ) ? (string) $row['binding_last_attempt_reason'] : null
		);
	}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Legacy numeric conversation id.
	 */
	public function source_conversation_id(): int {
		return $this->source_conversation_id;
	}

	/**
	 * Legacy conversation UUID.
	 */
	public function source_conversation_uuid(): string {
		return $this->source_conversation_uuid;
	}

	/**
	 * Target conversation primary key, if migrated.
	 */
	public function target_conversation_id(): ?int {
		return $this->target_conversation_id;
	}

	/**
	 * Target conversation UUID, if migrated.
	 */
	public function target_conversation_uuid(): ?string {
		return $this->target_conversation_uuid;
	}

	/**
	 * One of the STATUS_* constants.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Preserved for work package 5.
	 */
	public function legacy_bot_id(): ?int {
		return $this->legacy_bot_id;
	}

	/**
	 * Preserved for work package 5.
	 */
	public function legacy_destination_id(): ?int {
		return $this->legacy_destination_id;
	}

	/**
	 * Preserved for work package 5.
	 */
	public function legacy_telegram_topic_id(): ?int {
		return $this->legacy_telegram_topic_id;
	}

	/**
	 * Preserved for work package 5.
	 */
	public function legacy_topic_creation_state(): ?string {
		return $this->legacy_topic_creation_state;
	}

	/**
	 * Preserved for work package 5.
	 */
	public function legacy_topic_lifecycle_state(): ?string {
		return $this->legacy_topic_lifecycle_state;
	}

	/**
	 * Source message count.
	 */
	public function message_count_source(): int {
		return $this->message_count_source;
	}

	/**
	 * Target message count.
	 */
	public function message_count_target(): int {
		return $this->message_count_target;
	}

	/**
	 * Source note count.
	 */
	public function note_count_source(): int {
		return $this->note_count_source;
	}

	/**
	 * Target note count.
	 */
	public function note_count_target(): int {
		return $this->note_count_target;
	}

	/**
	 * Phase B validation result, if validated.
	 */
	public function validation_passed(): ?bool {
		return $this->validation_passed;
	}

	/**
	 * When Phase B last validated this row.
	 */
	public function validated_at(): ?string {
		return $this->validated_at;
	}

	/**
	 * A stable, typed reason if skipped/failed.
	 */
	public function error_reason(): ?string {
		return $this->error_reason;
	}

	/**
	 * When Phase B promoted this row to migrated.
	 */
	public function migrated_at(): ?string {
		return $this->migrated_at;
	}

	/**
	 * Created at.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Updated at.
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * One of `created`|`skipped`|`conflict`, or null if this row has never
	 * reached a terminal binding outcome (work package 5) — also the
	 * rescan predicate.
	 */
	public function binding_status(): ?string {
		return $this->binding_status;
	}

	/**
	 * A stable, typed reason if `binding_status` is `skipped`/`conflict`.
	 */
	public function binding_error_reason(): ?string {
		return $this->binding_error_reason;
	}

	/**
	 * The resulting Universal Telegram binding UUID, if `binding_status` is `created`.
	 */
	public function binding_uuid(): ?string {
		return $this->binding_uuid;
	}

	/**
	 * When this row last reached a terminal binding outcome.
	 */
	public function binding_attempted_at(): ?string {
		return $this->binding_attempted_at;
	}

	/**
	 * When this row was last attempted, terminal or retryable.
	 */
	public function binding_last_attempt_at(): ?string {
		return $this->binding_last_attempt_at;
	}

	/**
	 * The retryable-outcome reason from the most recent attempt, if it was
	 * retryable; null once a terminal outcome is reached.
	 */
	public function binding_last_attempt_reason(): ?string {
		return $this->binding_last_attempt_reason;
	}
}
