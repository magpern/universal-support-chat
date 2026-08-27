<?php
/**
 * SC-M03 work package 5 orchestrator: candidate identification, the
 * early quiescence pre-check, and outcome persistence for binding
 * preparation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Owns exactly what Support Chat ADR-0009 assigns to this repository:
 * identifying candidates entirely from its own already-finalized
 * `legacy_migration_map` rows (§1.1, no new read from Universal Telegram
 * needed), the structural eligibility checks (§2 items 2-6), and writing
 * every outcome back to the map row. It never writes to Universal
 * Telegram's own binding table itself — every actual binding write
 * happens inside `LegacyBindingImportClient::import_batch()`, entirely on
 * Universal Telegram's own side.
 */
final class LegacyBindingImportService {

	/**
	 * Constructor.
	 *
	 * @param LegacyMigrationMapRepository $map        Candidate source and outcome writer.
	 * @param LegacyBindingImportClient    $client     The in-process Universal Telegram write boundary.
	 * @param QuiescenceStateProvider      $quiescence Early, non-authoritative pre-check only (ADR-0009 §5).
	 */
	public function __construct(
		private readonly LegacyMigrationMapRepository $map,
		private readonly LegacyBindingImportClient $client,
		private readonly QuiescenceStateProvider $quiescence
	) {}

	/**
	 * Prepares up to `$limit` eligible bindings. `$dry_run` exercises the
	 * full pipeline, including the in-process call into Universal
	 * Telegram's own live re-check and lock-scoped quiescence assertion,
	 * but commits no write on either side of the boundary.
	 *
	 * @param bool $dry_run When true, commits no write on either side.
	 * @param int  $limit   Max candidates for this invocation.
	 *
	 * @return array<string, bool|int|string|null>
	 */
	public function run( bool $dry_run, int $limit = 100 ): array {
		$summary = array(
			'refused'   => false,
			'reason'    => null,
			'checked'   => 0,
			'created'   => 0,
			'skipped'   => 0,
			'conflict'  => 0,
			'retryable' => 0,
		);

		// Early, cheap, non-authoritative refusal (ADR-0009 §5) — stops an
		// obviously-doomed run before any Universal Telegram call at all.
		// The authoritative guard is Universal Telegram's own lock-scoped
		// assertion inside import_batch(), not this check.
		if ( ! $this->quiescence->is_quiescent() ) {
			$summary['refused'] = true;
			$summary['reason']  = 'not_quiescent';

			return $summary;
		}

		$rows = $this->map->find_bindable( $limit );

		$rows_by_source_id = array();
		foreach ( $rows as $row ) {
			++$summary['checked'];

			$structural_outcome = $this->structural_outcome( $row );
			if ( null !== $structural_outcome ) {
				if ( ! $dry_run ) {
					$this->map->mark_binding_terminal( $row->id(), $structural_outcome );
				}
				++$summary[ LegacyBindingOutcome::binding_status_for( $structural_outcome ) ];
				continue;
			}

			$rows_by_source_id[ $row->source_conversation_id() ] = $row;
		}

		if ( array() === $rows_by_source_id ) {
			return $summary;
		}

		$candidates = array();
		foreach ( $rows_by_source_id as $source_id => $row ) {
			$candidates[] = array(
				'source_conversation_id'    => $source_id,
				'bot_id'                    => (int) $row->legacy_bot_id(),
				'destination_id'            => (int) $row->legacy_destination_id(),
				'telegram_topic_id'         => (int) $row->legacy_telegram_topic_id(),
				'support_conversation_uuid' => (string) $row->target_conversation_uuid(),
			);
		}

		try {
			$results = $this->client->import_batch( $candidates, $dry_run );
		} catch ( LegacyBindingImportUnavailableException $exception ) {
			foreach ( $rows_by_source_id as $row ) {
				if ( ! $dry_run ) {
					$this->map->mark_binding_retry( $row->id(), LegacyBindingOutcome::RETRY_UT_UNAVAILABLE_OR_INDETERMINATE );
				}
				++$summary['retryable'];
			}

			return $summary;
		}

		foreach ( $results as $result ) {
			$row = $rows_by_source_id[ $result['source_conversation_id'] ] ?? null;
			if ( null === $row ) {
				continue;
			}

			$outcome = (string) $result['outcome'];

			if ( LegacyBindingOutcome::is_terminal( $outcome ) ) {
				if ( ! $dry_run ) {
					$this->map->mark_binding_terminal( $row->id(), $outcome, $result['binding_uuid'] ?? null );
				}
				++$summary[ LegacyBindingOutcome::binding_status_for( $outcome ) ];
				continue;
			}

			if ( ! $dry_run ) {
				$this->map->mark_binding_retry( $row->id(), $outcome );
			}
			++$summary['retryable'];
		}

		return $summary;
	}

	/**
	 * A read-only structural preview: every currently-bindable row's
	 * would-be structural eligibility outcome (§2 items 2-6), without any
	 * Universal Telegram call and without any write. Distinct from `run
	 * --dry-run`, which exercises the full pipeline including Universal
	 * Telegram's own live re-check.
	 *
	 * @param int $limit Max rows to preview.
	 *
	 * @return array{checked:int, structurally_eligible:int, structurally_excluded:int}
	 */
	public function validate( int $limit = 10000 ): array {
		$checked  = 0;
		$eligible = 0;
		$excluded = 0;

		foreach ( $this->map->find_bindable( $limit ) as $row ) {
			++$checked;

			if ( null === $this->structural_outcome( $row ) ) {
				++$eligible;
			} else {
				++$excluded;
			}
		}

		return array(
			'checked'               => $checked,
			'structurally_eligible' => $eligible,
			'structurally_excluded' => $excluded,
		);
	}

	/**
	 * The structural, SC-owned eligibility checks (ADR-0009 §2 items 2-6) —
	 * every check this repository can perform entirely from its own
	 * already-finalized map row, without any Universal Telegram call.
	 *
	 * @param LegacyMigrationMapEntry $row The map row to check.
	 *
	 * @return string|null One of `LegacyBindingOutcome`'s structural
	 *                       constants, or null if structurally eligible.
	 */
	private function structural_outcome( LegacyMigrationMapEntry $row ): ?string {
		if ( null === $row->legacy_telegram_topic_id() ) {
			return LegacyBindingOutcome::SKIP_NO_TOPIC;
		}

		if ( null === $row->legacy_bot_id() || null === $row->legacy_destination_id() ) {
			return LegacyBindingOutcome::SKIP_MISSING_BOT_OR_DESTINATION;
		}

		if ( 'created' !== $row->legacy_topic_creation_state() ) {
			return LegacyBindingOutcome::SKIP_TOPIC_NOT_CREATED;
		}

		if ( 'active' !== $row->legacy_topic_lifecycle_state() ) {
			return LegacyBindingOutcome::SKIP_TOPIC_LIFECYCLE_TERMINAL;
		}

		if ( null === $row->target_conversation_uuid() ) {
			return LegacyBindingOutcome::SKIP_NO_TARGET_CONVERSATION;
		}

		return null;
	}
}
