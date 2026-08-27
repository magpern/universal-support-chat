# Product Owner Decision Record — SC-M03 Final Cutover

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) and Universal Telegram's companion ADR-0042 fix the engineering architecture for the SC-M03 final-cutover package. Several product-scope questions identified during that design's own multi-round review are not engineering decisions and require explicit Product Owner approval before implementation begins, per `docs/governance.md`'s "Scope-change and closure approval authority." This record captures those approvals. It does not change architecture; ADR-0010/ADR-0042 remain the authoritative architecture documents.

## Decisions

### 1. Cohort atomicity — clarified, not weakened

The charter's existing "Partial cutover — forbidden; switch is atomic" principle is confirmed to mean: **atomic per an explicit, operator-approved cohort.** A cohort is a named, reviewed, operator-supplied list of conversations — never "all migrated conversations implicitly" — and every member of an approved cohort activates and hands off together, or none do (ADR-0010 §2). A pilot-sized first cohort is explicitly approved as the recommended operational approach for the first real cutover run, once implementation is separately authorized.

### 2. Incident terminal-acknowledgement exception — retained, narrowly scoped

**Approved, 2026-08-27.** The default and required policy remains: an incident (Universal Telegram ADR-0042 §5) must be remediated and successfully dispatched or replayed before cutover can complete. The Product Owner additionally approves retaining a narrow exception for the genuinely unrecoverable case, subject to every one of the following constraints, none of which may be relaxed without a new decision record:

- Reachable only via an explicit, authority-gated WP-CLI command (`--assume-cutover-authority`-equivalent), never automatic, never triggered by any ordinary retry.
- Requires a reference to an explicit, pre-existing Product Owner decision (an opaque identifier only — e.g. a decision-record filename/anchor recorded ahead of time by the Product Owner for that specific incident) — never accepts arbitrary free-form operator text in any CLI argument or audit field.
- Never stamps `replayed_at` or `handed_off_at` — an acknowledged incident's underlying row is permanently excluded from further ordinary reprocessing, but is never misrepresented as having been delivered anywhere.
- The row's encrypted payload and full audit trail are preserved forever — never deleted by this action or by any retention sweep, matching this programme's existing "never auto-delete an unresolved/undecryptable row" precedent (ADR-0040).
- Stores only the opaque Product Owner reference plus the row's existing fixed, non-content metadata (`bot_id`, `update_id`, incident reason, timestamps) — no new content-bearing field of any kind.

If a future review finds this exception is being used more broadly than "genuinely unrecoverable," the Product Owner reserves the right to revoke it via a new decision record; this record does not pre-authorize any use beyond what is stated here.

### 3. Retention of Universal Telegram legacy source data and audit evidence

Recovery from any failure discovered after cohort activation is forward-only (ADR-0010 §1): no rollback path in this design sends post-activation traffic back to Universal Telegram. Consistent with that, Universal Telegram's legacy conversation data, the SC-M03 migration map, and every audit/incident/handoff-map record this design produces are retained **until a separately approved future retirement decision** — this record does not authorize deletion of any of them, and no future task may treat "cutover completed" alone as sufficient authorization for retirement.

### 4. `maybe_mark_topic_unavailable()` cross-talk resolution — approved as designed

The resolution frozen in ADR-0010 §6 / ADR-0042 (route an active-binding topic's lifecycle service message to `report_channel_unavailable` before any legacy mutation, retaining existing legacy behavior only when no active binding exists) is approved as the correct, sole resolution. No alternative (e.g. leaving the cross-talk as an accepted, documented risk) is adopted.

## Affected Documents/Milestones

- [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) — references this record for the product-scope questions it does not itself resolve.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d — additive amendment references this record.
- [Final-cutover implementation plan v1](../plans/sc-m03-final-cutover-plan-v1.md) — reflects every decision above.
- Universal Telegram ADR-0042 — the companion architecture document these decisions also bind.
