<?php
/**
 * The sole boundary through which this engine ever reaches Universal
 * Telegram's legacy conversation data.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Exactly Support Chat ADR-0008 §2-§5's export contract, expressed as an
 * interface so `PhaseABackfillService`/`PhaseBReconciliationService` can be
 * tested against a fake without Universal Telegram loaded, and against the
 * real `InProcessLegacyExportClient` in the dual-plugin interop suite. No
 * implementation of this interface may query a `universal_telegram_*`
 * table directly, hold Universal Telegram's vault key material, or add a
 * network path — the only sanctioned call is Universal Telegram's own
 * `LegacyExportServiceV1::export_batch()`, in-process.
 */
interface LegacyExportClient {

	/**
	 * Exports up to `$limit` legacy conversations (server-side capped at
	 * 100 by Universal Telegram regardless of this value) with id greater
	 * than `$after_source_id`, per Support Chat ADR-0008 §5's shape.
	 *
	 * @param int $after_source_id The highest legacy conversation id already processed; 0 for the first batch.
	 * @param int $limit           Requested batch size.
	 *
	 * @return array{export_schema_version: int, conversations: array<int, array<string, mixed>>, error?: string}
	 *
	 * @throws LegacyExportUnavailableException If Universal Telegram is inactive, its export
	 *                                            service is unavailable, or the call is refused
	 *                                            for a reason this engine must treat as a hard
	 *                                            stop rather than "zero conversations."
	 */
	public function export_batch( int $after_source_id, int $limit ): array;
}
