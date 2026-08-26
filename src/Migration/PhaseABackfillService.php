<?php
/**
 * Phase A: repeatable, live-source-safe preparatory backfill.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;

/**
 * Reads legacy conversations from Universal Telegram in ascending-id
 * batches, starting from the migration map's own high-water mark, and
 * writes each one into Support Chat as a single per-conversation
 * transaction (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.2-§4.3).
 * Safe to run repeatedly while Universal Telegram remains live: a prior
 * "completion" is never terminal — the next invocation simply resumes past
 * whatever the map already recorded.
 */
final class PhaseABackfillService {

	/**
	 * Safety valve against an unbounded single CLI invocation; not a
	 * design constraint from the frozen plan — Phase A is meant to be
	 * invoked repeatedly (e.g. on a cron-like operator cadence) rather than
	 * asked to drain an arbitrarily large backlog in one call.
	 */
	private const MAX_BATCHES_PER_RUN = 2000;

	/**
	 * The hard ceiling Universal Telegram's own `LegacyExportServiceV1`
	 * enforces server-side on every `export_batch()` call, regardless of
	 * what is requested (ADR-0008 §5). This engine's own effective batch
	 * size can never exceed it — requesting more would only ever get back
	 * at most this many rows per call, so treating a full 100-row response
	 * to a *larger* request as "short" would stop Phase A early while
	 * further source rows still exist.
	 */
	private const MAX_UT_BATCH_SIZE = 100;

	/**
	 * The smallest batch size this engine will ever actually request,
	 * regardless of what the caller passes.
	 */
	private const MIN_BATCH_SIZE = 1;

	/**
	 * Constructor.
	 *
	 * @param LegacyExportClient                   $export_client Universal Telegram's export boundary.
	 * @param ConversationRepository                $conversations Support Chat conversation writes.
	 * @param MessageRepository                     $messages      Support Chat message writes.
	 * @param NoteRepository                        $notes         Support Chat note writes.
	 * @param LegacyMigrationMapRepository           $map           Conversation-level correspondence.
	 * @param LegacyMigrationMessageMapRepository    $message_map   Message/note-level correspondence.
	 * @param LegacyMigrationRunRepository           $runs          Run-level operational evidence.
	 * @param LegacyMigrationBatchLogRepository      $batch_log     Per-batch operational evidence.
	 */
	public function __construct(
		private readonly LegacyExportClient $export_client,
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly NoteRepository $notes,
		private readonly LegacyMigrationMapRepository $map,
		private readonly LegacyMigrationMessageMapRepository $message_map,
		private readonly LegacyMigrationRunRepository $runs,
		private readonly LegacyMigrationBatchLogRepository $batch_log
	) {}

