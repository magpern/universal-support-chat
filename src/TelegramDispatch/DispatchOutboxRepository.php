<?php
/**
 * Support Chat -> Telegram dispatch outbox persistence.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Sole owner of `universal_support_chat_telegram_dispatch`: one durable,
 * Support-Chat-owned row per committed conversation message that is a
 * candidate for mirroring into the linked Telegram forum topic. Persisting
 * the row is what makes delivery survivable — a committed Support Chat
 * message is never lost because the Universal Telegram adapter is
 * unavailable; the row simply stays retryable.
 *
 * The table holds no message content. `direction`/`origin`/`state`/
 * `last_reason` are fixed-vocabulary strings; the body is read live from
 * `MessageRepository` (decrypted in memory only) at delivery time.
 */
final class DispatchOutboxRepository {

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( SchemaHealth $schema_health ) {
		$this->schema_health = $schema_health;
	}

	/**
	 * Records a Support-Chat-originated message as a pending dispatch,
	 * due immediately. Idempotent on `message_uuid`.
	 *
	 * @param string $message_uuid      Support Chat message UUID.
	 * @param int    $conversation_id   Parent conversation primary key.
	 * @param string $conversation_uuid Parent conversation UUID.
	 * @param string $direction         Message direction (visitor|operator).
	 *
	 * @return bool True when a new pending row was inserted.
	 */
	public function enqueue(
		string $message_uuid,
		int $conversation_id,
		string $conversation_uuid,
		string $direction
	): bool {
		return $this->insert(
			$message_uuid,
			$conversation_id,
			$conversation_uuid,
			$direction,
			DispatchRecord::ORIGIN_SUPPORT_CHAT,
			DispatchRecord::STATE_PENDING,
			null
		);
	}

	/**
	 * Records a Telegram-originated message with a permanent suppression
	 * marker so it can never enter the outbound dispatch path (loop
	 * prevention). Idempotent on `message_uuid`.
	 *
	 * @param string $message_uuid      Support Chat message UUID.
	 * @param int    $conversation_id   Parent conversation primary key.
	 * @param string $conversation_uuid Parent conversation UUID.
	 * @param string $direction         Message direction (operator).
	 *
	 * @return bool True when a new suppression row was inserted.
	 */
	public function mark_telegram_origin(
		string $message_uuid,
		int $conversation_id,
		string $conversation_uuid,
		string $direction
	): bool {
		return $this->insert(
			$message_uuid,
			$conversation_id,
			$conversation_uuid,
			$direction,
			DispatchRecord::ORIGIN_TELEGRAM,
			DispatchRecord::STATE_SUPPRESSED,
			'telegram_origin'
		);
	}

	/**
	 * Inserts one outbox row, converging silently on a duplicate
	 * `message_uuid` (the table's own UNIQUE key).
	 *
	 * @param string      $message_uuid      Support Chat message UUID.
	 * @param int         $conversation_id   Parent conversation primary key.
	 * @param string      $conversation_uuid Parent conversation UUID.
	 * @param string      $direction         Message direction.
	 * @param string      $origin            Message origin.
	 * @param string      $state             Initial state.
	 * @param string|null $reason            Optional non-secret reason.
	 */
	private function insert(
		string $message_uuid,
		int $conversation_id,
		string $conversation_uuid,
		string $direction,
		string $origin,
		string $state,
		?string $reason
	): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		if ( $this->exists( $message_uuid ) ) {
			return false;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE,
			array(
				'message_uuid'      => $message_uuid,
				'conversation_id'   => $conversation_id,
				'conversation_uuid' => $conversation_uuid,
				'direction'         => $direction,
				'origin'            => $origin,
				'state'             => $state,
				'attempts'          => 0,
				'channel_case_ref'  => null,
				'last_reason'       => $reason,
				'next_attempt_at'   => $now,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted && $inserted > 0;
	}

	/**
	 * Whether an outbox row exists for the message UUID (any state).
	 *
	 * @param string $message_uuid Support Chat message UUID.
	 */
	public function exists( string $message_uuid ): bool {
		return null !== $this->find( $message_uuid );
	}

	/**
	 * Finds one outbox row by message UUID.
	 *
	 * @param string $message_uuid Support Chat message UUID.
	 */
	public function find( string $message_uuid ): ?DispatchRecord {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				"SELECT * FROM {$table} WHERE message_uuid = %s LIMIT 1",
				$message_uuid
			),
			ARRAY_A
		);

