# SC-M03 Final-Cutover — Disposable DEV Rehearsal Plan v1 (Support Chat companion)

**Status: planning-only. No rehearsal has run. Product Owner execution approval is
outstanding.** This is the Support Chat companion to the primary operator runbook, which lives
in Universal Telegram (that repository owns the cutover and quiescence CLI). This document
authorizes nothing and changes no code, schema, plugin version, configuration, test, tag,
release, deployment, or infrastructure.

**Primary runbook:** `https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`

## 1. Charter, ADRs, and pinned baselines

- Charter: [`docs/milestones/sc-m03-controlled-migration-and-cutover.md`](../milestones/sc-m03-controlled-migration-and-cutover.md) §0d.
- This repository: [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md) and the [final-cutover Product Owner decision record](../decisions/sc-m03-final-cutover-po-decisions.md).
- Universal Telegram companion architecture: ADR-0042 (`https://github.com/magpern/universal-telegram/blob/main/docs/adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md`).
- Prior work packages relied on: [ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) (export boundary), [ADR-0009](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md) (binding preparation), and Universal Telegram's ADR-0039 / ADR-0040 / ADR-0041.
- Closures relied on: [WP3–4 migration engine](../closure/sc-m03-work-packages-3-4-legacy-migration-engine-closure.md), [WP2 quiescence re-check](../closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md), [the Phase-B continuous-recheck addendum](../closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md), [WP5 binding preparation](../closure/sc-m03-work-package-5-legacy-binding-preparation-closure.md), and [the final-cutover closure](../closure/sc-m03-final-cutover-closure.md) with its mutual-pairing addendum and "Product Owner acceptance (final)".
- Decision record: [`sc-m03-final-cutover-dev-rehearsal-po-decisions.md`](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md).

**Accepted baselines this rehearsal pins (freshly fetched `origin/main`, both HEAD):**

| Repository | Accepted SHA |
|---|---|
| `magpern/universal-support-chat` | `ce4691241eb843485117b323516899df916fdaf7` — plugin `0.6.0`, `universal_support_chat_db_version` `11` (handoff-map = Migrator step 11) |
| `magpern/universal-telegram` | `31519ee3ae297369118bf2deda6eae05d13a3d8b` — plugin `0.19.0`, schema `target_version()` `36` (cutover tables = Migrator steps 35–36) |

The final-cutover closure's "Product Owner acceptance (final)" states verbatim that acceptance
"does not authorize a DEV or production quiescence window, migration, cohort activation, route
switch, cutover, deployment, soak, rollback, deletion, release, or tag. The next possible
activity is a separately planned, disposable DEV rehearsal."

## 2. Tier boundary — Tier 1 is not the DEV rehearsal

