<?php
/**
 * Phase B: quiescence-gated final reconciliation and validation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;

/**
 * May only run once `QuiescenceStateProvider::is_quiescent()` returns
 * `true` (ADR-0008 §6) and only after a preflight confirms no legacy
 * source rows exist beyond Phase A's recorded high-water mark
 * (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.2). Work packages
 * 3-4 ship only a default-deny production provider and a test fake — this
 * service is proven only against the test seam; **no conversation has ever
 * been validated as cutover-ready by this work package** (ADR-0008 §6,
 * this plan's own closure constraint).
 */
final class PhaseBReconciliationService {

	public const REFUSED_NOT_QUIESCENT   = 'not_quiescent';
	public const REFUSED_NEW_SOURCE_ROWS = 'new_source_rows_since_last_backfill';

	/**
	 * Constructor.
	 *
	 * @param LegacyExportClient                  $export_client Universal Telegram's export boundary.
	 * @param QuiescenceStateProvider              $quiescence    Phase B's sole precondition gate.
	 * @param MessageRepository                    $messages      Target message reads/writes.
	 * @param NoteRepository                       $notes         Target note reads/writes.
	 * @param LegacyMigrationMapRepository          $map           Conversation-level correspondence.
	 * @param LegacyMigrationMessageMapRepository   $message_map   Message/note-level correspondence.
	 * @param LegacyMigrationValidator              $validator     Read-only count/correspondence checks.
	 */
	public function __construct(
		private readonly LegacyExportClient $export_client,
		private readonly QuiescenceStateProvider $quiescence,
		private readonly MessageRepository $messages,
		private readonly NoteRepository $notes,
		private readonly LegacyMigrationMapRepository $map,
		private readonly LegacyMigrationMessageMapRepository $message_map,
		private readonly LegacyMigrationValidator $validator
	) {}

	/**
	 * Runs Phase B once, if and only if its preconditions are satisfied.
	 * A dry run performs the same reconciliation reads and the same
	 * transient content comparisons, but writes nothing — no map row is
	 * promoted, no delta message/note is imported.
	 *
	 * @param bool $dry_run Whether to simulate without writing.
	 *
	 * @return array{status: string, reason?: string, checked?: int, validated?: int, failed?: int}
	 */
	public function run( bool $dry_run ): array {
		if ( ! $this->quiescence->is_quiescent() ) {
			return array(
				'status' => 'refused',
				'reason' => self::REFUSED_NOT_QUIESCENT,
			);
		}

		$high_water_mark = $this->map->high_water_mark();
		$preflight       = $this->export_client->export_batch( $high_water_mark, 1 );

		if ( array() !== $preflight['conversations'] ) {
			return array(
				'status' => 'refused',
				'reason' => self::REFUSED_NEW_SOURCE_ROWS,
			);
		}

		$rows      = $this->map->find_backfilled();
		$validated = 0;
		$failed    = 0;

		foreach ( $rows as $row ) {
			// Re-check immediately before this row's own reconciliation
			// begins — the top-of-run() check above can be stale by the
			// time later rows in the same run are reached (SC-M03 WP3-4
			// Phase B continuous quiescence re-check addendum): a real,
			// live-computed QuiescenceStateProvider can flip `false` mid-run
			// even though nothing changed on this side. PHPStan's static
			// flow analysis cannot know that: it sees the same interface
			// call already made once above and assumes a pure, unchanged
			// result — a real provider is deliberately not pure here.
			if ( ! $this->quiescence->is_quiescent() ) { // @phpstan-ignore booleanNot.alwaysFalse
				return array(
					'status'    => 'refused',
					'reason'    => self::REFUSED_NOT_QUIESCENT,
					'checked'   => $validated + $failed,
					'validated' => $validated,
					'failed'    => $failed,
				);
			}

			try {
				$passed = $this->reconcile_one( $row, $dry_run );
			} catch ( QuiescenceLostDuringReconciliationException $exception ) {
				// reconcile_one() re-checked immediately before its own
				// promotion-to-migrated write and lost quiescence there —
				// stop the whole run now; do not count this row as failed,
				// do not continue to any further row.
				return array(
					'status'    => 'refused',
					'reason'    => self::REFUSED_NOT_QUIESCENT,
					'checked'   => $validated + $failed,
					'validated' => $validated,
					'failed'    => $failed,
				);
			}

			if ( $passed ) {
				++$validated;
			} else {
				++$failed;
			}
		}

		return array(
			'status'    => 'ran',
			'checked'   => count( $rows ),
			'validated' => $validated,
			'failed'    => $failed,
		);
	}

