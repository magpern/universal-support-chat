# SC-M03 — Controlled Migration and Cutover

## Status

Planned. **Work packages 0–1 implemented** (authenticated Contract server; Universal Telegram signed Contract client and joint interoperability gate) — see closures: `docs/closure/sc-m03-work-package-0-contract-server-closure.md`, `docs/closure/sc-m03-work-package-1-interop-gate-closure.md`. **Work packages 3–4 (legacy migration engine) implemented** — batch migrator/checkpoints (Phase A preparatory backfill) and validators (Phase B quiescence-gated reconciliation), proven against Universal Telegram's real, merged `LegacyExportServiceV1` in a dual-plugin interoperability harness — see closure: `docs/closure/sc-m03-work-packages-3-4-legacy-migration-engine-closure.md` (Product Owner acceptance pending). This closure does **not** claim real quiescence, cutover readiness, route switching, soak, rollback, or any production migration execution (ADR-0008 §6). **Work package 2 (quiescence) implemented and Product Owner accepted** — see closure: `docs/closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md`. **Work package 5 (binding preparation for existing Telegram topics) implemented and Product Owner accepted** (ADR-0009, §0c below; closure: `docs/closure/sc-m03-work-package-5-legacy-binding-preparation-closure.md`). **Final-cutover package documentation frozen and Product Owner accepted** (ADR-0010, §0d below; PO decision record's "Documentation-freeze acceptance" section) — implementation authorized within ADR-0010/ADR-0042's stated scope; still no production cutover, migration, route switching, deployment, soak, rollback, or deletion.

Depends on: SC-M02; **UT Adapter M1** (binding table must exist); **ADR-0007** (authenticated Contract server design); **ADR-0008** (legacy export boundary and migration authority model)

## Objective

Resumable one-shot migration of legacy Universal Telegram chat data into Support Chat, with quiescence, validation, binding creation for existing topics, atomic route switch, soak, and non-destructive rollback.

## 0. Sequencing amendment (ADR-0007)

Added by the `docs/sc-contract-v1-authentication-profile` documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Principles, the interoperability matrix, or the exclusions below.

UT Adapter M1 shipped with every adapter → Support Chat Contract call deliberately stubbed to fail closed (`sc_authenticated_contract_unavailable`), because Contract v1 (ADR-0005) requires authenticated, capability-checked calls without specifying the mechanism. [ADR-0007](../adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md) now fixes that mechanism: mutual Ed25519 request signing, administrator-authorized pairing, no shared secret, no bare `rest_do_request()` context, no public mutation bypass.

SC-M03 implementation is split and ordered as follows (frozen plan: [v2](../plans/sc-m03-controlled-migration-and-cutover-plan-v2.md), superseding [v1](../plans/sc-m03-controlled-migration-and-cutover-plan-v1.md)):

1. **Implemented.** Support Chat's authenticated Contract server and pairing/replay authority (ADR-0007) — closure: `docs/closure/sc-m03-work-package-0-contract-server-closure.md`.
2. Universal Telegram's signed Contract client, replacing the current fail-closed stubs — a follow-up slice of UT Adapter M1, documented and implemented in the Universal Telegram repository after this Support Chat ADR merges.
3. End-to-end authenticated interoperability tests between the two (pairing, rotation, revocation, replay rejection, uniform fail-closed behaviour).
4. Only then, this charter's one-shot legacy migration engine and controlled cutover orchestration (§Principles, unchanged).
5. Only after SC-M03 acceptance, the Universal Telegram legacy Conversations/AI/widget/settings decommission (Cursor-led, Universal Telegram repository, out of scope here).

**No SC-M03 migration/cutover implementation code may begin until steps 1–3 are merged.**

## 0b. Work packages 3–4 authorization amendment (ADR-0008)

Added by the legacy-export-boundary-and-migration-authority-model documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Principles, the interoperability matrix, or the exclusions below.

Steps 1–3 above (Contract server, Universal Telegram signed client, joint interoperability gate) are complete. [ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) now fixes the remaining architecture gap step 4 (the migration engine) depends on: a narrow, versioned, in-process, WP-CLI-only legacy-export boundary (never a Contract v1 operation, never a public REST route, never a shared secret), and a frozen `QuiescenceStateProvider` contract that binds work package 2's future real quiescence signal. The [work packages 3–4 implementation plan](../plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md) and the [work packages 3–4 Product Owner decision record](../decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md) (owner-identity semantics, assignment-data migration, ownerless conversations, retention timing, consent-state disposition) are both frozen alongside ADR-0008.

**Work packages 3–4's closure may not claim real quiescence, cutover readiness, atomic route switching, soak, rollback, or any production migration execution** — `QuiescenceStateProvider` ships only as a default-deny stub and a test seam until work package 2 (a separate, later, unstarted unit of work) supplies a real implementation of this same frozen interface.

