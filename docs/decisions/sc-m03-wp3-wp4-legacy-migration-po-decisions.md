# Product Owner Decision Record — SC-M03 Work Packages 3–4 Legacy Migration Engine

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) fixes the engineering architecture (export boundary, authority model, `QuiescenceStateProvider` contract) for SC-M03 work packages 3 (batch migrator/backfill) and 4 (validators). Five field-mapping and product-scope questions identified during the architecture review of the reviewed WP3–WP4 plan are not engineering decisions and require explicit Product Owner approval before implementation begins, per `docs/governance.md`'s "Scope-change and closure approval authority." This record captures those approvals. It does not itself change architecture; ADR-0008 remains the authoritative architecture document, and this record is referenced from it.

## Decisions

### 1. `owner_user_id` — conditional copy, no substitute meaning

Universal Telegram's `conversations.owner_user_id` may be copied into Support Chat's `conversations.owner_user_id` **only if** implementation-time code verification confirms the two columns carry equivalent visitor/customer-identity semantics (not operator identity, not any other meaning) in both plugins' current `ConversationRepository` implementations. If verification finds the semantics do not match, or is inconclusive, **implementation must stop and return to architecture review rather than substitute an assumed meaning.** This is not an open question deferred to coding judgment — it is a conditional rule with a defined stop condition.

### 2. `assigned_operator_id` — migrate the value, no new UI this milestone

The existing legacy value in Universal Telegram's `conversations.assigned_operator_id` is migrated into Support Chat's existing `conversations.assigned_operator_id` column, preserving historical assignment data even though SC-M02 deferred building the assignment UI/workflow that would let a Hub operator manage this value going forward. This milestone (work packages 3–4) must not surface assignment as a new user-facing feature, must not add any UI, endpoint, or workflow that reads or acts on this column beyond what already exists from SC-M02 — the data is preserved for a later milestone to use, not activated by this one.

### 3. Anonymous/ownerless legacy conversations — Hub-visible historical record, or excluded with audit reason; no new ownership model

If a legacy conversation has no usable `owner_user_id` (verified nullable per the reviewed plan's field-mapping audit), it may be migrated as a **Hub-visible historical record with no visitor-facing access**, but only if Support Chat's existing, already-authorized schema and ownership rules support that state safely without modification. If they do not — if representing an ownerless conversation would require inventing new ownership semantics, a new access-control path, or any schema change beyond what work packages 3–4 already scope — the conversation is instead **excluded from migration with a durable, queryable audit reason** recorded in the migration map table. **No new visitor-ownership model may be designed or implemented as part of this decision or this milestone.** This decision authorizes only a choice between two paths already available within existing constraints; it does not authorize new product design.

### 4. Retention timing — this milestone builds and tests the engine only

Work packages 3–4 build and test the migration engine's code (batch migrator, validators, the export boundary, the `QuiescenceStateProvider` contract and its default-deny/test-seam implementations). **They do not run a production migration, and they do not change Universal Telegram's retention behavior or schedule in any way.** Whether and when to run the migration engine against real production data — including whether to accelerate the timing relative to Universal Telegram's ongoing retention purges, which permanently delete legacy conversations old enough to be purged before any migration touches them — is a **separate, later operational decision**, made when a real `QuiescenceStateProvider` implementation (work package 2) exists and cutover is actually being planned. This record does not make that later decision, and nothing in work packages 3–4 may be read as having made it implicitly.

### 5. `consent_state` — not migrated; legacy record preserved unchanged

Universal Telegram's `conversations.consent_state` column is **not migrated into Support Chat** by work packages 3–4, and is not read by the export boundary (ADR-0008 §5 already excludes it from the export shape at the source). The legacy consent record remains exactly as it exists today in Universal Telegram's own database, unmodified by this milestone. It is neither deleted nor reinterpreted. Its ultimate disposition — whether it is later migrated, referenced, or intentionally left behind — is deferred to the later read-only soak/decommission decision (SC-M03 charter §0 step 5, Universal Telegram legacy decommission), which has its own future governance gate and is explicitly out of scope for work packages 3–4.

## Affected Documents/Milestones

- [ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) — references this record for the field-mapping/scope questions it does not itself resolve.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) — additive amendment references this record.
- [SC-M03 work packages 3–4 plan](../plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md) — implements against these five decisions; does not re-litigate them.

## Compatibility/Migration Impact

No runtime code, schema, plugin version, release, tag, or deployment change is made by this record. It authorizes future implementation decisions; it does not itself implement, migrate, or execute anything.
