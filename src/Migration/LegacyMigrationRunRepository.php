<?php
/**
 * Persistence for one migration run's own state.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * A `legacy_migration_runs` row exists only for operational evidence and
 * crash-resume convenience — the migration map table (keyed by
 * `source_conversation_id`) remains the actual source of truth for what
 * has already been processed, so a dry run never persists a row here.
 */
final class LegacyMigrationRunRepository {

	public const PHASE_BACKFILL  = 'backfill';
	public const PHASE_RECONCILE = 'reconcile';

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Starts a new run row. Never called for a dry run.
	 *
	 * @param string   $phase              One of the PHASE_* constants.
	 * @param int      $batch_size         The batch size this run was invoked with.
	 * @param int|null $created_by_user_id The operator's WP user id, if invoked interactively.
	 */
	public function start( string $phase, int $batch_size, ?int $created_by_user_id ): ?int {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_RUNS_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'run_uuid'           => wp_generate_uuid4(),
				'phase'              => $phase,
				'status'             => self::STATUS_RUNNING,
				'dry_run'            => 0,
				'batch_size'         => $batch_size,
				'checkpoint_cursor'  => 0,
				'started_at'         => current_time( 'mysql', true ),
				'created_by_user_id' => $created_by_user_id,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);

		return false === $inserted ? null : (int) $wpdb->insert_id;
	}

	/**
	 * Advances the run's checkpoint cursor — called only after a
	 * conversation's own transaction has already committed.
	 *
	 * @param int $run_id                 Run primary key.
	 * @param int $checkpoint_source_id   The source conversation id just committed.
	 */
	public function advance_checkpoint( int $run_id, int $checkpoint_source_id ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_RUNS_TABLE;

		$wpdb->update(
			$table,
			array( 'checkpoint_cursor' => $checkpoint_source_id ),
			array( 'id' => $run_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a run complete or failed.
	 *
	 * @param int    $run_id Run primary key.
	 * @param string $status STATUS_COMPLETED or STATUS_FAILED.
	 */
	public function finish( int $run_id, string $status ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_RUNS_TABLE;

		$wpdb->update(
			$table,
			array(
				'status'       => $status,
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $run_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