**Work packages 3–4 implementation may not begin until both (a) ADR-0008 and (b) Universal Telegram's own documentation amendment — pinning ADR-0008's post-merge commit SHA and canonical blob URL, and implementing `LegacyExportServiceV1` per ADR-0008 §2–§5 — are merged to their respective `main` branches**, mirroring the identical two-repository gate step 1–3 above already established for the Contract server/signed-client pair.

## 0c. Work package 5 authorization amendment (ADR-0009)

Added by the legacy-binding-preparation-boundary documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Principles, the interoperability matrix, or the exclusions below.

Work package 2 (quiescence) is complete and Product Owner accepted ([closure](../closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md)). [ADR-0009](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md) now fixes work package 5's remaining architecture gap: a narrow, versioned, in-process, WP-CLI-only binding-write boundary (`LegacyBindingImportServiceV1`, symmetric to ADR-0008's read-side `LegacyExportServiceV1`), a new non-routing `prepared` binding status, a status-specific idempotency/conflict model, and an atomic, Universal Telegram-internal, lock-scoped quiescence enforcement. The [work package 5 implementation plan](../plans/sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md) and the [work package 5 Product Owner decision record](../decisions/sc-m03-wp5-legacy-binding-po-decisions.md) are both frozen alongside ADR-0009.

**Work package 5's closure may not claim cutover readiness, atomic route switching, soak, rollback, or any production migration/binding execution.** Every binding it creates carries `status = 'prepared'`, which Universal Telegram's existing routing gates (`is_active()`) structurally exclude from live traffic — this is a testable code property (ADR-0009 §3), not an assumption about a future cutover step.

