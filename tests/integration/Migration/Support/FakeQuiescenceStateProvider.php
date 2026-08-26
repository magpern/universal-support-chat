<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Support;

use UniversalSupportChat\Migration\QuiescenceStateProvider;

/**
 * The one and only test seam ADR-0008 §6 authorizes for exercising Phase B
 * — never registered for production use anywhere in `Core\Plugin`.
 */
final class FakeQuiescenceStateProvider implements QuiescenceStateProvider {

	private bool $quiescent = false;

	private ?\DateTimeImmutable $since = null;

	/**
	 * Sets this fake to report quiescent, since the given moment.
	 */
	public function make_quiescent( ?\DateTimeImmutable $since = null ): self {
		$this->quiescent = true;
		$this->since     = $since ?? new \DateTimeImmutable();

		return $this;
	}

	/**
	 * Flips this fake back to non-quiescent — used to simulate quiescence
	 * being lost mid-run (SC-M03 WP3-4 Phase B continuous quiescence
	 * re-check addendum).
	 */
	public function make_not_quiescent(): self {
		$this->quiescent = false;
		$this->since     = null;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_quiescent(): bool {
		return $this->quiescent;
	}

	/**
	 * {@inheritDoc}
	 */
	public function since(): ?\DateTimeImmutable {
		return $this->since;
	}
}
