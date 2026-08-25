<?php
/**
 * Per-conversation channel status (ADR-0005 §3, ADR-0006).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Support Chat–owned channel availability signal, derived only from
 * authenticated adapter callbacks (`report_channel_unavailable`,
 * `report_delivery_failure`). Never stores a Telegram-native identifier.
 */
class ChannelStatusRepository {

	public const STATUS_AVAILABLE = 'available';
	public const STATUS_DEGRADED  = 'degraded';

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
	 * Marks a conversation's channel degraded.
	 *
	 * @param int    $conversation_id Conversation primary key.
	 * @param string $reason_code     Fixed, non-sensitive reason code.
	 */
	public function mark_degraded( int $conversation_id, string $reason_code ): bool {
		return $this->upsert( $conversation_id, self::STATUS_DEGRADED, $reason_code );
	}

	/**
	 * Marks a conversation's channel available again.
	 *
	 * @param int $conversation_id Conversation primary key.
	 */
	public function mark_available( int $conversation_id ): bool {
		return $this->upsert( $conversation_id, self::STATUS_AVAILABLE, null );
	}

	/**
	 * The current channel status for a conversation. Defaults to available
	 * when no row exists.
	 *
	 * @param int $conversation_id Conversation primary key.
	 *
	 * @return array{status: string, reason_code: string|null}
	 */
	public function status_for( int $conversation_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array(
				'status'      => self::STATUS_AVAILABLE,
				'reason_code' => null,
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CHANNEL_STATUS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, reason_code FROM {$table} WHERE conversation_id = %d LIMIT 1",
				$conversation_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) ) {
			return array(
				'status'      => self::STATUS_AVAILABLE,
				'reason_code' => null,
			);
		}

		return array(
			'status'      => (string) $row['status'],
			'reason_code' => isset( $row['reason_code'] ) && '' !== $row['reason_code'] ? (string) $row['reason_code'] : null,
		);
	}

	/**
	 * Inserts or updates the status row for a conversation.
	 *
	 * @param int         $conversation_id Conversation primary key.
	 * @param string      $status          Status value.
	 * @param string|null $reason_code     Reason code, or null.
	 */
	private function upsert( int $conversation_id, string $status, ?string $reason_code ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CHANNEL_STATUS_TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (conversation_id, status, reason_code, updated_at) VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE status = VALUES(status), reason_code = VALUES(reason_code), updated_at = VALUES(updated_at)",
				$conversation_id,
				$status,
				$reason_code,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $result;
	}
}
