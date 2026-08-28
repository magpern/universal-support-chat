# SC-M03 Final-Cutover — Disposable DEV Rehearsal Plan v2 (Support Chat companion)

**Status: planning-only. No rehearsal has run under this runbook.** This document authorizes
nothing and changes no code, schema, plugin version, `universal_support_chat_db_version`,
configuration, test, tag, release, deployment, or infrastructure. The **Approval A addendum is
RECORDED / Product Owner accepted 2026-08-28** and authorizes **exactly one (1)** disposable
Tier 1 re-attempt at the two immutable execution baseline SHAs; Approval B (Tier 2) is still
outstanding and blocked on B1 + B2.

**Primary runbook:** `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`
(Universal Telegram owns the cutover and quiescence CLI and the operative runbook).

**This companion supersedes [`sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](sc-m03-final-cutover-dev-rehearsal-plan-v1.md).**
It revises only what finding F1 invalidated. v1 is retained unedited as the historical record of
the halted first attempt.

## 0. Why v2 exists — finding F1, now corrected

Tier 1 was executed on 2026-08-27 and **HALTED** at the UT→SC deferred-update handoff phase by
**finding F1** — closure [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md)
(this repo, merge `fcbfaa773ef63661b6d8ce42962f10bb174588f8`; Universal Telegram closure +
characterization test, merge `98c602543bd67bc471e2a88468d175fb6e659b46`).

**F1**: `ContractOperationDispatcher::resolve_conversation()` resolves `channel_case_ref` as this
repository's own `conversation_uuid` — **correct** — but Universal Telegram was sending the UT
`binding_uuid`, and every real binding-creation path mints an independent one. Secondary defect:
Universal Telegram's `CutoverReplayDispatcher::finish()` treated the resulting `404 not_found` as
an unbounded transient retry.

**F1 is corrected and merged** ([ADR-0011](../adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md)
/ Universal Telegram ADR-0043, both **Accepted** 2026-08-27):

- `channel_case_ref` carries the **Support Chat conversation/case UUID**. Universal Telegram
  sends `ChannelBinding::support_conversation_uuid()`; `binding_uuid` never crosses the Contract
  v1 wire.
- **This repository needed no runtime, schema, `universal_support_chat_db_version`, resolver, or
  Contract-operation change** — comment corrections C1–C4 only (`Migrator::step_11_…` docblock,
  `HandoffMapRepository::insert()` / `find()` docblocks, `ContractOperationDispatcher` class +
  `resolve_conversation()` docblocks). `resolve_conversation()`, `dispatch_with_provenance()`,
  and the `legacy_handoff_map` shape were already correct.
- Universal Telegram's `CutoverReplayFailureClassifier` makes `finish()` exhaustive and
  fail-closed: `404 not_found` → new closed incident `unresolved_case_reference`; the enumerated
  deterministic `400`/`409` refusals and any unrecognised `ok:false` reason → new closed
  incident `handoff_rejected`; `409 handoff_provenance_conflict` unchanged; only the frozen
  explicit transient set stays retryable.

Merge evidence:

| Repo | F1 runtime PR | F1 merge | F1 closure PR | Closure merge |
|---|---|---|---|---|
| universal-support-chat | #26 (comments only) | `9144cb1e2362c2be8d4c74f1461bba7ffe236575` | #27 | `5d81b5b7795ee50f3a79e535a483d7677b36d1c0` |
| universal-telegram | #53 | `7d4cc4fecb97f862721cea0fec427ade26b46ea7` | #54 | `32f17ea904a33cdd1f9b0225ba9638f95a09d883` |

Post-merge, the real dual-plugin interop harness (merged UT `main` + this repository's F1
branch, fresh disposable database per run) passed **OK (47 tests, 722 assertions)** on **both**
supported WP/PHP variants.

The **immutable, Product-Owner-approved Tier 1 execution baselines** this runbook pins (§1) are
universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` and universal-telegram
`6eed0228286e84b4e56e0119f242b483f138a58e`. Operators must fetch origin, verify these exact
commits exist, and check out these exact SHAs before execution. These commits include DEV
rehearsal runbook v2 and the corrected proposed Approval A addendum; their runtime trees remain
byte-identical to the F1 implementation commits (universal-support-chat `9144cb1`,
universal-telegram `7d4cc4f`) — no code, schema, `db_version`, test, configuration, workflow, or
runtime change occurred after F1, only documentation. **Future documentation merges must not
alter this authorised execution baseline unless a new Product Owner approval is recorded.**

**F1 is no longer a code blocker.** The Tier 1 re-attempt is gated on a **separate Approval A
addendum** (Universal Telegram
[`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`](https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md);
verbatim text and its acceptance also in this repository's decision record, "Addendum B" /
"Addendum C"). **That addendum is RECORDED / Product Owner accepted 2026-08-28: it authorizes
exactly one (1) Tier 1 re-attempt at the two immutable execution baseline SHAs and nothing else.**
Tier 2 remains blocked on B1 and B2 and pending Approval B.

## 1. Charter, ADRs, and pinned baselines (revised)

- Charter: [`docs/milestones/sc-m03-controlled-migration-and-cutover.md`](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d.
- This repository: [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) **as amended by [ADR-0011](../adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md)** (§4 `channel_case_ref` semantics), and the [decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md).
- F1 remediation: [`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`](sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md); F1 implementation closure [`docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](../closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
- Universal Telegram companion architecture: ADR-0042 as amended by ADR-0043.

**Immutable Tier 1 execution baselines this rehearsal pins** (operators fetch origin, verify
these exact commits exist, and check out these exact SHAs before execution — see §0; future
documentation merges must not alter this baseline unless a new Product Owner approval is
recorded):

| Repository | Pinned SHA |
|---|---|
| `magpern/universal-support-chat` | `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — plugin `0.6.0`, `universal_support_chat_db_version` `11` (unchanged by F1). Contains the companion runbook v2 and the Approval A addendum text; `src/`+`tests/`+config+CI tree byte-identical to the F1 implementation commit (`9144cb1`) — documentation only added since. |
| `magpern/universal-telegram` | `6eed0228286e84b4e56e0119f242b483f138a58e` — plugin `0.19.0`, schema `target_version()` `36` (unchanged by F1). Contains DEV rehearsal runbook v2 and the corrected proposed Approval A addendum; `src/`+`tests/`+config+CI tree byte-identical to the F1 implementation commit (`7d4cc4f`) — documentation only added since. |

## 2. Tier boundary — Tier 1 is not the DEV rehearsal (unchanged from v1 §2)

| Tier | What it is | Status under v2 |
|---|---|---|
| **Tier 1** | A required disposable automated operational-sequence / integration validation in the container/PHPUnit interop harness (`docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, `down -v` before and after), zero Telegram traffic. Proves data effects, state-machine sequencing, CLI-equivalent service ordering of Runs 1, 2, 3. | **Required prerequisite. Unexecuted under v2.** The Approval A addendum is **RECORDED (2026-08-28)** and authorizes **exactly one (1)** Tier 1 re-attempt at the two immutable execution baseline SHAs. |
| **Tier 2** | The first actual disposable DEV rehearsal: an isolated full-WordPress instance plus a dedicated non-production Telegram bot + test supergroup + test topics. | **Required. Blocked on B1 and B2.** |

**Tier 1 does NOT satisfy the accepted requirement for a disposable DEV rehearsal.** B1 and B2
block execution of the DEV rehearsal itself. Approval A (addendum) must be signed and Tier 1
must pass before Approval B (Tier 2) can take effect.

## 3. Support Chat repository findings — corrected `channel_case_ref` wire identity

**This section replaces v1 §3's "Wire detail" bullet.** All other v1 §3 findings (the two
Support Chat WP-CLI families and their authority flags / dry-run behaviour;
`legacy-migrate run --phase=reconcile` = Phase B and its refusal reasons;
`PhaseBReconciliationService::run()` continuous re-check; Phase A safeguards; ownership
dispositions; `legacy-bind run` only ever writes `status='prepared'`; **do not use Universal
Telegram's `support-chat-bindings import --apply`**; the handoff-map columns / `UNIQUE KEY
bot_update` / server-derived `kind` / `409 handoff_provenance_conflict` / `verify_step_11`
forbidden-column guard; crash/retry convergence; Contract v1 in-process Ed25519 pairing;
`cutover begin` is mutating and only `cutover status` / `cutover recover` are read-only)
**remain in force verbatim from v1.**

### 3.1 `channel_case_ref` = this repository's conversation/case UUID (ADR-0011)

- The Contract v1 wire field `channel_case_ref`, on every provenance-capable operation
  (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`,
  `report_channel_unavailable`), carries **this repository's conversation/case UUID**.
- `ContractOperationDispatcher::resolve_conversation()` resolves it via
  `ConversationRepository::find_by_uuid()` — **unchanged**. A malformed or unknown ref →
  `null` → handler `404 not_found`, no domain write, no `legacy_handoff_map` row — **the correct
  fail-closed behaviour, unchanged.**
- `dispatch_with_provenance()` is passed `$conversation->uuid()` and writes that into
  `legacy_handoff_map.channel_case_ref` — **unchanged; already the conversation UUID, never a UT
  binding UUID.**
- **No SC-side binding→conversation resolver, shared map, direct Universal Telegram SQL, or
  UT-binding-UUID fallback exists or is to be added** (rejected by ADR-0011 unless separately
  designed later in its own ADR).
- Universal Telegram now sends `ChannelBinding::support_conversation_uuid()` (an existing
  `CHAR(36) NOT NULL UNIQUE` column, populated from this repository's conversation UUID by both
  `LegacyBindingImportServiceV1` and `EnsureChannelCaseService`) and keeps `binding_uuid`
  UT-local, off the wire.

**Consequence for the rehearsal:** the v1 assumption that a fixture binding's `binding_uuid`
must equal the Support Chat conversation UUID is **void**. Every rehearsal binding is created by
the real `LegacyBindingImportServiceV1` (via `legacy-bind run`) with an independent
`binding_uuid`, and the rehearsal asserts the wire `channel_case_ref` and the
`legacy_handoff_map.channel_case_ref` both equal the conversation UUID, never `binding_uuid`.

### 3.2 Fail-closed classification (Universal Telegram side, referenced here)

`CutoverReplayDispatcher::finish()` (Universal Telegram, ADR-0043 §3): `404 not_found` →
`unresolved_case_reference`; deterministic `400 invalid_body` / `invalid_operator` /
`unsupported_operation`, `409 already_claimed` / `claimed_by_other` / `invalid_transition`,
`sc_contract_unsupported_operation`, and **any unrecognised `ok:false` reason** →
`handoff_rejected`; `409 handoff_provenance_conflict` unchanged; only `503 request_failed`,
`401 contract_auth_failed`, and the UT client transport/unavailable/unpaired gates stay
retryable. Support Chat emits **no** incident of its own — it returns the deterministic status
and Universal Telegram classifies it as a durable UT-only incident that blocks
`replaying → idle` / `confirm-complete`.

**Operator rule:** an `unresolved_case_reference` or `handoff_rejected` incident, and any
unknown/rejected Contract failure, **must remain a durable incident** — never hand-edited, never
reclassified as `retried_success` without a real retry that genuinely succeeded, never
`incident-acknowledge`d to force a run to `confirm-complete`. Acknowledgement is rehearsed only
as the separate §7.5 scenario (primary runbook), with a synthetic opaque `--po-decision-ref`.

## 4. Assumptions requiring DEV verification

v1 §4 assumptions **A2, A5 unchanged.** Revised:

| # | Assumption | Verify |
|---|---|---|
| A1 | Checked-out plugin SHAs equal the immutable Tier 1 execution baselines (`4f833c3…` / `6eed022…`); schema SC `11` / UT `36`. | `git rev-parse HEAD`; `wp eval` on `get_option('universal_support_chat_db_version')` / `Migrator::target_version()`. |
| A3 | Whether Universal Telegram's `cutover begin` preflight enforces the Support-Chat-side "mapping-complete" cross-check, or only the local `prepared` binding. | read UT `CutoverActivationService::preflight()` at `6eed022…` (runtime tree identical to `7d4cc4f` — the F1 implementation commit); drive a candidate whose SC map row is not `migrated` and observe whether `begin` refuses it. |
| A4 | The exact Support Chat CLI used to confirm `status='migrated'` is `wp universal-support-chat legacy-migrate status` / `validate`. | confirm against `LegacyMigrateCommand.php`; cross-check a known `legacy_migration_map` row via `wp eval`. |
| **A9 (new)** | A real `legacy-bind`-prepared binding (independent `binding_uuid`) is now handed off successfully — F1's correction holds end-to-end in the disposable harness. | primary runbook Run 1 step 11a: activate one real binding, buffer one operator reply, one `replay-deferred-updates` pass, assert `OUTCOME_HANDED_OFF` + one `legacy_handoff_map` row whose `channel_case_ref` is the conversation UUID ≠ `binding_uuid`. **A hard gate before `cutover begin`.** |

## 5. Support Chat side of the rehearsal (revised)

v1 §5.1 (Phase A / Phase B evidence), §5.2 (mapping / migration validation), and §5.3 (the
required Support-Chat-side `status='migrated'` assertion via CLI + `wp eval` **before** Universal
Telegram `cutover begin` — B4 compensation) **apply verbatim.** Revised:

### 5.4 Contract pairing and handoff evidence (revised for the F1-corrected identity)

- **Pairing**: perform the admin pairing action in the disposable env (or use the interop
  harness's real two-way Ed25519 pairing); assert
  `GET /universal-support-chat/v1/channel-contract` returns `channel_available:true` with the six
  cutover ops on the paired peer's allow-list.
- **Handoff**: for each handed-off deferred row, assert exactly one Support Chat domain effect
  (`conversation_messages` row for a reply; assignment change for `claim`;
  `ChannelStatusRepository` degraded row for `forum_topic_closed`) **and** exactly one
  `legacy_handoff_map` row with server-derived `kind`, **`channel_case_ref` = the Support Chat
  conversation UUID (equal to Universal Telegram's `support_conversation_uuid()`, never its
  `binding_uuid`)**, `target_message_uuid` populated only for `kind='message'`.
- **Idempotent retry**: a re-presented `(bot_id, update_id)` with matching `kind` +
  `channel_case_ref` → no second Support Chat message, no second map row; a mismatched one →
  `409 handoff_provenance_conflict`, no domain write, no map write.
- **Fail-closed cases** (new — primary runbook Run 3): a buffered row whose active binding's
  `support_conversation_uuid` points at a non-existent conversation → Support Chat `404
  not_found`, no domain/map write → Universal Telegram durable incident
  `unresolved_case_reference`; a buffered reply > 4096 chars → Support Chat `400 invalid_body`,
  no domain/map write → Universal Telegram durable incident `handoff_rejected`. Support Chat
  writes nothing and emits no incident of its own in either case.
- **No plaintext**: `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map` shows only
  ids / uuids / fixed-vocabulary / timestamps; `Migrator::verify_step_11` passes.

### 5.5 Same Tier 1 / Tier 2 boundary and approval dependency

The tier boundary and both approval texts (the **Approval A addendum** → a Tier 1 re-attempt
under v2; Approval B → Tier 2, gated on B1 + B2 + Tier 1 PASS) are owned by the primary runbook
(§4.1, §10) and reproduced in this repository's decision record ("Addendum B"). This companion
inherits them unchanged. **Nothing in this repository authorizes execution of either tier.**

## 6. Blockers (shared with the primary runbook)

| ID | Blocker | Blocks | Status under v2 |
|---|---|---|---|
| **B1** | No isolated full-WordPress rehearsal environment exists. | Execution of the DEV rehearsal (Tier 2). | Open. |
| **B2** | No dedicated non-production Telegram bot / test supergroup / test topics. | Execution of the DEV rehearsal (Tier 2). | Open. |
| **B3** | `cutover begin` and `cutover activate` have no dry-run. | Confidence that they can be "previewed." | Documented limitation. |
| **B4** | Assumption A3 unresolved. | Trusting `begin` alone as the migration-evidence gate; §5.3 compensates. | Compensated. |
| **B5 (governance)** | Product Owner authorization to execute the rehearsal under v2. | Tier 2; Tier 1 is now cleared for exactly one re-attempt. | **Tier 1: CLEARED** — Approval A addendum recorded 2026-08-28 (decision record Addendum C), one (1) re-attempt at the immutable baselines. **Tier 2: still open** — Approval B unchanged, blocked on B1 + B2. |
| ~~**F1**~~ | ~~The cutover deferred-update handoff cannot resolve a real prepared binding.~~ | ~~Tier 1 and Tier 2.~~ | **CLEARED 2026-08-27** — corrected and merged in both repositories; verified green by the real dual-plugin interop suite on both WP/PHP variants. A new pre-`begin` gate (A9) asserts the real-cohort handoff resolves in the disposable env before Tier 1 proceeds. |

## 7. Success criteria (Support Chat side; full list in the primary runbook §9)

v1 §7 items **1–10 apply**, with these revisions:

- **Item 5** (provenance handoff): the `legacy_handoff_map` row's `channel_case_ref` is the
  **Support Chat conversation UUID**, never a Universal Telegram binding UUID (it always was, on
  this side — v1's "= the binding UUID" wording was the F1 error and is corrected here).
- **New item 5a — F1-correction gate**: a real `legacy-bind`-prepared binding
  (`binding_uuid ≠ support_conversation_uuid`) is handed off (`OUTCOME_HANDED_OFF`, one map row,
  `handed_off_at` stamped) before `cutover begin`.
- **New item 7a — classified fail-closed incidents (Run 3)**: an injected `404` →
  `unresolved_case_reference`; an injected deterministic `400`/`409` refusal and an injected
  unrecognised `ok:false` reason → `handoff_rejected`; Support Chat writes nothing in each case;
  each blocks `replaying → idle` and `confirm-complete`; none is retryable; none is acknowledged
  to force a pass; every incident row is unchanged, verified at teardown.

Evidence artefacts, redaction rules (fixed-vocabulary allow-list now includes
`unresolved_case_reference` and `handoff_rejected`), and the evidence-bundle layout are owned by
the primary runbook (§5, §9.1, §9.2) and apply unchanged here.

## 8. Explicit out-of-scope / non-authorizations

This document authorizes nothing. It does not authorize execution of Tier 1 or Tier 2; any
production or DEV quiescence window, migration, binding preparation, cohort activation,
deferred-update replay, Telegram webhook, or operational command against `dev.biopentra.eu` or
production; creation of any infrastructure — a DEV VPS instance, WordPress site, Redis service,
SWAG configuration, DNS record, TLS certificate, Telegram bot / group / topic, credential, or
host-level persistent service — or any schema, plugin-version, `universal_support_chat_db_version`,
configuration, test, CI-workflow, tag, release, or deployment change. Under the recorded Approval
A addendum (2026-08-28), the single authorised Tier 1 re-attempt may bring up only the ephemeral
Docker containers, networks, and named volumes the disposable `docker/docker-compose.yml` +
`docker-compose.interop.yml` harness creates intrinsically for fresh synthetic test databases and
harness services, torn down by `docker compose … down -v` after every run — nothing else.
Approval B (Tier 2) remains a separate, later Product Owner approval, blocked on B1 + B2; a
second Tier 1 attempt needs a new Product Owner approval.

## 9. Definition of done (documentation stage only)

- This companion v2 and the decision-record addenda ("Addendum B", "Addendum C") are committed on
  documentation-only branches, reviewed, CI-green (including the `docs` job), and merged, after
  the Universal Telegram primary runbook v2 / addendum record are merged.
- v1 is left unedited; its "Amendment A" footer already points here.
- Registries, the plan index, the decisions index, and the milestone §0d page are updated
  **planning-only**.
- At the v2-freeze stage no acceptance record was added. **Subsequently, on 2026-08-28, the
  Product Owner accepted the Approval A addendum verbatim** (decision record Addendum C; Universal
  Telegram `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`) — it
  authorizes exactly one (1) Tier 1 re-attempt at the two immutable execution baseline SHAs and
  nothing else. No rehearsal has run.