	/**
	 * Runs Phase A until Universal Telegram reports no further
	 * conversations beyond the current high-water mark, or the per-run
	 * batch safety valve is reached.
	 *
	 * A dry run performs zero writes to any Support Chat table, the map,
	 * the run/batch-log tables, or anything else — it only reports what
	 * would happen.
	 *
	 * @param bool     $dry_run            Whether to simulate without writing.
	 * @param int      $batch_size         Requested export batch size. Clamped to this engine's own
	 *                                      effective size — `min( max( $batch_size, 1 ), 100 )` — before
	 *                                      ever being used, since Universal Telegram's own
	 *                                      `LegacyExportServiceV1` never returns more than 100 rows per
	 *                                      call regardless of what is requested (ADR-0008 §5).
	 * @param int|null $operator_user_id   The invoking operator's WP user id, for the run record only.
	 *
	 * @return array{run_id: int|null, dry_run: bool, batches: int, processed: int, backfilled: int, skipped: int, failed: int}
	 */
	public function run( bool $dry_run, int $batch_size, ?int $operator_user_id = null ): array {
		$effective_batch_size = min( max( $batch_size, self::MIN_BATCH_SIZE ), self::MAX_UT_BATCH_SIZE );

		$run_id = $dry_run ? null : $this->runs->start( LegacyMigrationRunRepository::PHASE_BACKFILL, $effective_batch_size, $operator_user_id );

		$after_id = $this->map->high_water_mark();
		$totals   = array(
			'run_id'     => $run_id,
			'dry_run'    => $dry_run,
			'batches'    => 0,
			'processed'  => 0,
			'backfilled' => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);

		for ( $batch_number = 1; $batch_number <= self::MAX_BATCHES_PER_RUN; $batch_number++ ) {
			$cursor_start = $after_id;
			$export       = $this->export_client->export_batch( $after_id, $effective_batch_size );
			$entries      = $export['conversations'];

			if ( array() === $entries ) {
				break;
			}

			++$totals['batches'];

			$batch_processed  = 0;
			$batch_backfilled = 0;
			$batch_skipped    = 0;
			$batch_failed     = 0;

			foreach ( $entries as $entry ) {
				$source_id = (int) $entry['id'];
				$after_id  = max( $after_id, $source_id );
				++$batch_processed;

				$outcome = $dry_run ? $this->simulate_one( $entry ) : $this->process_one( $entry );

				switch ( $outcome ) {
					case 'backfilled':
						++$batch_backfilled;
						break;
					case 'skipped':
						++$batch_skipped;
						break;
					default:
						++$batch_failed;
						break;
				}
			}

			$totals['processed']  += $batch_processed;
			$totals['backfilled'] += $batch_backfilled;
			$totals['skipped']    += $batch_skipped;
			$totals['failed']     += $batch_failed;

			if ( ! $dry_run && null !== $run_id ) {
				$this->batch_log->record( $run_id, $batch_number, $cursor_start, $after_id, $batch_processed, $batch_backfilled, $batch_skipped, $batch_failed, null );
				$this->runs->advance_checkpoint( $run_id, $after_id );
			}

			if ( count( $entries ) < $effective_batch_size ) {
				// Fewer rows than this engine's own effective request size
				// came back: no more conversations exist beyond this
				// cursor right now. Comparing against the *effective*
				// size (never the raw, possibly-larger, caller-requested
				// value) is what makes this a correct termination
				// signal — a full 100-row response to a >100 request is
				// not "short," it is exactly what Universal Telegram's
				// own per-call ceiling (ADR-0008 §5) always returns when
				// more rows remain.
				break;
			}
		}

		if ( ! $dry_run && null !== $run_id ) {
			$this->runs->finish( $run_id, LegacyMigrationRunRepository::STATUS_COMPLETED );
		}

		return $totals;
	}

	/**
	 * Dry-run simulation: classifies the outcome without writing anything.
	 *
	 * @param array<string, mixed> $entry One exported conversation entry.
	 */
	private function simulate_one( array $entry ): string {
		if ( isset( $entry['error'] ) ) {
			return 'failed';
		}

		if ( ! isset( $entry['owner_user_id'] ) ) {
			return 'skipped';
		}

		foreach ( (array) ( $entry['notes'] ?? array() ) as $note ) {
			if ( ! isset( $note['operator_user_id'] ) ) {
				return 'failed';
			}
		}

		return 'backfilled';
	}

	/**
	 * Processes and writes one conversation, per-conversation-transactional.
	 *
	 * @param array<string, mixed> $entry One exported conversation entry.
	 *
	 * @return string 'backfilled'|'skipped'|'failed'.
	 */
	private function process_one( array $entry ): string {
		$source_id = (int) $entry['id'];

		if ( isset( $entry['error'] ) ) {
			$this->record_terminal_outcome(
				$source_id,
				IdempotencyKeyDeriver::export_error_placeholder_uuid( $source_id ),
				null,
				null,
				null,
				null,
				null,
				false,
				'export_' . (string) $entry['error']
			);

			return 'failed';
		}

		$source_uuid = (string) $entry['conversation_uuid'];

		if ( ! isset( $entry['owner_user_id'] ) ) {
			$this->record_terminal_outcome(
				$source_id,
				$source_uuid,
				isset( $entry['bot_id'] ) ? (int) $entry['bot_id'] : null,
				isset( $entry['destination_id'] ) ? (int) $entry['destination_id'] : null,
				isset( $entry['telegram_topic_id'] ) ? (int) $entry['telegram_topic_id'] : null,
				isset( $entry['topic_creation_state'] ) ? (string) $entry['topic_creation_state'] : null,
				isset( $entry['topic_lifecycle_state'] ) ? (string) $entry['topic_lifecycle_state'] : null,
				true,
				'ownerless_conversation_unsupported'
			);

			return 'skipped';
		}

		foreach ( (array) ( $entry['notes'] ?? array() ) as $note ) {
			if ( ! isset( $note['operator_user_id'] ) ) {
				$this->record_terminal_outcome(
					$source_id,
					$source_uuid,
					isset( $entry['bot_id'] ) ? (int) $entry['bot_id'] : null,
					isset( $entry['destination_id'] ) ? (int) $entry['destination_id'] : null,
					isset( $entry['telegram_topic_id'] ) ? (int) $entry['telegram_topic_id'] : null,
					isset( $entry['topic_creation_state'] ) ? (string) $entry['topic_creation_state'] : null,
					isset( $entry['topic_lifecycle_state'] ) ? (string) $entry['topic_lifecycle_state'] : null,
					false,
					'note_operator_user_id_null_unsupported'
				);

				return 'failed';
			}
		}

		return $this->backfill_within_transaction( $entry ) ? 'backfilled' : 'failed';
	}

