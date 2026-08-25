# ADR-0004 — Migration and Retention Principles

## Status

Accepted

## Context

Legacy website chat data today lives in Universal Telegram. Extracting Support Chat requires moving conversations without dual-write divergence, without depending on Telegram for ticket existence, and without destructive rollback. Retention must be Support Chat–owned after cutover.

## Decision

### Migration style

- **No dual-write.**
- **Controlled one-shot cutover** with a **resumable** batch migration.
- Dual-read during soak is allowed only as **read-only** access to legacy Universal Telegram chat tables for verification/rollback routing — not as a second writer.

### Quiescence (mandatory before copy)

Block or drain **all** chat mutation sources until idle:

1. Visitor REST message posts (and conversation create/start)
2. WordPress Hub operator actions
3. Legacy Telegram inbound replies and conversation commands
4. Chat-related queued delivery/topic jobs
5. Chat retention and AI jobs affecting chat data

Only then may copy begin. Messages arriving between copy and route-switch are otherwise lost or duplicated.

### Copy and validation

- Resumable batch copy with checkpoint/resume.
- Re-encrypt into the Support Chat vault.
- Durable **source → target** mapping.
- Validate counts, integrity, and **content hashes**.
- Create bindings for existing Telegram topics **only after UT Adapter M1 exists**, in the adapter binding table; Support Chat stores opaque `channel_case_ref` only (ADR-0005).

### Cutover

- **Atomic route switch** of widget, visitor API, Hub, and inbound adapter routing to Support Chat.
- Reopen writes only after the switch succeeds.
- Legacy Universal Telegram chat tables retained **read-only** through a defined soak period.
- **Rollback** during soak = **route reversal only**; **no destructive deletion** of either side’s data.
- Invariants: no chat history loss, no duplicate messages, no duplicate topics, no cross-visitor access.

### AI data

- Initial migration **does not** move AI config/drafts.
- AI remains in Universal Telegram as historical/read-only until SC-AI1’s own migration plan.

### Retention

- After cutover, retention/purge policy is owned by Support Chat (SC-M01 defines defaults).
- Legacy soak tables are not the SoR after cutover.

### Mandatory interoperability matrix (SC-M03 / SC-M04)

| Case | Pass criteria |
|---|---|
| Support Chat alone | Widget + Hub work; offline ticket without Telegram (R7) |
| Both plugins connected | Escalated traffic binds; Hub reply works |
| Universal Telegram deactivated during an open conversation | Hub continues; channel marked unavailable |
| Adapter unavailable while Hub reply continues | Visitor/Hub path unaffected |
| Migrated Telegram topic reply routing | Inbound routes via binding to migrated conversation |
| Duplicate Telegram update idempotency | No duplicate transcript message |
| Interrupted migration resume | Checkpoint resume; no corrupt SoR |
| Soak-period rollback | Legacy route restored; no data loss |

## Alternatives

- Dual-write coexistence — rejected: diverging histories, duplicate sends, hard rollback.
- Delete legacy tables immediately at cutover — rejected: removes soak/rollback safety.
- Migrate before adapter/binding table exists — rejected: migrated Telegram topics would have nowhere to route.

## Consequences

- Roadmap order: SC-M00–M02 → UT Adapter M1 → SC-M03 → SC-M04.
- SC-M03 charter and plan must implement these principles; this ADR does not implement them.

## Security and privacy impact

Re-encryption and quiescence reduce plaintext exposure windows and prevent cross-visitor mapping errors.

## Affected Documents/Milestones

SC-M03, SC-M04; UT Adapter M1; ADR-0005.

## Compatibility/Migration Impact

Documentation only in this freeze. Execution is SC-M03 after UT Adapter M1.
