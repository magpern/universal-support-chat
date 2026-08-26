# Closure Addendum — SC-M03 Work Packages 3–4: Phase B Continuous Quiescence Re-check

## Relationship to the original closure

This is an **addendum** to `docs/closure/sc-m03-work-packages-3-4-legacy-migration-engine-closure.md`
(PASS, pending Product Owner acceptance). Per that closure's own "Next task"
section, it is triggered by Universal Telegram's SC-M03 Work Package 2 design
freeze (`magpern/universal-telegram` ADR-0040,
`docs/adr/0040-legacy-chat-quiescence-write-blocking-and-drain.md`), which
supplies the first non-default-deny `QuiescenceStateProvider` implementation
this engine will ever consume. The original closure record is not edited —
per this repository's own plan-immutability convention
(`docs/plans/README.md`, "once committed, a plan is immutable... a required
change is a new file that supersedes the prior plan; the prior plan file is
never edited or deleted"), applied here to the closure record it accompanies.

## What triggers this addendum

Support Chat ADR-0008 §6 (immutable per `docs/adr/README.md`'s ADR
immutability rule — only `Status` may change post-acceptance) already
requires: **"Phase B must call `is_quiescent()` as its sole precondition gate
and must refuse to run... whenever it returns `false`."** The WP3-4
implementation satisfies this literally but minimally: `PhaseBReconciliationService::run()`
(`src/Migration/PhaseBReconciliationService.php:62`) calls `is_quiescent()`
exactly once, as the first statement of `run()`, before any reconciliation
work begins.

Against `DefaultDenyQuiescenceStateProvider` (the only production
implementation that has ever existed until now), a single check is
sufficient by construction — the value is permanently `false`, so `run()`
can never proceed past it regardless of how many times it is checked.

Once Universal Telegram ships a real, non-default-deny provider (ADR-0040),
`is_quiescent()` becomes a value that can change **during** a single `run()`
call: ADR-0040 §8 defines `is_quiescent()` as `state === 'quiescent' AND deferred_update_backlog_count() === 0`
— live-computed, not latched. A single Telegram webhook update arriving
mid-reconciliation flips the deferred-update backlog non-empty, and
`is_quiescent()` immediately becomes `false` again, **without any explicit
quiescence-state transition** on Universal Telegram's side. A `run()` that
checked only once at entry would not observe this: it would continue
reconciling and could promote map rows to `migrated` (`reconcile_one()`,
`PhaseBReconciliationService.php:110`) using a `true` result that is no
longer current — exactly the class of stale-precondition defect ADR-0008 §6
exists to prevent, now newly reachable because the precondition is live for
the first time.

## Required amendment

**`PhaseBReconciliationService::run()` must re-check `is_quiescent()`:**

1. **Before each reconciliation batch/transaction** — concretely, immediately
   before each `reconcile_one()` call inside the `foreach ( $rows as $row )`
   loop (`PhaseBReconciliationService.php:83-91`), not only once before the
   loop begins.
2. **Immediately before whatever step commits or promotes final validation
   results** — concretely, immediately before `reconcile_one()`'s own
   promotion-to-`migrated` write, so that even a check passing at the top of
   one loop iteration cannot be stale by the time that same iteration's
   promotion actually commits.

**On any re-check returning `false`:** `run()` must stop immediately, must
not begin (or must roll back, if already open) the write for the row in
progress, must commit no further reconciliation work from that point
onward, and must return a refused result — using the same
`REFUSED_NOT_QUIESCENT` reason the initial check already uses (no new reason
code; this is not a new failure mode, it is the same failure mode detected
later). Rows already validated/promoted earlier in the same `run()` before
the re-check failed remain promoted — this amendment prevents further
promotion past the point quiescence was lost, it does not retroactively
unpromote rows that were genuinely quiescent-gated at the moment they
committed. `run()`'s return value must distinguish "refused before starting"
from "refused partway through" only by the existing `checked`/`validated`/
`failed` counts already returned today — no new return shape is introduced.

**This is a stricter implementation of ADR-0008 §6, not a bypass, not a new
transport contract, and not an interface change.** `QuiescenceStateProvider`'s
two methods (`is_quiescent(): bool`, `since(): ?DateTimeImmutable`) are
unchanged; this amendment only changes how many times, and at which points,
`PhaseBReconciliationService` calls the existing method. ADR-0008 §6's own
text already anticipated this class of change without requiring a new ADR:
"If work package 2's design later needs richer signal than a boolean... that
is an explicit, reviewed interface extension" (interface change, would need
a new ADR) is explicitly distinguished there from a caller-side change in
how an existing interface is consumed (this amendment) — this amendment is
the latter, not the former.

## Registration amendment (composition root only, also not an ADR-level change)

`Core\Plugin.php`'s existing `DefaultDenyQuiescenceStateProvider` registration
is replaced, through ordinary composition-root wiring, by a new
`UniversalTelegramQuiescenceStateProvider` (namespace
`UniversalSupportChat\Migration`) implementing `QuiescenceStateProvider` by
delegating in-process to Universal Telegram's `quiescence_status()` accessor
(ADR-0040 §8), following the exact defensive-call shape
`InProcessLegacyExportClient` already uses (`class_exists()` →
`method_exists()`/null-check → try/catch). This is "an ordinary composition-
root decision, not a flag Phase B inspects or can be told to ignore" — the
same framing ADR-0008 §6 already uses for the default-deny stub's own
registration. No new ADR is required for this swap by itself; it is the
required amendment above (§ "Required amendment") that is the substantive
change needing this addendum and Product Owner review.

## What this addendum does not authorize

- No change to `QuiescenceStateProvider`'s interface.
- No production quiescence operation, cutover, route switch, soak, or
  rollback — those remain out of scope for both SC-M03 WP2 (Universal
  Telegram ADR-0040) and this addendum.
- No re-opening of the original WP3-4 closure's own scope, findings, or
  PASS status — this addendum adds a new requirement triggered by a new
  downstream dependency (a real provider existing for the first time), it
  does not revise anything the original closure already evaluated against
  `DefaultDenyQuiescenceStateProvider` and the fake test seam.
- No implementation. This addendum is documentation-only, per
  `docs/governance.md`'s freeze model: the amendment is described and
  authorized here; `PhaseBReconciliationService.php`'s actual code change,
  its test coverage (including a new dual-plugin interop test seeding a
  mid-run buffered webhook update against Universal Telegram's real
  ADR-0040 implementation once merged), and the provider-registration swap
  are implementation, tracked as SC-M03 WP2's Support Chat-side work
  package, not committed alongside this addendum.

## Product Owner review required

This addendum requires explicit Product Owner sign-off as an amendment to
previously-closed work (the original WP3-4 closure's PASS status is not
reopened by this addendum; the amendment is additive, gating an existing
precondition more strictly, never relaxing it). Pending, not yet reviewed —
recorded here per the same "Pending. This PR is opened for review and is
not merged by this task" convention the original closure record already
uses for its own Product Owner acceptance line.

## Cross-reference

- Universal Telegram ADR-0040 (`magpern/universal-telegram`,
  `docs/adr/0040-legacy-chat-quiescence-write-blocking-and-drain.md`) — the
  provider implementation this addendum's amendment depends on. Not
  duplicated here, per this repository's own precedent
  (`InProcessLegacyExportClient`'s ADR-0008 pin) of referencing Universal
  Telegram's ADRs by citation rather than copying their text.
- Support Chat ADR-0008 §6 (this repository, immutable, unedited by this
  addendum).
