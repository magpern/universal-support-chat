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
	 * Whether a turn row already exists for a visitor message (idempotent
	 * enqueue guard — a retried visitor request must not spawn a second turn).
	 *
	 * @phpstan-impure
	 *
	 * @param int $visitor_message_id Visitor message id.
	 */
	public function exists_for_message( int $visitor_message_id ): bool {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE visitor_message_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor_message_id
			)
		) > 0;
	}

	/**
	 * Whether the conversation already has an unresolved (queued/running) turn.
	 *
	 * @phpstan-impure
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
	 * Whether the conversation has already handed off from the AI (a
	 * `handed_off` turn row exists). Once true, no further AI turns run.
	 *
	 * @phpstan-impure
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function has_handoff( int $conversation_id ): bool {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE conversation_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id,
				self::STATUS_HANDED_OFF
			)
		) > 0;
	}

	/**
	 * Leases up to `$limit` due `queued` turns, marking them `running` with a
	 * fresh lease. Crash-recovers rows whose lease has expired.
	 *
	 * @param int $limit        Batch size.
	 * @param int $lease_seconds Lease duration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function claim_due( int $limit, int $lease_seconds = 120 ): array {
		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$lease = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );
		$table = $this->table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ( status = 'queued' AND available_at <= %s ) OR ( status = 'running' AND lease_expires_at < %s ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		$claimed = array();

		foreach ( (array) $rows as $row ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'running', claimed_at = %s, lease_expires_at = %s, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now,
					$lease,
					$now,
					(int) $row['id'],
					(string) $row['status']
				)
			);

			if ( $ok ) {
				$row['status'] = self::STATUS_RUNNING;
				$claimed[]     = $row;
			}
		}

		return $claimed;
	}

	/**
	 * Records the answer outcome for a turn.
	 *
	 * @param int    $id                Turn id.
	 * @param int    $ai_message_id     The stored `ai` message id.
	 * @param string $finish_reason     Normalised finish reason.
	 * @param int    $prompt_tokens     Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @param int    $latency_ms        Round-trip latency.
	 * @param string $source_ids        Comma-joined source ids.
	 * @param string $source_checksums  Comma-joined checksum prefixes.
	 */
	public function complete_answered( int $id, int $ai_message_id, string $finish_reason, int $prompt_tokens, int $completion_tokens, int $latency_ms, string $source_ids, string $source_checksums ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'            => self::STATUS_ANSWERED,
				'outcome'           => 'answered',
				'ai_message_id'     => $ai_message_id,
				'finish_reason'     => $finish_reason,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'latency_ms'        => $latency_ms,
				'source_ids'        => $source_ids,
				'source_checksums'  => $source_checksums,
				'lease_expires_at'  => null,
				'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Records a handoff outcome for a turn.
	 *
	 * @param int         $id                   Turn id.
	 * @param string      $handoff_reason       Bounded {@see HandoffReason} value.
	 * @param string|null $provider_error_class Provider error class, when relevant.
	 */
	public function complete_handed_off( int $id, string $handoff_reason, ?string $provider_error_class = null ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'               => self::STATUS_HANDED_OFF,
				'outcome'              => 'handed_off',
				'handoff_reason'       => $handoff_reason,
				'provider_error_class' => $provider_error_class,
				'lease_expires_at'     => null,
				'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Schedules a retry after a transient provider failure.
	 *
	 * @param int    $id            Turn id.
	 * @param int    $attempts      New attempt count.
	 * @param int    $backoff_secs  Seconds until the next attempt.
	 * @param string $error_class   Provider error class.
	 */
	public function schedule_retry( int $id, int $attempts, int $backoff_secs, string $error_class ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'               => self::STATUS_QUEUED,
				'attempts'             => $attempts,
				'provider_error_class' => $error_class,
				'available_at'         => gmdate( 'Y-m-d H:i:s', time() + $backoff_secs ),
				'claimed_at'           => null,
				'lease_expires_at'     => null,
				'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks every unresolved turn for a conversation `skipped` — used when an
	 * operator takes over or the conversation hands off.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function skip_pending_for_conversation( int $conversation_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'skipped', outcome = 'skipped', lease_expires_at = NULL, updated_at = %s WHERE conversation_id = %d AND status IN ('queued','running')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$conversation_id
			)
		);
	}

	/**
	 * The most recent turn row for a conversation (for the Hub AI panel).
	 *
	 * @param int $conversation_id Conversation id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest_for_conversation( int $conversation_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE conversation_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Deletes every AI-turn row for a conversation. Called by the retention
	 * sweep when a conversation is purged (ADR-0018 §11). Uninstall drops the
	 * whole table instead.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function delete_for_conversation( int $conversation_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}
}