	/**
	 * Re-fetches one conversation from Universal Telegram, imports any
	 * message/note this map row has not seen yet (drift since Phase A),
	 * transiently verifies already-migrated message content still matches,
	 * and promotes the row to `migrated` if every check passes.
	 *
	 * @param LegacyMigrationMapEntry $row     The `backfilled` map row to reconcile.
	 * @param bool                    $dry_run Whether to simulate without writing.
	 *
	 * @throws QuiescenceLostDuringReconciliationException If `is_quiescent()`,
	 *         re-checked immediately before this row's promotion-to-`migrated`
	 *         write, returns `false` — the write is not made; `run()` catches
	 *         this and stops the whole reconciliation pass.
	 */
	private function reconcile_one( LegacyMigrationMapEntry $row, bool $dry_run ): bool {
		$refetch = $this->export_client->export_batch( $row->source_conversation_id() - 1, 1 );

		if ( array() === $refetch['conversations'] ) {
			// The source row is gone (purged) since Phase A copied it —
			// validate against what was already backfilled; nothing new
			// to reconcile, but content already copied is not re-checked
			// without a live source to compare against.
			if ( ! $dry_run ) {
				if ( ! $this->quiescence->is_quiescent() ) {
					throw new QuiescenceLostDuringReconciliationException();
				}

				$this->map->mark_migrated( $row->id(), true, $row->message_count_target(), $row->note_count_target() );
			}

			return true;
		}

		$entry = $refetch['conversations'][0];

		if ( (int) $entry['id'] !== $row->source_conversation_id() || isset( $entry['error'] ) ) {
			if ( ! $dry_run ) {
				$this->map->mark_validation_failed( $row->id() );
			}

			return false;
		}

		$known_message_ids = $this->message_map->source_ids_for_conversation( $row->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE );
		$known_note_ids    = $this->message_map->source_ids_for_conversation( $row->id(), LegacyMigrationMessageMapRepository::KIND_NOTE );

		$new_message_count = 0;
		$new_note_count    = 0;

		foreach ( (array) ( $entry['messages'] ?? array() ) as $message ) {
			if ( in_array( (int) $message['id'], $known_message_ids, true ) ) {
				continue;
			}

			++$new_message_count;

			if ( $dry_run ) {
				continue;
			}

			$target_key = IdempotencyKeyDeriver::for_message( (string) $message['message_uuid'] );
			$target     = $this->messages->import_legacy(
				(int) $row->target_conversation_id(),
				(string) $message['direction'],
				isset( $message['body'] ) ? $message['body'] : null,
				$target_key,
				(string) $message['created_at']
			);

			if ( null === $target ) {
				return false;
			}

			$this->message_map->record( $row->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE, (int) $message['id'], (string) $message['message_uuid'], $target->id(), $target->uuid(), $target_key );
		}

		foreach ( (array) ( $entry['notes'] ?? array() ) as $note ) {
			if ( in_array( (int) $note['id'], $known_note_ids, true ) ) {
				continue;
			}

			if ( ! isset( $note['operator_user_id'] ) ) {
				return false;
			}

			++$new_note_count;

			if ( $dry_run ) {
				continue;
			}

			$target = $this->notes->import_legacy( (int) $row->target_conversation_id(), (int) $note['operator_user_id'], (string) $note['body'], (string) $note['created_at'] );

			if ( null === $target ) {
				return false;
			}

			$placeholder_uuid = IdempotencyKeyDeriver::note_placeholder_uuid( $row->id(), (int) $note['id'] );
			$this->message_map->record( $row->id(), LegacyMigrationMessageMapRepository::KIND_NOTE, (int) $note['id'], $placeholder_uuid, $target->id(), $target->uuid(), null );
		}

		// Transient content-integrity check for every message already
		// known before this reconciliation pass — freshly decrypted on
		// both sides, compared in memory, never persisted beyond this
		// boolean (plan §4.5).
		foreach ( $known_message_ids as $source_message_id ) {
			$target_uuid = $this->message_map->target_uuid_for_source( $row->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE, $source_message_id );

			if ( null === $target_uuid ) {
				return false;
			}

			$source_plaintext = $this->find_message_body( (array) ( $entry['messages'] ?? array() ), $source_message_id );

			if ( ! $this->validator->content_matches_message( $target_uuid, $source_plaintext ) ) {
				return false;
			}
		}

		$final_message_count  = count( $known_message_ids ) + $new_message_count;
		$final_note_count     = count( $known_note_ids ) + $new_note_count;
		$source_message_count = count( (array) ( $entry['messages'] ?? array() ) );
		$source_note_count    = count( (array) ( $entry['notes'] ?? array() ) );

		$passed = ( $final_message_count === $source_message_count ) && ( $final_note_count === $source_note_count );

		if ( ! $dry_run ) {
			if ( $passed ) {
				if ( ! $this->quiescence->is_quiescent() ) {
					throw new QuiescenceLostDuringReconciliationException();
				}

				$this->map->mark_migrated( $row->id(), true, $final_message_count, $final_note_count );
			} else {
				$this->map->mark_validation_failed( $row->id() );
			}
		}

		return $passed;
	}

	/**
	 * Finds one message's plaintext body within a freshly re-exported
	 * conversation's message list.
	 *
	 * @param array<int, array<string, mixed>> $messages         The re-exported message list.
	 * @param int                               $source_message_id The legacy numeric message id to find.
	 */
	private function find_message_body( array $messages, int $source_message_id ): ?string {
		foreach ( $messages as $message ) {
			if ( (int) $message['id'] === $source_message_id ) {
				return isset( $message['body'] ) ? $message['body'] : null;
			}
		}

		return null;
	}
}
