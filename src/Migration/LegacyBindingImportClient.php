<?php
/**
 * The sole boundary through which this engine ever writes a Universal
 * Telegram binding row.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Exactly Support Chat ADR-0009 §2's write contract, expressed as an
 * interface so the future orchestrator can be tested against a fake
 * without Universal Telegram loaded, and against the real
 * `InProcessLegacyBindingImportClient` in the dual-plugin interop suite.
 * No implementation of this interface may query a `universal_telegram_*`
 * table directly, or add a network path — the only sanctioned call is
 * Universal Telegram's own `LegacyBindingImportServiceV1::import_batch()`,
 * in-process.
 */
interface LegacyBindingImportClient {

	/**
	 * Prepares up to `min( count( $candidates ), 100 )` bindings (server-side
	 * capped by Universal Telegram regardless of batch size), per Support
	 * Chat ADR-0009 §2-§4.
	 *
	 * @param array<int, array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string}> $candidates
	 * @param bool $dry_run When true, Universal Telegram commits no write.
	 *
	 * @return array<int, array{source_conversation_id:int, outcome:string, binding_uuid:?string}>
	 *
	 * @throws LegacyBindingImportUnavailableException If Universal Telegram is inactive, its
	 *                                                   binding-import service is unavailable, or
	 *                                                   the whole call is refused for a reason
	 *                                                   this engine must treat as a hard stop for
	 *                                                   the batch, not a per-candidate outcome.
	 */
	public function import_batch( array $candidates, bool $dry_run ): array;
}