	/**
	 * Creates a pending map row and immediately marks it skipped/failed —
	 * a terminal, durable audit outcome for a conversation this engine
	 * will never migrate. Committed unconditionally so a future Phase A
	 * run does not retry it forever.
	 *
	 * @param int         $source_id                    Legacy numeric conversation id.
	 * @param string      $source_uuid                  Legacy conversation UUID, or a deterministic placeholder.
	 * @param int|null    $legacy_bot_id                Preserved for work package 5.
	 * @param int|null    $legacy_destination_id        Preserved for work package 5.
	 * @param int|null    $legacy_telegram_topic_id     Preserved for work package 5.
	 * @param string|null $legacy_topic_creation_state  Preserved for work package 5.
	 * @param string|null $legacy_topic_lifecycle_state Preserved for work package 5.
	 * @param bool        $is_skip                      True for a skip, false for a failure.
	 * @param string      $reason                       A stable, typed audit reason.
	 */
	private function record_terminal_outcome(
		int $source_id,
		string $source_uuid,
		?int $legacy_bot_id,
		?int $legacy_destination_id,
		?int $legacy_telegram_topic_id,
		?string $legacy_topic_creation_state,
		?string $legacy_topic_lifecycle_state,
		bool $is_skip,
		string $reason
	): void {
		$row = $this->map->create_pending(
			$source_id,
			$source_uuid,
			$legacy_bot_id,
			$legacy_destination_id,
			$legacy_telegram_topic_id,
			$legacy_topic_creation_state,
			$legacy_topic_lifecycle_state
		);

		if ( null === $row ) {
			return;
		}

		if ( $is_skip ) {
			$this->map->mark_skipped( $row->id(), $reason );
		} else {
			$this->map->mark_failed( $row->id(), $reason );
		}
	}

	/**
	 * The happy path: one full per-conversation transaction. Any failure
	 * anywhere in this method rolls back every write it made, including the
	 * pending map row itself — so a future Phase A run's high-water mark
	 * does not advance past this source id, and it is retried, never left
	 * as a silent gap.
	 *
	 * @param array<string, mixed> $entry One exported conversation entry.
	 */
	private function backfill_within_transaction( array $entry ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transactional control, no user input.

		try {
			$this->backfill_writes( $entry );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->restore_ambient_test_transaction();

			return true;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->restore_ambient_test_transaction();

			return false;
		}
	}

