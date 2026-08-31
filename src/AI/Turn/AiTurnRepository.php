<?php
/**
 * AI turn metadata repository (ADR-0018, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

use UniversalSupportChat\Persistence\Migrator;

/**
 * Owns the metadata-only `universal_support_chat_ai_turns` table.
 *
 * Every row is an id, uuid, fixed-vocabulary string, small int, count, or
 * timestamp, plus the `source_ids` / `source_checksums` provenance
 * references. The prompt is never persisted; the AI answer lives only as an
 * `ai`-direction row in the encrypted messages table (ADR-0018 §3, and the
 * schema verification boundary).
 *
 * Row-creation is performed by {@see \UniversalSupportChat\AI\Turn\AiTurnEnqueuer}
 * inside the same transaction as the visitor message (SC-M07 WP6); this
 * repository provides the read helpers, worker-lease primitives, and the
 * retention hook.
 */
final class AiTurnRepository {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_RUNNING    = 'running';
	public const STATUS_ANSWERED   = 'answered';
	public const STATUS_HANDED_OFF = 'handed_off';
	public const STATUS_SKIPPED    = 'skipped';
	public const STATUS_FAILED     = 'failed';

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . Migrator::AI_TURNS_TABLE;
	}

	/**
	 * Inserts a queued turn row. Returns the new row id, or 0 on failure.
	 *
	 * @param string $turn_uuid       Turn UUID.
	 * @param int    $conversation_id Parent conversation id.
	 * @param int    $visitor_message_id The visitor message that triggered the turn.
	 * @param string $available_at    `Y-m-d H:i:s` earliest run time (UTC).
	 */
	public function insert_queued( string $turn_uuid, int $conversation_id, int $visitor_message_id, string $available_at ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'turn_uuid'          => $turn_uuid,
				'conversation_id'    => $conversation_id,
				'visitor_message_id' => $visitor_message_id,
				'status'             => self::STATUS_QUEUED,
				'attempts'           => 0,
				'available_at'       => $available_at,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finds a turn row by UUID.
	 *
	 * @param string $turn_uuid Turn UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_uuid( string $turn_uuid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE turn_uuid = %s", $turn_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether the conversation already has an unresolved (queued/running) turn.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function has_pending_turn( int $conversation_id ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE conversation_id = %d AND status IN ('queued','running')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			)
		);

		return $count > 0;
	}

	/**
	 * Lifetime AI-turn count for a conversation (all statuses except skipped).
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function count_for_conversation( int $conversation_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE conversation_id = %d AND status <> 'skipped'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			)
		);
	}

	/**
	 * Turns created since `$since` (`Y-m-d H:i:s`, UTC).
	 *
	 * @param string $since Lower bound, inclusive.
	 */
	public function count_created_since( string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);
	}

	/**
	 * Handoffs recorded since `$since` (`Y-m-d H:i:s`, UTC).
	 *
	 * @param string $since Lower bound, inclusive.
	 */
	public function count_handoffs_since( string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE status = 'handed_off' AND updated_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);
	}

	/**
	 * Deletes every AI-turn row for a conversation. Called by the retention
	 * sweep when a conversation is purged (ADR-0018 §11) and by
	 * {@see \UniversalSupportChat\Core\Lifecycle\Uninstaller} is out of scope
	 * — uninstall drops the whole table.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function delete_for_conversation( int $conversation_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}
}
