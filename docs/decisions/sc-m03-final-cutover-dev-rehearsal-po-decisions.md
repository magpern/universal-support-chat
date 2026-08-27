# Product Owner Decision Record — SC-M03 Final-Cutover Disposable DEV Rehearsal

## Status

**Approval A recorded — Tier 1 authorized. Tier 2 (Approval B) still awaiting Product Owner.**

Decision items 1, 3, 4, and 5 (Tier 1 scope, incident-evidence rules, Approval-A gate) are now
in force. Decision items 2 and 6 (Tier 2 / Approval B) remain pending and Tier 2 stays blocked
on B1, B2, **and F1** (decision item 7). This record was created by the disposable-rehearsal
documentation freeze; the Approval A section immediately below records the Product Owner's
authorization to execute Tier 1.

**2026-08-27 — Tier 1 attempted and halted by finding F1.** See decision item 7 below and
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`. F1 resolution is proposed in
ADR-0011 and Universal Telegram ADR-0043 (documentation-only, awaiting Product Owner review).

## Approval A — recorded

> Product Owner authorizes SC-M03 final-cutover Tier 1 prerequisite validation exactly as
> specified in the merged disposable-rehearsal runbooks, pinned to Universal Telegram
> `31519ee3ae297369118bf2deda6eae05d13a3d8b` and Universal Support Chat
> `ce4691241eb843485117b323516899df916fdaf7`.
>
> This authorizes only fresh throwaway checkouts and the disposable container/PHPUnit interop
> harness, synthetic fixtures, and zero Telegram network traffic. It does not authorize Tier 2,
> any DEV VPS action, any Telegram resource, or any production quiescence, migration, activation,
> route switch, cutover, deployment, release, tag, rollback, deletion, or retention change.

Companion record in Universal Telegram: `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`.
**This acceptance does not authorize Tier 2.**

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

### 7. F1 resolution — `channel_case_ref` carries the Support Chat conversation UUID (Proposed)

**Status: Proposed / awaiting Product Owner.** The Tier 1 prerequisite validation was attempted
on 2026-08-27 and **halted at the UT→SC deferred-update handoff phase by finding F1** (closure:
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`). F1 is a pre-existing
production seam: the Contract v1 wire field `channel_case_ref` is resolved by Support Chat as
its own `conversation_uuid`, but Universal Telegram sends `$binding->binding_uuid()`, and every
real binding mints an independent `binding_uuid`.

Proposed resolution, for Product Owner review:

- **Adopt option (b)** — `channel_case_ref` denotes the Support Chat `conversation_uuid` in
  every direction; Universal Telegram sends `ChannelBinding::support_conversation_uuid()` and
  retains `binding_uuid` only as its private binding-row identity. Support Chat's existing
  `resolve_conversation()` behaviour becomes the ratified contract. Recorded in **ADR-0011**
  (this repo) and **Universal Telegram ADR-0043**, with remediation plans in each repo. No
  schema change, no `db_version` bump, no new Contract operation.
- **Reject option (c)** — collapsing `binding_uuid` and `support_conversation_uuid` to be equal
  at creation. The deployed binding-creation paths intentionally mint an independent
  `binding_uuid`; formalizing equality contradicts shipped behaviour and forces an ADR-0009
  amendment. (Product Owner direction, 2026-08-27.)
- The exact stored field/source mapping is confirmed against source in ADR-0043 §Decision
  before any code change.

Until ADR-0011 and ADR-0043 are accepted and their remediation plans implemented (and the DEV
rehearsal runbook revised to v2 with F1 resolution as a hard precondition), **Tier 1 is not
re-attempted and Tier 2 is blocked on B1, B2, and F1.** Tier 1 re-attempt will require a
separate Approval A addendum.

## Non-authorization

This record authorizes nothing. It records the decisions the Product Owner must make before any
rehearsal — Tier 1 included — may run. No rehearsal has run. No acceptance record exists; adding
one is a later, separate Product Owner action.

## Affected documents

- [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) — the architecture this rehearsal exercises.
- [ADR-0011](../adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md) — F1 correction to ADR-0010 §4 `channel_case_ref` semantics (Proposed).
- [F1 remediation plan (Support Chat companion)](../plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md); primary in Universal Telegram.
- [Tier 1 closure](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md) — the finding of record.
- [Support Chat companion rehearsal plan](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md).
- Primary operator runbook (Universal Telegram) — `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d — planning-only cross-reference added.

## Compatibility / Migration impact

None. No runtime code, schema, plugin version, configuration, release, tag, deployment, or
infrastructure change is made by this record.