	/**
	 * The actual writes for one conversation's happy path, expected to run
	 * entirely inside the caller's transaction.
	 *
	 * @param array<string, mixed> $entry One exported conversation entry.
	 *
	 * @throws \RuntimeException If any write fails.
	 */
	private function backfill_writes( array $entry ): void {
		$source_id   = (int) $entry['id'];
		$source_uuid = (string) $entry['conversation_uuid'];

		$map_row = $this->map->create_pending(
			$source_id,
			$source_uuid,
			isset( $entry['bot_id'] ) ? (int) $entry['bot_id'] : null,
			isset( $entry['destination_id'] ) ? (int) $entry['destination_id'] : null,
			isset( $entry['telegram_topic_id'] ) ? (int) $entry['telegram_topic_id'] : null,
			isset( $entry['topic_creation_state'] ) ? (string) $entry['topic_creation_state'] : null,
			isset( $entry['topic_lifecycle_state'] ) ? (string) $entry['topic_lifecycle_state'] : null
		);

		if ( null === $map_row ) {
			throw new \RuntimeException( 'Failed to create migration map row.' );
		}

		if ( ! in_array( (string) $entry['status'], ConversationStatus::all(), true ) ) {
			throw new \RuntimeException( 'Unrecognized legacy conversation status.' );
		}

		$target_start_key = IdempotencyKeyDeriver::for_conversation(
			isset( $entry['start_idempotency_key'] ) ? (string) $entry['start_idempotency_key'] : null,
			$source_uuid
		);

		$target_conversation = $this->conversations->import_legacy(
			(int) $entry['owner_user_id'],
			(string) $entry['status'],
			isset( $entry['assigned_operator_id'] ) ? (int) $entry['assigned_operator_id'] : null,
			$target_start_key,
			(string) $entry['created_at'],
			(string) $entry['updated_at'],
			isset( $entry['resolved_at'] ) ? (string) $entry['resolved_at'] : null,
			isset( $entry['expires_at'] ) ? (string) $entry['expires_at'] : null
		);

		if ( null === $target_conversation ) {
			throw new \RuntimeException( 'Failed to write target conversation row.' );
		}

		$message_count_source = 0;
		$message_count_target = 0;

		foreach ( (array) ( $entry['messages'] ?? array() ) as $message ) {
			++$message_count_source;

			$source_message_id   = (int) $message['id'];
			$source_message_uuid = (string) $message['message_uuid'];
			$target_key          = IdempotencyKeyDeriver::for_message( $source_message_uuid );

			$target_message = $this->messages->import_legacy(
				$target_conversation->id(),
				(string) $message['direction'],
				isset( $message['body'] ) ? $message['body'] : null,
				$target_key,
				(string) $message['created_at']
			);

			if ( null === $target_message ) {
				throw new \RuntimeException( 'Failed to write target message row.' );
			}

			++$message_count_target;

			if ( ! $this->message_map->record( $map_row->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE, $source_message_id, $source_message_uuid, $target_message->id(), $target_message->uuid(), $target_key ) ) {
				throw new \RuntimeException( 'Failed to write message correspondence row.' );
			}
		}

		$note_count_source = 0;
		$note_count_target = 0;

		foreach ( (array) ( $entry['notes'] ?? array() ) as $note ) {
			++$note_count_source;

			$source_note_id = (int) $note['id'];
			$target_note    = $this->notes->import_legacy(
				$target_conversation->id(),
				(int) $note['operator_user_id'],
				(string) $note['body'],
				(string) $note['created_at']
			);

			if ( null === $target_note ) {
				throw new \RuntimeException( 'Failed to write target note row.' );
			}

			++$note_count_target;

			// Notes have no source UUID of their own in the export shape;
			// the message-map schema requires one, so a stable, namespaced,
			// deterministic placeholder is used — never treated as a real
			// legacy identifier, only as this table's own uniqueness key.
			$source_note_placeholder_uuid = IdempotencyKeyDeriver::note_placeholder_uuid( $map_row->id(), $source_note_id );

			if ( ! $this->message_map->record( $map_row->id(), LegacyMigrationMessageMapRepository::KIND_NOTE, $source_note_id, $source_note_placeholder_uuid, $target_note->id(), $target_note->uuid(), null ) ) {
				throw new \RuntimeException( 'Failed to write note correspondence row.' );
			}
		}

		$assignee_last_seen_message_id = null;
		if ( isset( $entry['assignee_last_seen_message_id'] ) ) {
			$assignee_last_seen_message_id = $this->message_map->target_id_for_source_message( $map_row->id(), (int) $entry['assignee_last_seen_message_id'] );
		}

		if ( ! $this->conversations->set_assignee_last_seen_message_id( $target_conversation->id(), $assignee_last_seen_message_id ) ) {
			throw new \RuntimeException( 'Failed to remap assignee_last_seen_message_id.' );
		}

		if ( ! $this->map->mark_backfilled( $map_row->id(), $target_conversation->id(), $target_conversation->uuid(), $message_count_source, $message_count_target, $note_count_source, $note_count_target ) ) {
			throw new \RuntimeException( 'Failed to promote migration map row to backfilled.' );
		}
	}

	/**
	 * Restores WP core's own test-fixture transaction after this method's
	 * explicit COMMIT/ROLLBACK, mirroring `MigrationLock::release()`'s
	 * identical, already-established workaround for the same DDL/DML
	 * transactional-fixture interaction. A no-op in production, where
	 * `WP_TESTS_DOMAIN` is never defined.
	 */
	private function restore_ambient_test_transaction(): void {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
