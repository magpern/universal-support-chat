<?php
/**
 * Thrown when quiescence is lost between a reconciliation step starting and
 * its promotion-to-migrated write.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Internal signal used only within `PhaseBReconciliationService` (SC-M03
 * WP3-4 Phase B continuous quiescence re-check addendum,
 * `docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md`).
 * `reconcile_one()` throws this immediately before a promotion-to-`migrated`
 * write when its own re-check of `is_quiescent()` returns `false`, so that
 * `run()` can distinguish "this row failed validation" (a normal `false`
 * return from `reconcile_one()`) from "quiescence was lost mid-run, stop
 * everything now" — the latter must abort the whole run and return a
 * refused result, not merely count one more row as failed.
 */
final class QuiescenceLostDuringReconciliationException extends \RuntimeException {

}
