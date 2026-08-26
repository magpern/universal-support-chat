<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop\Support;

use UniversalSupportChat\Migration\LegacyExportClient;

/**
 * A thin decorator around a REAL `LegacyExportClient` (in this suite,
 * always the real `InProcessLegacyExportClient` talking to Universal
 * Telegram's real, merged export boundary) that fires a caller-supplied
 * side effect immediately before one specific, 1-indexed `export_batch()`
 * call is delegated. Every export result itself is always the real
 * delegate's own — this class never fabricates or alters conversation
 * data. It exists solely to land a real, independent event (in
 * `QuiescenceProviderIntegrationTest`, a genuine Universal Telegram
 * webhook update being durably buffered mid-run) at a precise, otherwise
 * untestable point in `PhaseBReconciliationService::run()`'s real
 * multi-row loop, so the addendum's continuous quiescence re-check can be
 * proven against Universal Telegram's real, live-computed state — not
 * simulated by a fake provider flipping on a call count.
 */
final class ExportBatchSideEffectClient implements LegacyExportClient {

	private int $calls = 0;

	/**
	 * @param LegacyExportClient $delegate                 The real export client every call is ultimately forwarded to.
	 * @param int                $side_effect_before_call  The 1-indexed export_batch() call number before which the side effect fires (fires once, then never again).
	 * @param \Closure           $side_effect              Invoked with no arguments immediately before that one delegated call.
	 */
	public function __construct(
		private readonly LegacyExportClient $delegate,
		private readonly int $side_effect_before_call,
		private readonly \Closure $side_effect
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function export_batch( int $after_source_id, int $limit ): array {
		++$this->calls;

		if ( $this->calls === $this->side_effect_before_call ) {
			( $this->side_effect )();
		}

		return $this->delegate->export_batch( $after_source_id, $limit );
	}

	/**
	 * The number of export_batch() calls observed so far — assertion support only.
	 */
	public function calls(): int {
		return $this->calls;
	}
}
