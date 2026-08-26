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
		private readonly string $updated_at
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
			(string) $row['updated_at']
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
}
