<?php
/**
 * Persistence for per-batch operational evidence.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * One row per `LegacyExportClient::export_batch()` call processed —
 * aggregate counts only, never plaintext or a content-derived value.
 * Never written during a dry run.
 */
final class LegacyMigrationBatchLogRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Records one processed batch.
	 *
	 * @param int         $run_id          Owning run.
	 * @param int         $batch_number    1-based batch sequence number within the run.
	 * @param int         $cursor_start    The `after_source_id` this batch was requested with.
	 * @param int         $cursor_end      The highest source id this batch actually processed.
	 * @param int         $rows_processed  Conversations seen in this batch.
	 * @param int         $rows_migrated   Conversations backfilled/migrated in this batch.
	 * @param int         $rows_skipped    Conversations skipped in this batch.
	 * @param int         $rows_failed     Conversations failed in this batch.
	 * @param string|null $error_summary   A short, stable, non-content summary if anything failed.
	 */
	public function record(
		int $run_id,
		int $batch_number,
		int $cursor_start,
		int $cursor_end,
		int $rows_processed,
		int $rows_migrated,
		int $rows_skipped,
		int $rows_failed,
		?string $error_summary
	): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_BATCH_LOG_TABLE;
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'run_id'         => $run_id,
				'batch_number'   => $batch_number,
				'cursor_start'   => $cursor_start,
				'cursor_end'     => $cursor_end,
				'rows_processed' => $rows_processed,
				'rows_migrated'  => $rows_migrated,
				'rows_skipped'   => $rows_skipped,
				'rows_failed'    => $rows_failed,
				'started_at'     => $now,
				'completed_at'   => $now,
				'error_summary'  => $error_summary,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}
}
