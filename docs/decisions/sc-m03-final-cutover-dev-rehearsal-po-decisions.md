# Product Owner Decision Record — SC-M03 Final-Cutover Disposable DEV Rehearsal

## Status

**F1 correction MERGED (Addendum B). Approval A addendum RECORDED / Product Owner accepted
2026-08-28 (Addendum C). The single authorised Tier 1 re-attempt was EXECUTED 2026-08-28 and
PASSED (Addendum D) — Addendum C's one-time authorisation is now consumed. Tier 2 (Approval B)
still awaiting Product Owner, blocked on B1 and B2.**

Decision items 1, 3, 4, and 5 (Tier 1 scope, incident-evidence rules, Approval-A gate) and item
7 (F1 identity-correction implementation acceptance — implemented and merged) are recorded.
Decision items 2 and 6 (Tier 2 / Approval B) remain pending; Tier 2 stays blocked on B1 and B2.
The original Approval A was consumed by the halted 2026-08-27 Tier 1 attempt; **Addendum B**
below carries the F1-merge status, DEV rehearsal runbook v2, and the Approval A addendum text;
**Addendum C** records the Product Owner's 2026-08-28 acceptance of that addendum verbatim.

**2026-08-27 — Tier 1 attempted and halted by finding F1.** See decision item 7 below and
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`. F1 resolution was proposed in
ADR-0011 and Universal Telegram ADR-0043 (documentation-only).

**2026-08-27 — F1 identity-correction implementation ACCEPTED by the Product Owner.** Decision
item 7 is now **accepted**; ADR-0011 and Universal Telegram ADR-0043 are **Accepted**. This
authorizes **only** implementation of the frozen F1 remediation work packages — see
"F1 implementation acceptance — recorded" under decision item 7. It authorizes no schema /
`db_version` change, no new Contract operation, and no DEV, production, or operational cutover
action of any kind. **Tier 1 remains halted and unexecuted; Tier 2 remains blocked on B1, B2,
and F1.**

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

### 7. F1 resolution — `channel_case_ref` carries the Support Chat conversation UUID (ACCEPTED)

**Status: proposed 2026-08-27; ACCEPTED by the Product Owner 2026-08-27.** The decision history
below is preserved verbatim as first written; the "F1 implementation acceptance — recorded"
subsection at the end of this item records the Product Owner's verbatim authorization.

The Tier 1 prerequisite validation was attempted
on 2026-08-27 and **halted at the UT→SC deferred-update handoff phase by finding F1** (closure:
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`). F1 is a pre-existing
production seam: the Contract v1 wire field `channel_case_ref` is resolved by Support Chat as
its own `conversation_uuid`, but Universal Telegram sends `$binding->binding_uuid()`, and every
real binding mints an independent `binding_uuid`.

The identity rule, for Product Owner review (recorded in **ADR-0011** here and **Universal
Telegram ADR-0043**, with a remediation plan in each repo):

