<?php
/**
 * Persistence for the conversation-level source-to-target migration map.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * The authoritative, queryable source-to-target correspondence for
 * conversations (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.3).
 * Every column here holds only IDs, timestamps, booleans, and short stable
 * reason strings — never plaintext or a content-derived digest.
 */
final class LegacyMigrationMapRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * The highest legacy conversation id this engine has ever recorded a
	 * map row for — the resumable high-water mark Phase A resumes from.
	 */
	public function high_water_mark(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$max = $wpdb->get_var( "SELECT MAX(source_conversation_id) FROM {$table}" );

		return null === $max ? 0 : (int) $max;
	}

	/**
	 * Creates a `pending` map row for a legacy conversation, preserving the
	 * topic/binding-relevant fields work package 5 will need. Idempotent by
	 * `source_conversation_id` — a repeat call for an already-known source
	 * id returns the existing row unchanged.
	 *
	 * @param int         $source_conversation_id       Legacy numeric conversation id.
	 * @param string      $source_conversation_uuid     Legacy conversation UUID.
	 * @param int|null    $legacy_bot_id                Legacy bot id.
	 * @param int|null    $legacy_destination_id        Legacy destination id.
	 * @param int|null    $legacy_telegram_topic_id     Legacy Telegram topic id.
	 * @param string|null $legacy_topic_creation_state  Legacy topic creation state.
	 * @param string|null $legacy_topic_lifecycle_state Legacy topic lifecycle state.
	 */
	public function create_pending(
		int $source_conversation_id,
		string $source_conversation_uuid,
		?int $legacy_bot_id,
		?int $legacy_destination_id,
		?int $legacy_telegram_topic_id,
		?string $legacy_topic_creation_state,
		?string $legacy_topic_lifecycle_state
	): ?LegacyMigrationMapEntry {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		$existing = $this->find_by_source_id( $source_conversation_id );
		if ( null !== $existing ) {
			return $existing;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'source_conversation_id'       => $source_conversation_id,
				'source_conversation_uuid'     => $source_conversation_uuid,
				'status'                       => LegacyMigrationMapEntry::STATUS_PENDING,
				'legacy_bot_id'                => $legacy_bot_id,
				'legacy_destination_id'        => $legacy_destination_id,
				'legacy_telegram_topic_id'     => $legacy_telegram_topic_id,
				'legacy_topic_creation_state'  => $legacy_topic_creation_state,
				'legacy_topic_lifecycle_state' => $legacy_topic_lifecycle_state,
				'created_at'                   => $now,
				'updated_at'                   => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return $this->find_by_source_id( $source_conversation_id );
		}

		return $this->find_by_source_id( $source_conversation_id );
	}

	/**
	 * Promotes a map row to `backfilled`, recording the target row and
	 * counts. The only mutation Phase A ever applies to a map row it just
	 * created within the same transaction.
	 *
	 * @param int $id                    Map row primary key.
	 * @param int $target_conversation_id Target conversation primary key.
	 * @param string $target_conversation_uuid Target conversation UUID.
	 * @param int $message_count_source  Source message count.
	 * @param int $message_count_target  Target message count.
	 * @param int $note_count_source     Source note count.
	 * @param int $note_count_target     Target note count.
	 */
	public function mark_backfilled(
		int $id,
		int $target_conversation_id,
		string $target_conversation_uuid,
		int $message_count_source,
		int $message_count_target,
		int $note_count_source,
		int $note_count_target
	): bool {
		return $this->update(
			$id,
			array(
				'target_conversation_id'   => $target_conversation_id,
				'target_conversation_uuid' => $target_conversation_uuid,
				'status'                   => LegacyMigrationMapEntry::STATUS_BACKFILLED,
				'message_count_source'     => $message_count_source,
				'message_count_target'     => $message_count_target,
				'note_count_source'        => $note_count_source,
				'note_count_target'        => $note_count_target,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * Marks a map row `skipped` with a durable, queryable audit reason —
	 * never a failure, and never a partial target row (PO decision record item 3).
	 *
	 * @param int    $id     Map row primary key.
	 * @param string $reason A stable, typed reason.
	 */
	public function mark_skipped( int $id, string $reason ): bool {
		return $this->update(
			$id,
			array(
				'status'       => LegacyMigrationMapEntry::STATUS_SKIPPED,
				'error_reason' => $reason,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Marks a map row `failed` with a durable, queryable audit reason.
	 *
	 * @param int    $id     Map row primary key.
	 * @param string $reason A stable, typed reason.
	 */
	public function mark_failed( int $id, string $reason ): bool {
		return $this->update(
			$id,
			array(
				'status'       => LegacyMigrationMapEntry::STATUS_FAILED,
				'error_reason' => $reason,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Promotes a `backfilled` row to `migrated` after Phase B validates it.
	 *
	 * @param int  $id                Map row primary key.
	 * @param bool $validation_passed Whether Phase B's validation passed.
	 * @param int  $message_count_target Refreshed target message count.
	 * @param int  $note_count_target    Refreshed target note count.
	 */
	public function mark_migrated( int $id, bool $validation_passed, int $message_count_target, int $note_count_target ): bool {
		$now = current_time( 'mysql', true );

		return $this->update(
			$id,
			array(
				'status'               => LegacyMigrationMapEntry::STATUS_MIGRATED,
				'validation_passed'    => $validation_passed ? 1 : 0,
				'validated_at'         => $now,
				'migrated_at'          => $now,
				'message_count_target' => $message_count_target,
				'note_count_target'    => $note_count_target,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Records a failed Phase B validation attempt without promoting the row.
	 *
	 * @param int $id Map row primary key.
	 */
	public function mark_validation_failed( int $id ): bool {
		return $this->update(
			$id,
			array(
				'validation_passed' => 0,
				'validated_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Finds a map row by its legacy source conversation id.
	 *
	 * @param int $source_conversation_id Legacy numeric conversation id.
	 */
	public function find_by_source_id( int $source_conversation_id ): ?LegacyMigrationMapEntry {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_conversation_id = %d LIMIT 1", $source_conversation_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? LegacyMigrationMapEntry::from_row( $row ) : null;
	}

	/**
	 * Every map row currently `backfilled`, in ascending source-id order —
	 * Phase B's own worklist.
	 *
	 * @param int $limit Max rows.
	 *
	 * @return array<int, LegacyMigrationMapEntry>
	 */
	public function find_backfilled( int $limit = 500 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY source_conversation_id ASC LIMIT %d",
				LegacyMigrationMapEntry::STATUS_BACKFILLED,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ) => LegacyMigrationMapEntry::from_row( $row ), $rows );
	}

	/**
	 * Counts map rows per status — the `legacy-migrate status` CLI
	 * subcommand's aggregate operational evidence.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_status(): array {
		$counts = array(
			LegacyMigrationMapEntry::STATUS_PENDING    => 0,
			LegacyMigrationMapEntry::STATUS_BACKFILLED => 0,
			LegacyMigrationMapEntry::STATUS_MIGRATED   => 0,
			LegacyMigrationMapEntry::STATUS_SKIPPED    => 0,
			LegacyMigrationMapEntry::STATUS_FAILED     => 0,
		);

		if ( ! $this->schema_health->is_available() ) {
			return $counts;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Applies a partial update to one map row, always bumping `updated_at`.
	 *
	 * @param int                   $id     Map row primary key.
	 * @param array<string, mixed>  $data   Columns to update.
	 * @param array<int, string>    $formats wpdb format specifiers for $data, in the same order.
	 */
	private function update( int $id, array $data, array $formats ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table              = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;
		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$updated = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		return false !== $updated;
	}
}
