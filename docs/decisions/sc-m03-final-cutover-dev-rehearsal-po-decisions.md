# Product Owner Decision Record — SC-M03 Final-Cutover Disposable DEV Rehearsal

## Status

**Proposed / awaiting Product Owner.**

No decision below is in force. This record is created by the disposable-rehearsal documentation
freeze so that the questions requiring Product Owner sign-off are stated explicitly and in one
place. Nothing here authorizes execution of any rehearsal.

## Decision owner

Magnus (Product Owner, per [`docs/governance.md`](../governance.md) role table).

## Context

[ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md), Universal
Telegram's ADR-0042, and both final-cutover closure records
([this repository's](../closure/sc-m03-final-cutover-closure.md), and Universal Telegram's)
are merged and Product Owner accepted. Each acceptance states verbatim that it "does not
authorize a DEV or production quiescence window, migration, cohort activation, route switch,
cutover, deployment, soak, rollback, deletion, release, or tag" and that "the next possible
activity is a separately planned, disposable DEV rehearsal."

The rehearsal plan is materialized as:

- primary operator runbook — `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`
- Support Chat companion — [`sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md)

The questions below are product / governance decisions, not architecture decisions, and require
explicit Product Owner approval before the rehearsal may be executed.

## Proposed decisions (none in force until signed)

### 1. Tier 1 is required, but it is not the DEV rehearsal

Tier 1 is a **required disposable automated operational-sequence / integration validation** in
the existing container/PHPUnit interop harness, with zero Telegram traffic. It proves data
effects, state-machine sequencing, and CLI-equivalent service ordering.

**Tier 1 does not satisfy the accepted requirement for a disposable DEV rehearsal.** It lacks
real WP-Cron / Action Scheduler drain, the Redis object cache, authenticated Telegram webhook
ingress, real chat-widget traffic, and the DEV VPS runtime surface. Tier 1 is a mandatory
prerequisite to Tier 2 and is not a substitute for it.

### 2. Tier 2 is the DEV rehearsal, and it is blocked

Tier 2 — an isolated full-WordPress instance plus a dedicated non-production Telegram bot, test
supergroup, and test topics — is the first actual disposable DEV rehearsal. It is **blocked
until**:

- **B1** — an isolated full-WordPress instance exists (its own containers, network, host
  volumes, database + credentials, Redis, `CredentialVault` key, SWAG vhost, and LE certificate),
  sharing no volume, network alias, or database with `dev.biopentra.eu`, isolation demonstrated
  not asserted; **and**
- **B2** — a dedicated non-production Telegram bot, a dedicated test forum supergroup, and
  dedicated test topics exist, configured only in that isolated instance; the production and dev
  support groups and their bots are never referenced.

Building B1 and B2 is infrastructure work, out of scope of this documentation freeze.

### 3. Terminal acknowledgement is never used to force a pass

`wp universal-telegram cutover incident-acknowledge` is never used to make a rehearsal run
"pass." Its interface is exercised only as an explicitly separate, optional scenario, and only
with a synthetic unrecoverable fixture, an opaque `--po-decision-ref` pointing at a synthetic
pre-created rehearsal decision-record file (never free-form text, never a real Product Owner
decision reference), an explicit Product-Owner-authority simulation note, and proof afterward
that `replayed_at` / `handed_off_at` remain NULL, ciphertext and audit are retained, and no
Support Chat `legacy_handoff_map` row and no false handoff stamp was produced.

### 4. An incident row is never overwritten or mutated to drain a backlog

The disposable-rehearsal runbook never instructs mutating, overwriting, or replacing an incident
row's ciphertext or any of its columns to make replay or completion succeed. An incident is
resolved only by a genuine retry that succeeds (auto-stamping `incident_resolution =
'retried_success'`) or, in the separate scenario above, by `incident-acknowledge`. The
incident-detection run (Run 3) legitimately ends "blocked-as-designed" without reaching
`confirm-complete`; the row is destroyed only by full disposable-environment teardown.

### 5. Approval A is required before Tier 1

The Tier 1 prerequisite validation may not be executed until the Product Owner has signed
Approval A (primary runbook §10). Approval A authorizes only the container/PHPUnit harness,
synthetic data, zero Telegram traffic, and throwaway checkouts at the pinned SHAs.

### 6. Approval B is required before Tier 2, and cannot take effect early

The Tier 2 disposable DEV rehearsal may not be executed until the Product Owner has signed
Approval B (primary runbook §10). Approval B **cannot take effect** before Tier 1 has passed and
B1 and B2 are proven resolved (isolation demonstrated, dedicated non-production Telegram
resources provisioned).

## Non-authorization

This record authorizes nothing. It records the decisions the Product Owner must make before any
rehearsal — Tier 1 included — may run. No rehearsal has run. No acceptance record exists; adding
one is a later, separate Product Owner action.

## Affected documents

- [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) — the architecture this rehearsal exercises.
- [Support Chat companion rehearsal plan](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md).
- Primary operator runbook (Universal Telegram) — `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d — planning-only cross-reference added.

## Compatibility / Migration impact

None. No runtime code, schema, plugin version, configuration, release, tag, deployment, or
infrastructure change is made by this record.