**Work package 5 implementation may not begin until both (a) ADR-0009 and (b) Universal Telegram's own documentation amendment — pinning ADR-0009's post-merge commit SHA and canonical blob URL, and implementing `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion per ADR-0009 §2–§5 — are merged to their respective `main` branches**, mirroring the identical two-repository gate §0b already established for work packages 3–4.

## 0d. Final-cutover documentation freeze (ADR-0010)

Added by the final-cutover-handoff-contract-and-cohort-activation documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Principles, the interoperability matrix, or the exclusions below, except for the clarification stated explicitly here.

Work packages 2 (quiescence) and 5 (binding preparation) are both complete and Product Owner accepted. [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) (this repository) and Universal Telegram's companion ADR-0042 now freeze the final-cutover package's design: cohort-based, all-or-nothing binding activation under quiescence lock with compensating rollback on any commit failure; a cohort-aware deferred-update replay barrier; an additive extension to six existing Contract v1 operations carrying cutover provenance, with Support Chat owning a durable, transactionally co-written handoff map; a strict split between that handoff map and Universal Telegram's own incident record for pre-dispatch failures; and a resolution for the `maybe_mark_topic_unavailable()` live-webhook cross-talk risk. The [final-cutover implementation plan](../plans/sc-m03-final-cutover-plan-v1.md) and the [final-cutover Product Owner decision record](../decisions/sc-m03-final-cutover-po-decisions.md) are both frozen alongside ADR-0010.

**Clarification of the "Partial cutover — forbidden; switch is atomic" principle below, per Product Owner decision record item 1**: "atomic" means atomic per an explicit, operator-approved cohort — every member of a named, reviewed cohort activates and hands off together, or none do. A cohort may be smaller than the full migrated dataset, at the Product Owner's explicit discretion per run. This clarifies, and does not weaken, the existing principle.

**This freeze may not claim any production cutover, quiescence, route switch, soak, rollback, or deletion.** It authorizes only the future implementation of the final-cutover *engine* it describes — a separate, later, Product-Owner-approved implementation task, exactly as the work package 2 and work package 5 freezes each preceded their own later implementation.

**Final-cutover implementation may not begin until both (a) ADR-0010 and (b) Universal Telegram's own ADR-0042 (pinning ADR-0010's post-merge commit SHA) are merged to their respective `main` branches**, mirroring the identical two-repository gate every prior SC-M03 work package already established. **Both conditions are now satisfied** — ADR-0010 merged `be7461544a39c7ad074164d21e3c1b04c71f2fc2`; ADR-0042 merged `15067671c5234cce975e939150a631bb8f9e56c8` — **and Product Owner acceptance of the freeze is recorded** (`docs/decisions/sc-m03-final-cutover-po-decisions.md`, "Documentation-freeze acceptance"). Implementation is authorized only within those two documents' own stated scope and safety boundaries; production cutover, migration, route switching, deployment, soak, rollback, and deletion each remain separately gated.

**Final-cutover implementation and Product Owner acceptance are complete** ([closure](../closure/sc-m03-final-cutover-closure.md), including the mutual-pairing interop-suite correction addendum and "Product Owner acceptance (final)"). That acceptance explicitly states it "does not authorize a DEV or production quiescence window, migration, cohort activation, route switch, cutover, deployment, soak, rollback, deletion, release, or tag" and names "a separately planned, disposable DEV rehearsal" as the next possible activity. **Planning-only:** that rehearsal is now specified — [Support Chat companion plan](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md), [Product Owner decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md), and the primary operator runbook in the Universal Telegram repository. Tier 1 (a required disposable automated operational-sequence validation in the container/PHPUnit interop harness) is **authorized** — Product Owner Approval A is recorded ([Product Owner decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md), "Approval A — recorded"; companion in Universal Telegram's `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`). Tier 2 (the actual disposable DEV rehearsal) is blocked on an isolated full-WordPress instance (B1) and dedicated non-production Telegram bot/supergroup/topics (B2), and pending Approval B, which cannot take effect until Tier 1 passes and B1/B2 are proven resolved.

**Tier 1 execution was attempted (2026-08-27) and HALTED at the UT→SC deferred-update handoff phase by finding F1** — `ContractOperationDispatcher::resolve_conversation()` resolves `channel_case_ref` only as this repository's own `conversation_uuid`, but Universal Telegram's `CutoverReplayDispatcher`/`InboundAdapterBridge` send `$binding->binding_uuid()` (an independent opaque UUID for every real binding). See closure [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md). The disposable harness is validated; **no production code was changed and no bypass, terminal-acknowledgement, incident-row mutation, or fixture shortcut was used.** F1 is raised for separate ADR-level review. **Tier 2 is now blocked on B1, B2, and F1.**

**F1 resolution — implementation ACCEPTED 2026-08-27:** [ADR-0011](../adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md) (**Accepted**) amends ADR-0010 §4: `channel_case_ref` is unambiguously this repository's conversation/case UUID — `resolve_conversation()` and `dispatch_with_provenance()` behaviour are already correct, only comments contradict it (corrections C1–C4). Universal Telegram ADR-0043 owns the adapter-side wire change (send `support_conversation_uuid()`, not `binding_uuid()`) and the fail-closed classification of an unresolvable ref (new closed incident `unresolved_case_reference`). No SC-side binding→conversation resolver is added (rejected unless separately designed later). Remediation plan: [`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`](../plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md) — comment corrections only; no schema, no `db_version` bump. Identifier collapse (option (c)) rejected by Product Owner direction. **Product Owner acceptance of the F1 implementation is recorded** ([decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md) item 7; Universal Telegram companion `docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-approval.md`) — it authorizes the frozen F1 remediation work packages only, no schema/`db_version`/Contract-operation change and no operational action. **Tier 1 cannot be accepted until the correction is implemented and its real-binding path passes**; Tier 1 remains halted/unexecuted; Tier 2 stays blocked on B1, B2, and F1.

## Principles (mandatory — ADR-0004)

- No dual-write.
- Controlled one-shot cutover.
- Quiesce and block/drain all mutation sources before copy:
  - visitor REST posts;
  - Hub actions;
  - legacy Telegram inbound replies/commands;
  - chat-related queued delivery/topic jobs;
  - chat retention and AI jobs.
- Resumable batch copy and re-encryption into Support Chat vault.
- Durable source-to-target mapping.
- Count, integrity, and content-hash validation.
- Existing Telegram topic bindings created only after UT Adapter M1 exists.
- Atomic route switch; then reopen writes.
- Legacy Universal Telegram chat tables retained read-only through a defined soak period.
- Rollback is route reversal only; no destructive deletion.
- No chat history loss, duplicate messages, duplicate topics, or cross-visitor access.
- AI config/drafts are **not** migrated in this milestone.

## Interoperability matrix (must pass)

| Case | Pass criteria |
|---|---|
| Support Chat alone | Widget + Hub; R7 ticket without Telegram |
| Both plugins connected | Escalated binds; Hub reply works |
| Universal Telegram deactivated during open conversation | Hub continues; channel unavailable |
| Adapter unavailable while Hub reply continues | Hub/visitor unaffected |
| Migrated Telegram topic reply routing | Routes via binding |
| Duplicate Telegram update idempotency | No duplicate message |
| Interrupted migration resume | Safe resume |
| Soak-period rollback | Route reverse; no data loss |

## Explicit exclusions

- Dual-write coexistence.
- Destructive delete of legacy tables at cutover.
- SC-AI data migration.
- Implementing UT Adapter M1 itself.

## Frozen plan

[sc-m03-controlled-migration-and-cutover-plan-v2.md](../plans/sc-m03-controlled-migration-and-cutover-plan-v2.md) (supersedes [v1](../plans/sc-m03-controlled-migration-and-cutover-plan-v1.md); v1 retained unedited per `docs/plans/README.md`)

Work packages 3–4 detail: [sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md](../plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md), authorized by [ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) and the [work packages 3–4 Product Owner decision record](../decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md).
