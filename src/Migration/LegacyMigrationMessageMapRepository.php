<?php
/**
 * Persistence for the message/note-level source-to-target migration map.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * The authoritative, queryable per-message/per-note correspondence
 * (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.3) — what makes
 * `assignee_last_seen_message_id` remapping mechanical and what Phase B's
 * reconciliation diff uses to detect a specific source row it hasn't seen
 * yet. Deliberately carries no Telegram-correlation field of any kind
 * (`telegram_message_id`/`outbound_message_uuid` are excluded at the
 * Universal Telegram export source per ADR-0008 §5 and never reach this
 * engine at all).
 */
final class LegacyMigrationMessageMapRepository {

	public const KIND_MESSAGE = 'message';
	public const KIND_NOTE    = 'note';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Records one message/note's source-to-target correspondence.
	 *
	 * @param int    $conversation_map_id Owning conversation map row.
	 * @param string $kind                One of the KIND_* constants.
	 * @param int    $source_id           Legacy numeric message/note id.
	 * @param string $source_uuid         Legacy message/note UUID.
	 * @param int         $target_id           Target message/note primary key.
	 * @param string      $target_uuid         Target message/note UUID.
	 * @param string|null $idempotency_key     The derived target idempotency key, or null (notes carry none).
	 */
	public function record(
		int $conversation_map_id,
		string $kind,
		int $source_id,
		string $source_uuid,
		int $target_id,
		string $target_uuid,
		?string $idempotency_key
	): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_map_id' => $conversation_map_id,
				'kind'                => $kind,
				'source_id'           => $source_id,
				'source_uuid'         => $source_uuid,
				'target_id'           => $target_id,
				'target_uuid'         => $target_uuid,
				'idempotency_key'     => $idempotency_key,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Resolves a source message id to its target message primary key —
	 * what makes remapping `conversations.assignee_last_seen_message_id`
	 * mechanical. Returns `null` if the referenced source message was
	 * never migrated (excluded/failed), never as an error.
	 *
	 * @param int $conversation_map_id Owning conversation map row.
	 * @param int $source_message_id  Legacy numeric message id.
	 */
	public function target_id_for_source_message( int $conversation_map_id, int $source_message_id ): ?int {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$target_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT target_id FROM {$table} WHERE conversation_map_id = %d AND kind = %s AND source_id = %d LIMIT 1",
				$conversation_map_id,
				self::KIND_MESSAGE,
				$source_message_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $target_id ? null : (int) $target_id;
	}

	/**
	 * Resolves a source message/note id to its target row's UUID — used by
	 * Phase B's transient content-integrity comparison (never persisted;
	 * the target UUID is only how the target row is looked back up for a
	 * fresh decrypt).
	 *
	 * @param int    $conversation_map_id Owning conversation map row.
	 * @param string $kind                One of the KIND_* constants.
	 * @param int    $source_id           Legacy numeric message/note id.
	 */
	public function target_uuid_for_source( int $conversation_map_id, string $kind, int $source_id ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$uuid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT target_uuid FROM {$table} WHERE conversation_map_id = %d AND kind = %s AND source_id = %d LIMIT 1",
				$conversation_map_id,
				$kind,
				$source_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $uuid ? null : (string) $uuid;
	}

	/**
	 * Every source id of a given kind already recorded for one conversation
	 * — Phase B's own "which rows have I already imported" set.
	 *
	 * @param int    $conversation_map_id Owning conversation map row.
	 * @param string $kind                One of the KIND_* constants.
	 *
	 * @return array<int, int>
	 */
	public function source_ids_for_conversation( int $conversation_map_id, string $kind ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT source_id FROM {$table} WHERE conversation_map_id = %d AND kind = %s",
				$conversation_map_id,
				$kind
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? array_map( 'intval', $rows ) : array();
	}
}