		return is_array( $row ) ? DispatchRecord::from_row( $row ) : null;
	}

	/**
	 * Finds one row by primary key.
	 *
	 * @param int $id Primary key.
	 */
	public function find_by_id( int $id ): ?DispatchRecord {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? DispatchRecord::from_row( $row ) : null;
	}

	/**
	 * Atomically claims up to $limit due rows, moving each from
	 * `pending`/`failed` to `delivering`. Suppressed, delivered, and
	 * abandoned rows are structurally unreachable here.
	 *
	 * @param int $limit Maximum rows to claim.
	 *
	 * @return array<int, DispatchRecord>
	 */
	public function claim_due( int $limit = 20 ): array {
		if ( ! $this->schema_health->is_available() || $limit < 1 ) {
			return array();
		}

		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				"SELECT id FROM {$table} WHERE state IN ('pending', 'failed') AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				$now,
				$limit
			)
		);

		$claimed = array();

		foreach ( (array) $ids as $raw_id ) {
			$id = (int) $raw_id;

			$won = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
					"UPDATE {$table} SET state = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND state IN ('pending', 'failed')",
					DispatchRecord::STATE_DELIVERING,
					$now,
					$id
				)
			);

			if ( is_int( $won ) && $won > 0 ) {
				$record = $this->find_by_id( $id );
				if ( null !== $record ) {
					$claimed[] = $record;
				}
			}
		}

		return $claimed;
	}

	/**
	 * Records a resolved channel case ref on a row without changing state.
	 *
	 * @param int    $id               Primary key.
	 * @param string $channel_case_ref Resolved adapter channel case ref.
	 */
	public function record_channel_case_ref( int $id, string $channel_case_ref ): void {
		$this->update( $id, array( 'channel_case_ref' => $channel_case_ref ) );
	}

	/**
	 * Marks a row delivered (terminal success).
	 *
	 * @param int $id Primary key.
	 */
	public function mark_delivered( int $id ): void {
		$this->update(
			$id,
			array(
				'state'       => DispatchRecord::STATE_DELIVERED,
				'last_reason' => null,
			)
		);
	}

	/**
	 * Marks a row retryable-failed and schedules the next attempt.
	 *
	 * @param int    $id              Primary key.
	 * @param string $reason          Non-secret failure reason.
	 * @param int    $backoff_seconds Seconds until the row is due again.
	 */
	public function mark_failed( int $id, string $reason, int $backoff_seconds ): void {
		$this->update(
			$id,
			array(
				'state'           => DispatchRecord::STATE_FAILED,
				'last_reason'     => $reason,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $backoff_seconds ) ),
			)
		);
	}

	/**
	 * Marks a row permanently abandoned (terminal, non-retryable).
	 *
	 * @param int    $id     Primary key.
	 * @param string $reason Non-secret reason.
	 */
	public function mark_abandoned( int $id, string $reason ): void {
		$this->update(
			$id,
			array(
				'state'       => DispatchRecord::STATE_ABANDONED,
				'last_reason' => $reason,
			)
		);
	}

	/**
	 * Applies a partial update, always bumping `updated_at`.
	 *
	 * @param int                    $id      Primary key.
	 * @param array<string, ?string> $columns Column => value pairs.
	 */
	private function update( int $id, array $columns ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$columns['updated_at'] = current_time( 'mysql', true );

		$wpdb->update(
			$wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE,
			$columns,
			array( 'id' => $id ),
			array_fill( 0, count( $columns ), '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Counts rows grouped by state (operational diagnostics only).
	 *
	 * @return array<string, int>
	 */
	public function count_by_state(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed diagnostics aggregate.
			"SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['state'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Deletes every outbox row for a conversation (retention purge only).
	 *
	 * @param int $conversation_id Parent conversation primary key.
	 */
	public function delete_for_conversation( int $conversation_id ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE,
			array( 'conversation_id' => $conversation_id ),
			array( '%d' )
		);
	}
}
