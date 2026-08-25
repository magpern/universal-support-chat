# SC-M03 — Controlled Migration and Cutover

## Status

Planned

Depends on: SC-M02; **UT Adapter M1** (binding table must exist)

## Objective

Resumable one-shot migration of legacy Universal Telegram chat data into Support Chat, with quiescence, validation, binding creation for existing topics, atomic route switch, soak, and non-destructive rollback.

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

[sc-m03-controlled-migration-and-cutover-plan-v1.md](../plans/sc-m03-controlled-migration-and-cutover-plan-v1.md)
