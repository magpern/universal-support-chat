<?php
/**
 * Production-registered default-deny QuiescenceStateProvider.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * The only `QuiescenceStateProvider` implementation work packages 3-4 ship
 * for production use (ADR-0008 §6). `is_quiescent()` unconditionally
 * returns `false`, making it structurally impossible for Phase B to run
 * against production data until work package 2 registers a real provider
 * satisfying this same frozen interface. Never configurable, never a
 * bypassable stub — there is no flag or filter anywhere that changes its
 * return value.
 */
final class DefaultDenyQuiescenceStateProvider implements QuiescenceStateProvider {

	/**
	 * Always `false` — no real quiescence signal exists until work package 2.
	 */
	public function is_quiescent(): bool {
		return false;
	}

	/**
	 * Always `null` — never quiescent, so never a "since" to report.
	 */
	public function since(): ?\DateTimeImmutable {
		return null;
	}
}