| Tier | What it is | Status |
|---|---|---|
| **Tier 1** | A **required disposable automated operational-sequence / integration validation** in the container/PHPUnit interop harness (`docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, `down -v` before and after), **zero Telegram traffic**. Proves data effects, state-machine sequencing, and CLI-equivalent service ordering of Runs 1, 2, 3. | **Required prerequisite. Unexecuted.** |
| **Tier 2** | The **first actual disposable DEV rehearsal**: an isolated full-WordPress instance plus a **dedicated non-production Telegram bot + test supergroup + test topics**. This is what the accepted requirement calls for. | **Required. Blocked on B1 and B2.** |

**Tier 1 does NOT satisfy the accepted requirement for a disposable DEV rehearsal.** It lacks
real WP-Cron / Action Scheduler drain, the Redis object cache, authenticated Telegram webhook
ingress, real chat-widget traffic, and the DEV VPS runtime surface. **B1 (no isolated
full-WordPress instance) and B2 (no dedicated non-production Telegram resources) therefore block
execution of the DEV rehearsal**, not merely optional extra realism. Approval A must be signed
and Tier 1 must pass before Approval B (Tier 2) can take effect.

## 3. Support Chat repository findings (source-accurate at the pinned SHA)

Two Support Chat WP-CLI families exist; the cutover orchestration itself lives entirely in
Universal Telegram.

| Command | Source | Mutating? | Authority flag | Dry-run |
|---|---|---|---|---|
| `wp universal-support-chat legacy-migrate <run\|status\|validate>` `[--phase=backfill\|reconcile]` `[--batch-size=<n>]` | `src/Migration/Cli/LegacyMigrateCommand.php` | `run` (non-dry-run) only | `--assume-migration-authority` (ADR-0008 §4 — operator-confirmation, not a security control) | `--dry-run` (default for `run`); zero writes to any table incl. run/map/batch-log |
| `wp universal-support-chat legacy-bind <run\|status\|validate>` `[--limit=<n>]` | `src/Migration/Cli/LegacyBindCommand.php` | `run` (non-dry-run) only | `--assume-binding-authority` (ADR-0009 §7 — named distinctly from `--assume-migration-authority`) | `--dry-run`; runs the full pipeline incl. the real in-process quiescence lock + live re-check, commits nothing on either side |

- **`legacy-migrate run --phase=reconcile`** is Phase B. It refuses with `Phase B refused to run: <reason>` where reason is `not_quiescent` (the production `UniversalTelegramQuiescenceStateProvider` delegates in-process to Universal Telegram and fails closed to `false`) or `new_source_rows_since_last_backfill`.
- **`PhaseBReconciliationService::run()` re-checks `is_quiescent()`** at the top of every loop iteration and before each promotion write (WP2 continuous-recheck addendum). On loss it stops immediately, rolls back the in-progress row, promotes nothing further, returns `REFUSED_NOT_QUIESCENT`; rows already promoted stay promoted.
- **Phase A safeguards** (WP3–4 closure): per-conversation DB transaction (never per-batch); resumable high-water mark = `MAX(source_conversation_id)` in `legacy_migration_map`; checkpoint advance only after commit; effective batch size `min(max(n,1),100)`; Universal Telegram export cap 100 conversations/call; re-encryption through Support Chat's own `CredentialVault` (`body_ciphertext` never equals source plaintext); retention-nulled bodies imported as `NULL` (not a failure); a Universal Telegram typed export error (`{"id":…,"error":"decrypt_failed"}`) → durable `failed` map row + zero target rows.
- **Ownership dispositions** (WP3–4 PO decisions): `owner_user_id` copied only for the non-null, code-verified-semantics case; **ownerless** Universal Telegram conversations excluded with the durable reason `ownerless_conversation_unsupported` (never a placeholder owner); a note with null `operator_user_id` fails the whole conversation atomically with `note_operator_user_id_null_unsupported`; `assigned_operator_id` migrated as inert historical data (no UI); `consent_state` not migrated.
- **`legacy-bind run`** creates only `status = 'prepared'` bindings via `InProcessLegacyBindingImportClient` → Universal Telegram's `LegacyBindingImportServiceV1::import_batch()`. It never writes `status = 'active'`. Candidate identification is entirely from this repository's own `legacy_migration_map` rows with `status = 'migrated' AND binding_status IS NULL`. **Do not use Universal Telegram's `support-chat-bindings import --apply`** — it hardcodes `status='active'` with no source-liveness check (ADR-0041 §5 / WP5 PO decision item 3).
- **Handoff map** (`universal_support_chat_legacy_handoff_map`, Migrator step 11): columns `id, bot_id, update_id, kind, channel_case_ref, target_message_uuid, created_at`, `UNIQUE KEY bot_update (bot_id, update_id)`. `kind` ∈ `message|claim|release|resolve|reopen|channel_unavailable`, server-derived. Written transactionally alongside the domain effect by `ContractOperationDispatcher::dispatch_with_provenance()` only when both `source_bot_id` + `source_update_id` are present. A duplicate `(bot_id, update_id)` with matching `kind` + `channel_case_ref` converges silently; a mismatch → `409 handoff_provenance_conflict`, rollback, no domain write, no map write. `Migrator::verify_step_11` proves no `body|body_ciphertext|plaintext|content_hash|digest` column exists.
- **Crash/retry convergence**: Support Chat commits first (domain write + map row in one transaction); Universal Telegram stamps `handed_off_at` only after `{ok:true}`. At-least-once, not a distributed transaction.
- **Contract V1 pairing**: mutual detached Ed25519 signing (libsodium), no shared secret / bearer token. Pairing is a WordPress-admin action (this repository: `PairingPage` / `PairingActions`, nonce `usc_contract_pairing`; Universal Telegram: `PairingController`, nonce `universal_telegram_support_chat_pairing`), requiring the operator to hold **both** plugins' MANAGE capabilities. Transport is `InProcessContractTransport` via `rest_do_request()` — same PHP request, no HTTP hop, no new port. Discovery: unauthenticated `GET /universal-support-chat/v1/channel-contract`. Migration / binding / quiescence / activation boundaries are **NOT** Contract V1 — they are in-process WP-CLI-only PHP interfaces. The final-cutover handoff is the one place buffered traffic re-uses the live Contract V1 channel (the six ops `ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`; `update_assignment` / `report_delivery_failure` never carry provenance).
- **Wire detail** (final-cutover closure addendum): `channel_case_ref` on the wire = `$binding->binding_uuid()`, which this repository's dispatcher resolves directly as its own `conversation_uuid`. A fixture binding's `binding_uuid` must equal the Support Chat conversation UUID.
- **`cutover begin` (Universal Telegram) is MUTATING** — it inserts a `cutover_runs` row on a passing preflight. Only `cutover status` and `cutover recover` are read-only. `cutover begin` and `cutover activate` have no dry-run.

## 4. Assumptions requiring DEV verification

| # | Assumption | Verify |
|---|---|---|
| A1 | Checked-out plugin SHAs equal the accepted baselines; schema UT `36` / SC `11`. | `git rev-parse`; `wp eval` on `get_option('universal_support_chat_db_version')` / `Migrator::target_version()`. |
| A2 | Both plugins pair; discovery `channel_available:true` with the six cutover ops on the peer allow-list. | admin pairing (or the interop harness's real two-way pairing); `GET /universal-support-chat/v1/channel-contract`. |
| A3 | Whether Universal Telegram's `cutover begin` preflight enforces the Support-Chat-side "mapping-complete" cross-check, or only the local `prepared` binding. | read Universal Telegram's `CutoverActivationService::preflight()` at the pinned SHA; drive a candidate whose SC map row is not `migrated` and observe whether `begin` refuses it. |
| A4 | The exact Support Chat CLI used to confirm `status = 'migrated'` is `wp universal-support-chat legacy-migrate status` / `validate`. | confirm against `LegacyMigrateCommand.php`; cross-check a known map row via `wp eval`. |
| A5 | Support Chat's message-retention policy for migrated/handed-off conversations vs Universal Telegram's 30-day replayed/handed-off deferred-row retention are compatible. | read `Settings` defaults + retention sweeps in both repos at the pinned SHAs; document, do not change. |

## 5. Support Chat side of the rehearsal

### 5.1 Phase A / Phase B evidence

- **Phase A dry-run then real**: `legacy-migrate run --phase=backfill --dry-run`, then `… --assume-migration-authority`. Assert: every synthetic cohort conversation reaches a `legacy_migration_map` row; `body_ciphertext` ≠ source plaintext; counts correspond; ownerless / null-operator fixtures excluded with the correct durable reason; a second `--dry-run` backfill shows a stable high-water mark; Universal Telegram source rows unmutated.
- **Phase B dry-run then real** (only while Universal Telegram is `quiescent` with an empty deferred backlog): `legacy-migrate run --phase=reconcile --dry-run`, then `… --assume-migration-authority` → cohort member `status='migrated'`.
- **Phase B refusal / recovery (Run 2)**: inject one synthetic deferred update mid-reconcile → `REFUSED_NOT_QUIESCENT`; assert the in-progress row rolled back, earlier promotions intact. **No `legacy-bind` / `cutover begin` / `cutover activate` follows.** The full recovery sequence (owned by the primary runbook §7.2) then runs: Universal Telegram `quiescence exit` → `replay-deferred-updates` drains the injected row through **legacy `process_update()`** (not handed off, not an incident, no `legacy_handoff_map` row) → backlog 0 + `idle` → Universal Telegram `quiescence enter` + `confirm` → **re-run Phase B successfully** → only then binding preparation and cutover preflight.

### 5.2 Support Chat mapping / migration validation

- `legacy-migrate validate` — read-only registry self-consistency + count/correspondence checks over backfilled rows. Assert `N passed count/correspondence checks` with no warnings.
- `legacy-migrate status` — aggregate counts by status. Assert the expected `migrated` count and zero unexpected `failed` / exclusion reasons.
- Direct read of the `legacy_migration_map` row(s) via `wp eval`: `status`, message/note counts, exclusion reasons, `binding_status`.

### 5.3 Required Support-Chat-side `status='migrated'` assertion before Universal Telegram `cutover begin`

Because A3 is unresolved (B4), the rehearsal **must** assert, via `wp universal-support-chat
legacy-migrate status` / `validate` **plus** a direct `wp eval` read of every cohort member's
`legacy_migration_map.status`, that each is exactly `migrated` **before** the operator runs
`wp universal-telegram cutover begin`. `begin` alone is not accepted as the migration-evidence
gate.

### 5.4 Contract pairing and handoff evidence

- **Pairing**: perform the admin pairing action in the disposable env (or use the interop harness's real two-way Ed25519 pairing); assert `GET /universal-support-chat/v1/channel-contract` returns `channel_available:true` with the six cutover ops on the paired peer's allow-list.
- **Handoff**: for each handed-off deferred row, assert exactly one Support Chat domain effect (`conversation_messages` row for a reply, assignment change for `claim`, `ChannelStatusRepository` degraded row for `forum_topic_closed`) **and** exactly one `legacy_handoff_map` row with server-derived `kind`, `channel_case_ref` = the binding UUID, `target_message_uuid` populated only for `kind='message'`.
- **Idempotent retry**: a re-presented `(bot_id, update_id)` with matching `kind` + `channel_case_ref` → no second Support Chat message, no second map row; a mismatched one → `409 handoff_provenance_conflict`, no domain write, no map write.
- **No plaintext**: `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map` shows only ids / uuids / fixed-vocabulary / timestamps; `Migrator::verify_step_11` passes.

### 5.5 Same Tier 1 / Tier 2 boundary and approval dependency

The tier boundary and both approval texts (Approval A → Tier 1; Approval B → Tier 2, gated on
B1 + B2 + Tier 1 PASS) are owned by the primary runbook (§4.1, §10). This companion inherits
them unchanged. Nothing in this repository authorizes execution of either tier.

## 6. Blockers (shared with the primary runbook)

| ID | Blocker | Blocks |
|---|---|---|
| **B1** | No isolated full-WordPress rehearsal environment exists (the DEV VPS is one shared WordPress + MariaDB + Redis stack). | Execution of the DEV rehearsal (Tier 2). |
| **B2** | No dedicated non-production Telegram bot / test supergroup / test topics. | Execution of the DEV rehearsal (Tier 2). |
| **B3** | `cutover begin` (inserts a `cutover_runs` row) and `cutover activate` (writes binding status) have no dry-run. | Confidence that they can be "previewed." |
| **B4** | Assumption A3 unresolved — a cohort could pass Universal Telegram `begin` preflight while its Support Chat map row is not `migrated`. | Trusting `begin` alone as the migration-evidence gate; §5.3 compensates. |
| **B5 (governance)** | Product Owner has not approved executing any rehearsal. | The entire rehearsal (both tiers). |

## 7. Success criteria (Support Chat side; full list in the primary runbook §9)

1. Phase A: every synthetic cohort conversation `status='migrated'`; counts correspond; `body_ciphertext` ≠ source plaintext; source rows unmutated; ownerless / null-operator fixtures excluded with the correct reason; high-water mark stable across a second pass.
2. Phase B under continuous quiescence: promotes only while `is_quiescent()`; the Run 2 mid-run injection forces `REFUSED_NOT_QUIESCENT` with correct partial-progress semantics.
3. Quiescence-loss recovery (Run 2): no `legacy-bind` / `cutover begin` / `cutover activate` after the refusal; the injected update drains via legacy `process_update()` (no handoff-map row); backlog 0 + `idle`; re-enter/confirm; Phase B re-run promotes to `migrated`; only then proceed.
4. Migration-evidence gate: `status='migrated'` asserted via Support Chat CLI + `wp eval` before `cutover begin` (§5.3, B4).
5. Provenance handoff + idempotent retry: one Support Chat domain effect + one `legacy_handoff_map` row per handed-off row; matching retry converges silently; mismatched retry → `409 handoff_provenance_conflict`, nothing written.
6. No legacy mutation for an active SC-bound topic lifecycle event: `report_channel_unavailable` reached; the Universal Telegram legacy conversation row unmutated; handoff-map row `kind='channel_unavailable'` with no `target_message_uuid`.
7. Incident detection + safe blocking (Run 3): a `decrypt_failed` fixture blocks `replaying→idle` and `confirm-complete`; the incident row is never mutated; Run 3 ends blocked-as-designed. Terminal acknowledgement is never used to force a pass.
8. No plaintext: `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map` and every cutover/incident/audit table shows only ids / uuids / fixed-vocabulary / timestamps; `verify_step_11` passes.
9. Return to normal DEV operation (Run 1 / Run 2): after `confirm-complete`, quiescence `idle` + backlog 0; a fresh post-idle inbound for the cohort topic routes through the Support Chat adapter; a non-cohort legacy inbound still routes to legacy.
10. Teardown proof: `docker compose … down -v` completes, the run's DB volume is gone, `docker compose ps` empty; (Tier 2) throwaway bot deleted + `getWebhookInfo` empty.

Evidence artefacts, redaction rules, and the evidence-bundle layout are owned by the primary
runbook (§5.6, §9.1) and apply unchanged here.

## 8. Explicit out-of-scope / non-authorizations

This document authorizes nothing. It does not authorize execution of Tier 1 or Tier 2; any
production or DEV quiescence window, migration, binding preparation, cohort activation,
deferred-update replay, Telegram webhook, or operational command against `dev.biopentra.eu` or
production; creation of infrastructure, containers, Telegram bots, groups, topics, DNS,
certificates, or credentials; or any schema, plugin-version, configuration, test, tag, release,
or deployment change. Separate Product Owner approval (Approval A, then Approval B) is required
before the DEV rehearsal — even the Tier 1 prerequisite — may be executed.

## 9. Definition of done (documentation-freeze stage only)

- This companion plan and the [decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md) are committed on a documentation-only branch, reviewed, CI-green, and merged, alongside the Universal Telegram primary runbook.
- Registries, the plan index, the decisions index, and the milestone §0d page are updated **planning-only** — every touched line states that no rehearsal has run and Product Owner execution approval is outstanding.
- No acceptance record is added; that is a later Product Owner action.

---

## Amendment A — 2026-08-27 — Tier 1 halt (finding F1) and correction gate (non-design status note)

Post-freeze **status amendment** — no design section above is changed; the design revision is a
new file, `sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (not yet written).

- **Tier 1 was executed and HALTED** at the UT→SC deferred-update handoff phase by **finding
  F1** — closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` (this repo,
  merge `fcbfaa773ef63661b6d8ce42962f10bb174588f8`; Universal Telegram closure +
  characterization test, merge `98c602543bd67bc471e2a88468d175fb6e659b46`).
- **F1**: `ContractOperationDispatcher::resolve_conversation()` resolves `channel_case_ref` as
  this repository's `conversation_uuid` (correct), but Universal Telegram sent the UT
  `binding_uuid`; every real binding mints an independent one. Secondary defect: Universal
  Telegram's `CutoverReplayDispatcher::finish()` treated the `404 not_found` as an unbounded
  transient retry.
- **Correction frozen** (documentation-only, Proposed): **ADR-0011** (this repo; amends
  ADR-0010 §4) + **Universal Telegram ADR-0043**, and
  `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`. This repository's
  resolver, `dispatch_with_provenance()`, and `legacy_handoff_map` shape are **already correct**
  and unchanged; only comments (C1–C4) are corrected. No schema / `db_version` change.
- **Tier 1 acceptance gate**: Tier 1 **cannot be accepted** until the correction is implemented
  in both repositories and its real-binding handoff path passes green in the interop harness. A
  Tier 1 re-attempt needs a **separate Approval A addendum** under runbook **v2**.
- **Tier 2** retains its **B1**/**B2** blockers and **unexecuted** status, and is **additionally
  blocked on F1**.
- No Product Owner implementation acceptance for F1 is recorded by this amendment — see
  [decision record](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md) item 7 and
  the remediation plan §15 (Universal Telegram).
