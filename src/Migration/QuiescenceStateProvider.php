<?php
/**
 * Frozen quiescence-signal contract (ADR-0008 §6).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Phase B's sole precondition gate. This exact interface is frozen by
 * ADR-0008 §6 and is binding on work package 2's future real
 * implementation: no later milestone may redefine what "quiescent" means,
 * add a bypass flag, or let Phase B proceed through any path other than a
 * `true` result from an object satisfying this interface.
 */
interface QuiescenceStateProvider {

	/**
	 * Whether every Universal Telegram legacy chat mutation source is
	 * currently blocked/drained (ADR-0004 "Quiescence"). Work packages 3-4
	 * ship only a default-deny stub (always `false`) and a test fake; a
	 * real implementation is work package 2's own, separate, future scope.
	 */
	public function is_quiescent(): bool;

	/**
	 * When quiescence began, if currently quiescent — evidence only, never
	 * itself a precondition Phase B checks.
	 */
	public function since(): ?\DateTimeImmutable;
}
