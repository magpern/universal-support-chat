# SC-M03 — Controlled Migration and Cutover

## Status

Planned. **Work package 0 (Support Chat's authenticated Contract server) implemented** — see closure: `docs/closure/sc-m03-work-package-0-contract-server-closure.md`. **Migration/cutover implementation remains blocked** until work packages 1–3 (Universal Telegram's signed Contract client and joint interoperability tests) are also complete — see [§0 Sequencing amendment](#0-sequencing-amendment-adr-0007) below.

Depends on: SC-M02; **UT Adapter M1** (binding table must exist); **ADR-0007** (authenticated Contract server design)

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
