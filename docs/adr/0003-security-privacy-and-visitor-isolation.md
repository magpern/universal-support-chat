# ADR-0003 — Security, Privacy, and Visitor Isolation

## Status

Accepted

## Context

Support chat stores visitor messages, operator notes, and assignment state. Without explicit isolation and classification rules, cross-visitor access, secret leakage, or channel-side over-sharing become likely—especially once an optional adapter exports transcript plaintext in memory.

## Decision

### Visitor isolation

- Every conversation is owned by an authenticated visitor identity (exact auth model is SC-M01/SC-M02 plan detail; principle is fixed here).
- Operators and adapters must not obtain another visitor’s transcript through IDOR, guessed UUIDs, or channel binding confusion.
- Visitors never receive channel binding references, remote IDs, credentials, or internal operator data (ADR-0005).

### Classification

Support Chat classifies persisted fields at minimum into:

- **Visitor-facing transcript** — eligible for authorised channel delivery/backfill when escalated.
- **Internal** — notes, audits, assignment rationale — **never** exported to channel adapters.
- **Secret** — vault material, bearer secrets, credentials — never logged, never exported, never sent to adapters as durable storage.

### Capabilities

- Hub and REST mutations require explicit WordPress capabilities.
- Adapter → Support Chat Contract calls are authenticated and capability-checked.
- Fail closed on missing authz.

### Encryption posture

- Conversation bodies and secrets at rest use a Support Chat–owned vault/key approach (SC-M00 defines mechanism).
- Migration into Support Chat re-encrypts into the Support Chat vault (ADR-0004); plaintext only in memory during authorised migrate/delivery.

### Channel plaintext

- Support Chat may expose plaintext to an adapter **only** through narrow Contract v1 delivery/backfill calls (ADR-0005).
- Adapters own their own encrypted outbound queues after accept.

## Alternatives

- Visitor checkbox as AI consent UX as the sole control — rejected for future AI (R4); site policy + disclosure instead (SC-AI2).
- Storing channel tokens in Support Chat for convenience — rejected (ADR-0002).

## Consequences

- SC-M01+ schemas and REST must enforce ownership.
- Contract export eligibility excludes internal/secret classes.

## Security and privacy impact

This ADR is the baseline security/privacy boundary for the product.

## Affected Documents/Milestones

All milestones; ADR-0005 transcript eligibility; SC-M03 migration handling of ciphertext.

## Compatibility/Migration Impact

Legacy Universal Telegram encryption keys are not reused as Support Chat vault keys; re-encrypt during SC-M03.