- **`channel_case_ref` means the Support Chat conversation / case UUID** — the SC-owned case
  identity — in every Contract v1 operation, both directions, live and cutover-replay. Support
  Chat resolves it through its existing authoritative conversation repository (`find_by_uuid()`),
  unchanged. The `legacy_handoff_map.channel_case_ref` value is that conversation UUID (already
  the code's behaviour).
- **The Universal Telegram binding UUID remains UT-local** — used for binding lookup, lifecycle,
  activation, routing identity, and idempotency keys — and **never crosses the Contract v1
  wire**. Universal Telegram sends `ChannelBinding::support_conversation_uuid()` (an existing
  `NOT NULL`, `UNIQUE` column on every binding row, populated from the SC conversation UUID at
  creation).
- **Option (c) — requiring `binding_uuid == support_conversation_uuid` — is rejected.** The
  deployed creation paths (`LegacyBindingImportServiceV1`, `EnsureChannelCaseService`)
  intentionally mint an independent `binding_uuid`; equality must never be required or used as a
  workaround. (Product Owner direction, 2026-08-27.)
- **An SC-side binding→conversation resolution mechanism is rejected** — no new lookup table,
  no direct Universal Telegram SQL, no shared map, no fallback interpreting a UT binding UUID as
  an SC identifier — **unless separately designed later** in its own ADR with its own
  justification.
- **Any deterministic Support Chat refusal after an active binding has been selected fails
  closed with a classified terminal outcome** — new closed incident codes
  `unresolved_case_reference` (`404 not_found`) and `handoff_rejected` (the deterministic
  `400`/`409` refusals) — never an unbounded transient retry that blocks replay without an
  outcome. Only genuinely transient conditions (`503 request_failed`, `401 contract_auth_failed`,
  transport / unavailable / unpaired peer) stay retryable. The classification is **exhaustive**
  (Universal Telegram ADR-0043 §3): no generic fallback, no fallback to legacy processing once
  an active binding is selected, no implicit UUID-equality assumption.
- The exact stored field/source mapping was confirmed against source (UT `31519ee` / SC
  `ce46912`) before any wording was frozen — see ADR-0043 §2.1.
- **No production action is authorized** by this decision or the ADRs. No schema or `db_version`
  change.

#### F1 implementation acceptance — recorded (2026-08-27)

The Product Owner authorization required before F1 implementation may begin (Universal Telegram
remediation plan §15) is recorded here verbatim:

> **Product Owner authorization — SC-M03 final-cutover F1 identity-correction implementation**
>
> I have reviewed ADR-0043 (Universal Telegram) and ADR-0011 (Universal Support Chat) and their F1 remediation plans, frozen as documentation-only. I accept the frozen identity rule: `channel_case_ref` in Contract v1 identifies the Support Chat conversation/case (resolved via Support Chat's own conversation repository); the Universal Telegram binding UUID is a UT-owned binding identity that never crosses the Contract v1 wire; equality of the two UUIDs is never required or assumed; no Support Chat binding→conversation resolver, shared map, or UT-binding-UUID fallback is added; a missing/malformed/non-existent case reference after an active binding is selected is a classified terminal incident, never an unbounded retry.
>
> I authorize implementation of exactly the work packages in `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` (and its Support Chat companion), pinned to the accepted baselines, in normal feature branches with per-repo CI and the interop harness. This authorizes **no** schema or `db_version` change, **no** new Contract operation, **no** DEV or production quiescence, migration, activation, route switch, cutover, deployment, release, tag, or rollback, and **no** execution of Tier 1 or Tier 2 of the DEV rehearsal.
>
> A Tier 1 re-attempt remains a separate authorization (a new Approval A addendum) after this implementation is merged, CI-green, and its real-binding handoff path passes, under DEV rehearsal runbook v2. Tier 2 stays blocked on B1, B2, and F1.

**This acceptance authorizes only F1 implementation.** It does not authorize, and no later task
may read it as authorizing: Tier 1 or Tier 2 of the DEV rehearsal, any DEV VPS action, any
Telegram resource, any schema / `db_version` / plugin-version change, any new Contract
operation, or any production or DEV quiescence, migration, cohort activation, route switch,
cutover, deployment, soak, release, tag, rollback, deletion, or retention change.

- **Companion record (Universal Telegram):** `docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-approval.md`.
- **Acceptance PRs:** universal-support-chat `https://github.com/magpern/universal-support-chat/pull/25`; universal-telegram `https://github.com/magpern/universal-telegram/pull/52`.
- **ADR status:** ADR-0011 (this repo) and Universal Telegram ADR-0043 are now **Accepted** (dated acceptance note in each ADR's Status section). The Status-field amendment note to ADR-0010 §4 is applied as work package WP-F1-S-3 of the implementation, per the companion plan.

**Tier 1 acceptance gate.** Tier 1 halted because of F1. **Tier 1 cannot be accepted until the
correction is implemented in both repositories and its real-binding handoff path (bindings
created by `LegacyBindingImportServiceV1` / `EnsureChannelCaseService`, not equality fixtures)
passes green in the interop harness.** Until then Tier 1's status is "attempted → halted by F1".
A Tier 1 re-attempt requires a separate Approval A addendum and runs only under DEV rehearsal
runbook v2. **Tier 2 retains its B1 and B2 blockers and its unexecuted status, and is
additionally blocked on F1.**

## Addendum B — 2026-08-28 — F1 correction merged; DEV rehearsal runbook v2; proposed Approval A addendum

Explicitly labelled addendum. It records subsequent status; no decision item above is edited.

- **F1 implementation and its closure are MERGED in both repositories.** universal-support-chat
  #26 → `9144cb1e2362c2be8d4c74f1461bba7ffe236575` (comment corrections C1–C4 only — no runtime,
  schema, `universal_support_chat_db_version`, resolver, or Contract-operation change) / closure
  #27 → `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`. universal-telegram #53 →
  `7d4cc4fecb97f862721cea0fec427ade26b46ea7` (adapter sends `support_conversation_uuid()` as
  `channel_case_ref`; `binding_uuid` off the wire; new exhaustive fail-closed
  `CutoverReplayFailureClassifier` — `unresolved_case_reference` / `handoff_rejected`) / closure
  #54 → `32f17ea904a33cdd1f9b0225ba9638f95a09d883`. The real dual-plugin interop suite passed
  **OK (47 tests, 722 assertions)** on both supported WP/PHP variants post-merge. Closure:
  [F1 implementation closure](../closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
- **F1 is no longer a code blocker.** Tier 1's status is still "attempted 2026-08-27 → halted by
  F1"; it remains **unexecuted and unaccepted**. A Tier 1 re-attempt requires a **separate
  Approval A addendum** (the original Approval A was consumed by the halted run) and runs only
  under **DEV rehearsal runbook v2**.
- **DEV rehearsal runbook v2** supersedes v1: primary
  `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`;
  Support Chat companion [`sc-m03-final-cutover-dev-rehearsal-plan-v2.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md).
  Pins the **immutable, Product-Owner-approved Tier 1 execution baselines** universal-telegram
  `6eed0228286e84b4e56e0119f242b483f138a58e` / universal-support-chat
  `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators fetch origin, verify these exact commits
  exist, and check them out before execution; runtime trees byte-identical to the F1
  implementation commits (`7d4cc4f` / `9144cb1`), documentation only added since; future
  documentation merges must not alter the baseline without a new Product Owner approval. It
  revises only the
  F1-invalidated portions of v1 (wire identity, Run 1 handoff fixture/assertions with a real
  distinct `binding_uuid`, the exhaustive fail-closed classifier referenced by Runs 2 and 3, a
  new Run 1 F1-correction gate, new Run 3 fail-closed incident scenarios, and the exact Tier 1
  re-run pass/fail evidence). All v1 safety boundaries, evidence/redaction/teardown requirements,
  the Tier 1/Tier 2 distinction, and blockers B1–B5 are carried forward.
- **Tier 2** stays blocked on **B1 and B2** and pending **Approval B** (unchanged).

### Proposed Approval A addendum text (Tier 1 re-attempt under runbook v2) — NOT signed, NOT recorded

Reproduced verbatim from the Universal Telegram primary runbook v2 §10.3 and
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md` (Status: Proposed —
awaiting Product Owner signature):

> **Product Owner authorization — SC-M03 final-cutover Tier 1 prerequisite validation, re-attempt under DEV rehearsal runbook v2 (Approval A addendum)**
>
> The original Approval A (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`) was consumed by the Tier 1 attempt of 2026-08-27, which was correctly halted by finding F1. F1 has since been corrected and merged in both repositories (universal-telegram #53 → `7d4cc4fecb97f862721cea0fec427ade26b46ea7`, closure #54 → `32f17ea904a33cdd1f9b0225ba9638f95a09d883`; universal-support-chat #26 → `9144cb1e2362c2be8d4c74f1461bba7ffe236575`, closure #27 → `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`) and verified green by the real dual-plugin interop suite on both supported WP/PHP variants.
>
> The **immutable, Product-Owner-approved Tier 1 execution baselines** for this authorization are universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a`. Before execution, operators must fetch origin, verify these exact commits exist, and check out these exact SHAs. These commits include DEV rehearsal runbook v2 and this corrected proposed Approval A addendum; their runtime trees remain byte-identical to the F1 implementation commits (universal-telegram `7d4cc4f`, universal-support-chat `9144cb1`) — no code, schema, `db_version`, test, configuration, workflow, or runtime change occurred after F1, only documentation. Future documentation merges must not alter this authorised execution baseline unless a new Product Owner approval is recorded.
>
> I authorize a **single Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated operational-sequence / integration validation, exactly as described in DEV rehearsal runbook **v2** and its Support Chat companion, at the immutable Tier 1 execution baselines universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators must fetch origin, verify these exact commits exist, and check out these exact SHAs before execution.
>
> This authorization is limited to: the container/PHPUnit interop harness only (`docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, `docker compose … down -v` before and after every run); the ephemeral Docker resources that harness creates intrinsically — Docker containers, networks, and named volumes brought up by `docker/docker-compose.yml` together with `docker/docker-compose.interop.yml`, solely for fresh synthetic test databases and harness services, and removed by `docker compose … down -v` after every run; fresh throwaway repository checkouts at the two immutable Tier 1 execution baseline SHAs above — each contains DEV rehearsal runbook v2 and this Approval A addendum, and its `src/` / `tests/` / configuration / CI-workflow trees are byte-identical to the F1 implementation commits; entirely synthetic fixture data created by the rehearsal's own code; Runs 1, 2, and 3 of runbook v2 §7, including the Run 1 step 11a F1-correction gate and the Run 3 `unresolved_case_reference` / `handoff_rejected` incident scenarios; both supported WP/PHP variants, each in a fresh disposable database.
>
> It does **NOT** authorize, under any circumstance: Tier 2 or any disposable DEV rehearsal; any action against `/opt/biopentra/dev/*`, `dev.biopentra.eu`, its database, its Redis, its bot(s), its webhook, its SWAG vhost, or any existing conversation; any Telegram network traffic whatsoever — no bot token (real or dedicated), no `setWebhook`, no `sendMessage`, no group or topic action, no `api.telegram.org` request; any real, dedicated, or newly-created Telegram bot, supergroup, or topic; any real user, operator, or production conversation data in any fixture; any infrastructure or resource creation beyond the ephemeral harness Docker resources named above — in particular no DEV VPS instance, WordPress site, Redis service, SWAG configuration, DNS record, TLS certificate, Telegram resource, credential, host-level persistent service, or any resource under `/opt/biopentra/dev/*` or `dev.biopentra.eu`; any production or DEV quiescence window, migration, binding preparation, cohort activation, deferred-update replay outside the disposable harness, route switch, cutover, soak, deployment, release, tag, rollback, deletion, or retention change; any acknowledge, overwrite, hand-edit, or repair of an incident row to make a run pass, and any use of `cutover incident-acknowledge` outside the explicitly synthetic §7.5 scenario; any schema, `Migrator::target_version()`, `universal_support_chat_db_version`, plugin-version, Contract-operation, configuration, CI-workflow, or test change.
>
> The operator must halt on any runbook v2 §8.2 hard stop condition and escalate to me. A Tier 1 re-run is PASS only when every §9.2 evidence item is captured (redacted per §5) and teardown is proven; Run 3 legitimately ends "blocked-as-designed" without reaching `confirm-complete`.
>
> Approval B (Tier 2) remains a separate, later authorization and cannot take effect until this Tier 1 re-attempt passes and B1 and B2 are proven resolved.
>
> Signed: __________________________  Date: __________

**Addendum B itself recorded nothing as accepted** — the text above is the addendum "as
proposed", retained verbatim for decision history. Its Product Owner acceptance is recorded in
**Addendum C** below.

## Addendum C — 2026-08-28 — Approval A addendum RECORDED (Product Owner accepted)

Explicitly labelled addendum. It records the Product Owner's acceptance; no decision item and no
earlier text above is edited.

**On 2026-08-28 the Product Owner accepted the Approval A addendum verbatim** (the "as proposed"
text in Addendum B and in the Universal Telegram record
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`). The acceptance is
recorded there, verbatim, under "Product Owner acceptance — recorded 2026-08-28", per the
acceptance-record convention used for decision item 7 (F1 implementation).

This acceptance:

- **authorizes exactly one (1) Tier 1 re-attempt** of the SC-M03 final-cutover disposable
  automated operational-sequence / integration validation (Runs 1, 2, and 3 of DEV rehearsal
  runbook v2 §7), at the **immutable execution baseline SHAs** universal-telegram
  `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
  `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators fetch origin, verify these exact commits
  exist, and check them out before execution;
- runs **only** in the disposable `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`
  container/PHPUnit interop harness (`docker compose … down -v` before and after every run,
  including the ephemeral Docker containers, networks, and named volumes that harness creates
  intrinsically for fresh synthetic test databases and harness services), with entirely synthetic
  fixtures and **zero Telegram network traffic**, on both supported WP/PHP variants;
- does **not** authorize Tier 2 or any disposable DEV rehearsal; any DEV VPS action or action
  against `/opt/biopentra/dev/*` or `dev.biopentra.eu`; any Telegram network traffic, bot token,
  webhook, group, or topic; any production activity; any operational cutover action — no
  quiescence window, migration, binding preparation, cohort activation, deferred-update replay
  outside the disposable harness, route switch, cutover, soak, deployment, release, tag,
  rollback, deletion, or retention change; any incident-row acknowledge / overwrite / repair to
  force a pass; or any code, test, schema, `universal_support_chat_db_version`, plugin-version,
  configuration, CI-workflow, or immutable-baseline-SHA change;
- leaves **Approval B (Tier 2)** a separate, later Product Owner action, blocked on B1 and B2.

**A second Tier 1 attempt, or any change to the immutable baseline SHAs, requires a new Product
Owner approval recorded here.** No rehearsal has run.

**Next authorised step: execute the single disposable Tier 1 re-attempt only** (Universal
Telegram record §"Next authorised step").

## Addendum D — 2026-08-28 — Tier 1 re-attempt EXECUTED and PASSED

Explicitly labelled addendum. It records the outcome of the single re-attempt authorised by
Addendum C; no decision item and no earlier text above is edited.

**On 2026-08-28 the single authorised Tier 1 re-attempt was executed and PASSED.** It ran at the
immutable execution baseline SHAs universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e`
and universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (fresh throwaway checkouts,
verified on origin before checkout, detached HEAD, clean tree), in the disposable
`docker/docker-compose.yml` + `docker/docker-compose.interop.yml` interop harness only, with
synthetic fixtures and zero Telegram network traffic, on both supported WP/PHP variants
(WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3), `docker compose … down -v` before and after every run.

- Dual-plugin interop suite: **OK (47 tests, 722 assertions)** on both variants.
- Unit and wp-only integration suites green on both variants.
- The F1-correction gate held with a real `LegacyBindingImportServiceV1`-prepared binding
  (`binding_uuid ≠ support_conversation_uuid`); the `legacy_handoff_map` row is keyed by the
  Support Chat conversation UUID.
- The exhaustive fail-closed replay classifier is confirmed; the `unresolved_case_reference`
  and `handoff_rejected` incident paths block `replaying → idle` and `confirm-complete` as
  designed; no incident row was mutated, acknowledged, or repaired.
- Teardown proven: no `t1re` container, volume, or network survives.

**No Support Chat runtime code, schema, `universal_support_chat_db_version` (11), test,
configuration, CI-workflow, or immutable-baseline SHA was changed.** Closure records:
[Tier 1 re-attempt closure (Support Chat)](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md);
primary closure `https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`.

**Tier 1 is complete. Addendum C's one-time authorisation is consumed.** No further Tier 1 run is
authorised; a second Tier 1 attempt, or any change to the immutable baseline SHAs, requires a new
Product Owner approval recorded here. Tier 2 (Approval B) remains a separate, later Product Owner
action, blocked on B1 and B2.

## Non-authorization

Apart from the F1 implementation acceptance recorded under decision item 7 (implementation of the
frozen F1 remediation work packages — merged) and the Approval A addendum recorded under Addendum
C (**exactly one (1)** disposable Tier 1 re-attempt at the immutable baselines — executed and
passed 2026-08-28, Addendum D), this record authorizes nothing operational. Tier 2 remains
unexecuted and blocked on B1 and B2; Approval B (Tier 2) and any second Tier 1 attempt remain
separate, later Product Owner actions.

## Affected documents

- [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) — the architecture this rehearsal exercises.
- [ADR-0011](../adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md) — F1 correction to ADR-0010 §4 `channel_case_ref` semantics (**Accepted** 2026-08-27).
- [F1 implementation acceptance companion record (Universal Telegram)](https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-approval.md).
- [F1 remediation plan (Support Chat companion)](../plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md); primary in Universal Telegram.
- [Tier 1 closure](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md) — the halted first attempt / finding of record.
- [Tier 1 re-attempt closure](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md) — the single authorised re-attempt, executed and passed 2026-08-28.
- [Support Chat companion rehearsal plan v1](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md) (superseded) and [companion v2](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md) (current).
- [F1 implementation closure (Support Chat)](../closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
- Primary operator runbook (Universal Telegram) — v1 `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` (superseded); **v2** `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (current); Approval A addendum (**recorded 2026-08-28**) `https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`.
- [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d — planning-only cross-reference added.

## Compatibility / Migration impact

None. No runtime code, schema, plugin version, configuration, release, tag, deployment, or
infrastructure change is made by this record.
