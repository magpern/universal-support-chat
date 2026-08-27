# SC-M03 Final Cutover — Implementation Plan v1

**Scope: implementation-ready plan, not implementation.** Authorized by [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) (this repository) and Universal Telegram's companion ADR-0042. No code, schema, branch, PR, release, tag, deployment, or production quiescence/cutover/route-switch/soak/rollback/deletion is performed by this document.

## 1. Charter and ADRs

- Charter: [`sc-m03-controlled-migration-and-cutover.md`](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d.
- This repository: [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md).
- Universal Telegram: ADR-0042 (companion, owns the state machine/activation/replay/incident/lifecycle-cross-talk mechanics).
- Product Owner decisions: [`sc-m03-final-cutover-po-decisions.md`](../decisions/sc-m03-final-cutover-po-decisions.md).
- Prior work packages relied on: ADR-0008 (export boundary), ADR-0009 (binding preparation), Universal Telegram ADR-0040/ADR-0041.

## 2. Repository findings at plan-drafting time

All verified directly against `origin/main` — this repository `661f506e74b4a5e383b9a4859efc32d80ada43b5`, Universal Telegram `a761550f9e4c8b4422cb48dc23b0a6e82fdccbc5` — see ADR-0010 §"Required source verification" for the full, cited list. Load-bearing findings repeated here for plan continuity:

- The six adapter → Support Chat Contract operations this design reuses (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`) are each already genuinely idempotent by source, via distinct mechanisms (idempotency-key dedup, state-check-before-write, or UPSERT) — none needed a new idempotency mechanism invented; only a new provenance/handoff-map co-write was needed.
- None of the six existing request bodies carry `bot_id`/`update_id`; none of the six handlers persist a queryable per-update record today.
- `ChannelStatusRepository::upsert()` is a real `INSERT ... ON DUPLICATE KEY UPDATE` keyed by `UNIQUE(conversation_id)` — safe to repeat, `updated_at` legitimately advances each call.
- `ChannelBindingRepository::set_status()` (Universal Telegram) has no CAS guard, no current-status precondition, and zero production callers — cannot be reused for activation.
- `WebhookController::maybe_mark_topic_unavailable()` (Universal Telegram) runs before the adapter-bridge check and matches on `topic_creation_state = 'created'` alone, never the legacy conversation's own status — confirmed live-webhook cross-talk risk, resolved in ADR-0010 §6.

## 3. Assumptions and open questions

Separated explicitly from decisions, per plan convention:

- **[ASSUMPTION]** The exact WP3-4 Phase B CLI command name used in cohort preflight's "confirm `status = migrated`" step — not re-verified in this freeze; must be confirmed at implementation time against the real, current WP-CLI command surface.
- **[ASSUMPTION]** SC's own existing message-retention policy for migrated/handed-off conversation messages, as distinct from Universal Telegram's own 30-day replayed/handed-off deferred-row retention (ADR-0040, extended by this design) — not re-read this session; must be confirmed compatible before implementation.
- **[OPEN, not blocking this freeze]** The exact CI Telegram-API test-double strategy for outbound-reply verification tests (§9) — follow whatever existing outbound-delivery tests already use; confirm at implementation time.

## 4. Architectural decisions (see ADR-0010 for full rationale/alternatives)

1. Cohort activation is a two-phase saga (read-only preflight over the whole cohort, then all-or-nothing commit with automatic in-run compensation) — never partial.
2. Deferred-update disposition at replay time is decided by a single, live `is_active()` check per row — never a pre-computed cohort membership list — closing the final-drain race structurally.
3. The Support Chat handoff boundary reuses six existing Contract v1 operations, extended with two optional provenance fields, rather than inventing a new operation or a new in-process push boundary.
4. Support Chat owns and transactionally co-writes its own handoff map; Universal Telegram owns a strictly separate incident record for pre-dispatch failures, with zero overlap by construction.
5. The `maybe_mark_topic_unavailable()` cross-talk is resolved by checking for an active binding before any legacy lookup, reusing the existing `report_channel_unavailable` operation.

## 5. Directory, namespace, schema, and API impact (scoped, future implementation only)

**This repository:**
- New table `universal_support_chat_legacy_handoff_map` (schema step — number to be assigned at implementation time, additive, following this repository's existing `SHOW COLUMNS`/postcondition-verification convention).
- `ContractOperationDispatcher`'s six existing handler methods gain the transactional co-write path (§4, ADR-0010 §4) — no new operation names, no new route.
- No new WP-CLI surface in this repository for the final-cutover package itself — all cutover orchestration commands live in Universal Telegram (ADR-0042); this repository's role is entirely the passive Contract-handler extension.

**Universal Telegram** (referenced, owned by ADR-0042, not designed here): new cutover-state singleton table, `activate_prepared()`/`revert_activation()` on `ChannelBindingRepository`, the cohort-aware replay-loop amendment, the incident-record columns on `quiescence_deferred_updates`, the `cutover` WP-CLI namespace, the `maybe_mark_topic_unavailable()` reordering.

## 6. Security and privacy impact

- No new REST route, no new authentication mechanism, no shared secret — the two new request-body fields ride the existing Ed25519-signed envelope unchanged.
- Every new persisted field on both sides is non-content (ids, uuids, fixed vocabulary, timestamps).
- The terminal-acknowledgement exception (PO decision record item 2) is deliberately narrow — opaque PO-reference only, never free-form content.

## 7. Test and CI impact

Full matrix specified in ADR-0010's companion review record and restated here for implementation-time reference:

- Whole-cohort activation and compensation (preflight rejection, forced mid-commit failure with N compensating reverts, `cas_version` ends at pre-run+2, never restored).
- Crash/restart at every saga boundary (pre-commit, post-SC-commit-pre-UT-ack, mid-compensation).
- Cohort-aware deferred replay (a row for an active-at-drain-time topic never reaches legacy `process_update()`; a row for an inactive/failed-activation topic correctly falls back to legacy replay).
- Success-after-SC-commit/before-UT-ack retry convergence (exactly one domain effect, exactly one handoff-map row, across a forced crash-and-retry).
- SC handoff-map idempotency (matching retry succeeds silently) and provenance-conflict refusal (mismatched `kind`/`channel_case_ref` returns `409`, writes nothing).
- Universal Telegram-only incidents never creating an SC handoff-map row — one test per incident reason code, plus a structural negative assertion.
- The serialized webhook-vs-final-idle race (the existing ADR-0040 two-transaction interleaving proof, re-run against the widened three-column backlog predicate).
- Lifecycle-event non-cross-talk (an active-binding topic's `forum_topic_closed`/`forum_topic_deleted` reaches `report_channel_unavailable`, never legacy mutation; an inactive-binding topic's identical event still reaches legacy mutation unchanged).
- All supported WordPress/PHP combinations (floor and current, matching this repository's existing CI matrix) plus real dual-plugin interop for every scenario above that spans both plugins.
- A permanent regression proof that no `prepared` binding ever routes traffic, and no `active` binding is ever silently skipped by any of this design's own new code paths (extending the existing `InboundAdapterBridgeNonInterferenceTest`/its activation counterpart).

## 8. Work packages, in execution order (future, not authorized by this document)

1. This repository's schema step (handoff map) and the six Contract handlers' transactional extension.
2. Universal Telegram's cutover-state machine, `activate_prepared()`/`revert_activation()`, cohort-aware replay amendment, incident record and CLI.
3. Cross-plugin interop test suite (both repositories).
4. Disposable DEV rehearsal (this VPS) — required before any production claim.
5. Production preflight and acceptance — separately approved, not part of this plan's own definition of done.

## 9. Risks and mitigations

- **Cohort sizing vs. acceptable interruption window** — mitigated by the pilot-cohort-first recommendation (PO decision record item 1).
- **A genuinely unrecoverable incident row** — mitigated by the narrowly-scoped terminal-acknowledgement exception (PO decision record item 2), never a general bypass.
- **`BindingImportCommand`'s pre-existing reroute risk** (carried forward from WP5, still unresolved) — this design's `activation_conflict_*`/provenance-conflict outcomes remain the detection mechanism for a collision with it, not a fix at its source.

## 10. Explicit out-of-scope list

Production quiescence, cutover, route switch, soak, rollback, or deletion; any AI/availability/ticket/launcher/unrelated-UI work; retirement of Universal Telegram legacy data or UI (a separate, future, separately-approved decision per PO decision record item 3); any code, schema, branch, PR, release, tag, or deployment in this task.

## 11. Definition of done (for this documentation-freeze stage only)

ADR-0010 and Universal Telegram's ADR-0042 both merged to their respective `main` branches after green documentation CI; this plan, the PO decision record, and the charter §0d amendment merged alongside; both merged SHAs reported. Implementation (§8) begins only after a separate, later, explicitly-approved task.
