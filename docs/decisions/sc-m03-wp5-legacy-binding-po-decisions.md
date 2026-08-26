# Product Owner Decision Record — SC-M03 Work Package 5 Legacy Binding Preparation

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0009](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md) fixes the engineering architecture (write boundary, `prepared` status, status-specific idempotency/conflict model, atomic quiescence enforcement) for SC-M03 work package 5. Three product-scope questions identified during that ADR's own review process are not engineering decisions and require explicit Product Owner approval before implementation begins, per `docs/governance.md`'s "Scope-change and closure approval authority." This record captures those approvals. It does not change architecture; ADR-0009 remains the authoritative architecture document.

## Decisions

### 1. Conflict-row retry mode — deferred, not built in this implementation cycle

A future `--retry-conflicts`-style mode, requeuing `binding_conflict_existing_mismatched` / `binding_conflict_existing_active` / `binding_conflict_existing_status_unresolved` rows after a human has resolved the underlying situation out-of-band, is **not** part of this implementation cycle's scope. Ordinary reruns of `wp universal-support-chat legacy-bind run` must never automatically revisit a terminal conflict row. If a future need for such a mode arises, it is a separate, later, explicitly-scoped addition requiring its own review — not an implicit extension of this work package's rerun semantics.

### 2. `prepared → active` activation — separate future design and implementation task

This work package creates only non-routing `prepared` bindings. It must never create, activate, or convert a binding to `active`, under any flag, mode, or condition. The transition from `prepared` to `active` — including its own authority model, whether it is per-binding, per-batch, or atomic-all-at-once with the eventual route switch — is a distinct, forthcoming design task belonging to a future cutover work package, not designed, scoped, or authorized by this decision or by ADR-0009.

### 3. `BindingImportCommand`'s pre-existing reroute risk — recorded, not remediated by this work package

Universal Telegram's existing, already-shipped `BindingImportCommand --apply` writes `status = 'active'` unconditionally, with no liveness check on the source legacy conversation — a pre-existing gap independent of and predating this work package. This decision confirms: **this work package does not alter, harden, or restrict `BindingImportCommand` in any way.** ADR-0009's `binding_conflict_existing_active` outcome is accepted as this work package's detection mechanism for the case where that command's output collides with a later run of this work package — this is accepted as sufficient for this implementation cycle. Any future hardening of `BindingImportCommand` itself is a separate, later, Universal-Telegram-owned decision.

## Affected Documents/Milestones

- [ADR-0009](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md) — references this record for the product-scope questions it does not itself resolve.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0c — additive amendment references this record.
- [Work package 5 implementation plan v1](../plans/sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md) §13 (explicit out-of-scope list) — reflects decisions 1 and 3 above.
