<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Support;

use UniversalSupportChat\Migration\QuiescenceStateProvider;

/**
 * A `QuiescenceStateProvider` test double that reports quiescent for its
 * first `$true_calls` calls to `is_quiescent()`, then flips itself
 * non-quiescent (via `FakeQuiescenceStateProvider::make_not_quiescent()`)
 * for every call after that — simulating a buffered legacy-chat webhook
 * update arriving mid-`run()` (SC-M03 WP3-4 Phase B continuous quiescence
 * re-check addendum), which is exactly the scenario the addendum's
 * `PhaseBReconciliationService::run()`/`reconcile_one()` re-checks exist to
 * catch. Every call to `is_quiescent()` is counted, including the
 * top-of-`run()` check, each loop-top re-check, and each pre-promotion
 * re-check inside `reconcile_one()` — so `$true_calls` must be chosen with
 * that exact call sequence in mind.
 */
final class QuiescenceLossAfterCallsProvider implements QuiescenceStateProvider {

	private FakeQuiescenceStateProvider $fake;

	private int $calls = 0;

	public function __construct( private readonly int $true_calls ) {
		$this->fake = ( new FakeQuiescenceStateProvider() )->make_quiescent();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_quiescent(): bool {
		++$this->calls;

		if ( $this->calls > $this->true_calls ) {
			$this->fake->make_not_quiescent();
		}

		return $this->fake->is_quiescent();
	}

	/**
	 * {@inheritDoc}
	 */
	public function since(): ?\DateTimeImmutable {
		return $this->fake->since();
	}
}
