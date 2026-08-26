# SC-M03 Work Package 5 — Existing Telegram-Topic Binding Preparation — Implementation Plan v1

## 1. Milestone charter and ADR references

- Milestone: [SC-M03 — Controlled Migration and Cutover](../milestones/sc-m03-controlled-migration-and-cutover.md) §0c.
- Authorizing ADR: [ADR-0009 — Legacy Binding Preparation Boundary and Non-Routing `prepared` Status](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md).
- Depends on and reuses, unedited: [ADR-0008](../adr/0008-legacy-export-boundary-and-migration-authority-model.md) (`QuiescenceStateProvider`, WP-CLI authority-model precedent); [SC-M03 work packages 3–4 plan](sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md) (`legacy_migration_map` schema, `LegacyFieldMap`, `LegacyMigrationValidator::KNOWN_ERROR_REASONS` pattern); [SC-M03 work packages 3–4 closure](../closure/sc-m03-work-packages-3-4-legacy-migration-engine-closure.md); [SC-M03 WP2 Phase B closure](../closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md) (real `UniversalTelegramQuiescenceStateProvider`, continuous re-check).
- Product Owner decisions: [sc-m03-wp5-legacy-binding-po-decisions.md](../decisions/sc-m03-wp5-legacy-binding-po-decisions.md).
- Universal Telegram counterpart: `LegacyBindingImportServiceV1`, the `prepared` binding status, and the lock-scoped quiescence assertion, implemented in the Universal Telegram repository per ADR-0009 §2–§5, pinned to ADR-0009's post-merge commit SHA.

## 2. Repository findings at plan-drafting time

- Confirmed baselines: Universal Telegram `origin/main` = `67e3545`; Support Chat `origin/main` = `b043964` (descendant of `a91cb7f`, WP2 Product-Owner-acceptance commit).
- `universal_support_chat_legacy_migration_map` (`src/Persistence/Migrator.php` step 9) already carries every field this work package needs to identify a candidate: `source_conversation_id`/`source_conversation_uuid`, `target_conversation_uuid`, `status`, `legacy_bot_id`, `legacy_destination_id`, `legacy_telegram_topic_id`, `legacy_topic_creation_state`, `legacy_topic_lifecycle_state`.
- `universal_telegram_support_chat_bindings` (Universal Telegram `src/Persistence/Migrator.php` step 31): `status ENUM('active','unavailable','closed') NOT NULL DEFAULT 'active'`; unique keys on `binding_uuid`, `support_conversation_uuid`, `ensure_idempotency_key`, `(bot_id, telegram_topic_id)`.
- `ChannelBindingRepository::create()` (Universal Telegram `src/SupportChatAdapter/ChannelBindingRepository.php:127-165`) hardcodes `'status' => ChannelBinding::STATUS_ACTIVE` at line 153.
- `ChannelBinding::is_active()` (`src/SupportChatAdapter/ChannelBinding.php:150-151`): `true` only when `status === STATUS_ACTIVE`.
- `InboundAdapterBridge::try_handle()` (`src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:59-73`): sole gate is `find_by_bot_topic()` returning a row with `is_active() === true`.
- `WebhookController::process_update()` (`src/Telegram/Inbound/WebhookController.php:206-231`): calls `try_handle()` unconditionally at line 216-217, before `maybe_route_to_conversation()` (legacy routing) at line 228 — no check of the legacy conversation's own liveness anywhere in this sequence.
- `SupportChatAdapter\Cli\BindingImportCommand` (`src/SupportChatAdapter/Cli/BindingImportCommand.php:75-138`): existing, already-shipped, production `--dry-run`/`--apply` command; pre-checks `find_by_conversation_uuid()`/`find_by_bot_topic()` before calling `create()`; does not re-validate the source legacy conversation's liveness at import time; writes `status = 'active'` unconditionally via `create()`'s hardcoded default.
- Universal Telegram's quiescence machinery (`docs/adr/0040` in that repository): `Migration\QuiescenceGate::decide_webhook_disposition()` and `attempt_replaying_to_idle()` (`src/Migration/QuiescenceGate.php:261-286`, `304-349`) both use `START TRANSACTION` / `SELECT state[, token] FROM {quiescence_state} WHERE id = %d FOR UPDATE` / commit-or-rollback — the exact lock discipline this plan's atomic quiescence assertion (§6) must reuse. `Core\Plugin::quiescence_status()` (`src/Core/Plugin.php:2073-2079`) is the existing **non-lock-scoped** read-only accessor Phase B and Support Chat's own pre-check already consume; it is explicitly not sufficient as this work package's authoritative guard (ADR-0009 §5).
- Highest existing Universal Telegram schema step: 33 (`step_33_create_quiescence_tables`). The additive `status` ENUM change lands as step 34.

## 3. Assumptions and open questions

- **Assumption, requires implementation-time verification against Universal Telegram's real enum:** which `topic_lifecycle_state` values count as terminal/unbindable. Provisionally `deleted`, `pending_delete`.
- **Assumption, requires implementation-time verification against real migrated data:** whether `legacy_topic_creation_state`/`legacy_topic_lifecycle_state` are populated for every already-`migrated` map row, or need a defensive backfill read for rows migrated before this plan existed.
- **Open question, resolved by the Product Owner decision record:** conflict-row retry mode (`--retry-conflicts`) — deferred, not built in this implementation cycle.

## 4. Architectural decisions (cite ADR-0009)

All boundary-shape, ownership, non-routing-status, idempotency/conflict, and atomic-quiescence decisions are frozen in ADR-0009 §1–§5 and are not re-litigated here. This plan covers only implementation detail ADR-0009 leaves to the plan layer: exact eligibility table, CLI shape, schema DDL, and test strategy.

## 5. Eligibility and exclusions

A migration-map row is a candidate only if **all** hold. Every terminal exclusion writes a fixed, non-content reason to `binding_error_reason`; every retryable condition never touches `binding_status`.

| # | Condition | Check | Class | Reason |
|---|---|---|---|---|
| 1 | Conversation migrated | `status = 'migrated'` | *(scan predicate, not a runtime outcome)* | — |
| 2 | Has a legacy topic | `legacy_telegram_topic_id IS NOT NULL` | Terminal | `binding_skip_no_topic` |
| 3 | Has bot/destination identity | `legacy_bot_id IS NOT NULL AND legacy_destination_id IS NOT NULL` | Terminal | `binding_skip_missing_bot_or_destination` |
| 4 | Topic actually created (Phase-A snapshot) | `legacy_topic_creation_state = 'created'` | Terminal | `binding_skip_topic_not_created` |
| 5 | Topic lifecycle still bindable (Phase-A snapshot) | `legacy_topic_lifecycle_state` not terminal (§3 assumption) | Terminal | `binding_skip_topic_lifecycle_terminal` |
| 6 | Target conversation exists | `target_conversation_uuid IS NOT NULL` | Terminal | `binding_skip_no_target_conversation` |
| 7a | Universal Telegram live re-check, conclusively invalid | UT-side, inside `import_batch()` | Terminal | `binding_skip_topic_state_changed_since_migration` |
| 7b | Universal Telegram live re-check, indeterminate | UT-side, inside `import_batch()` | Retryable | `binding_retry_ut_unavailable_or_indeterminate` |
| 8a | Matching identity, existing binding `prepared` | `find_by_bot_topic()`/`find_by_conversation_uuid()` | Terminal (idempotent success) | `binding_skip_already_bound` |
| 8b | Mismatched identity, any status | as above | Terminal (conflict) | `binding_conflict_existing_mismatched` |
| 8c | Matching identity, existing binding `active` | as above | Terminal (conflict, elevated) | `binding_conflict_existing_active` |
| 8d | Matching identity, existing binding `unavailable`/`closed` | as above | Terminal (conflict) | `binding_conflict_existing_status_unresolved` |
| 9 | Quiescence lock assertion fails | UT-side, per-candidate transaction | Retryable | `binding_retry_not_quiescent` |
| 10 | Run interrupted before commit | No outcome written | Retryable (implicit) | *(none)* |
| 11 | Other transient UT-side error | UT-side, caught, typed | Retryable | `binding_retry_transient_error` |

Full rationale for the 8a–8d split and the terminal/retryable classification: ADR-0009 §4.

## 6. Idempotency, conflict, and quiescence detail

- Unique identity of a binding attempt: `source_conversation_id` — one attempt terminates only on a terminal outcome.
- Universal Telegram's own DB-level uniqueness (`binding_uuid`, `support_conversation_uuid`, `ensure_idempotency_key`, `(bot_id, telegram_topic_id)`) is the actual backstop; `import_batch()` pre-checks via `find_by_conversation_uuid()`/`find_by_bot_topic()` before `create()`, and a `create()` returning `null` (a real constraint collision) is treated identically to a pre-check hit — never retried with force.
- One Universal Telegram transaction per candidate, and that transaction is where quiescence itself is authoritatively verified (ADR-0009 §5), not merely where the row is inserted: `SELECT state, token FROM {quiescence_state} WHERE id = 1 FOR UPDATE`, verify `state = 'quiescent'` and zero deferred backlog, re-validate topic state (§5 item 7), then `create()` with `status = 'prepared'` — commit together or roll back entirely.
- Two concurrent runs racing on the same candidate resolve via Universal Telegram's UNIQUE constraints; the loser maps to `binding_skip_already_bound` once the winner's row is visible. Support Chat's own map-row write (`UPDATE ... WHERE source_conversation_id = ? AND binding_status IS NULL`) is similarly idempotent against a concurrent writer.
- Rerun/resume: scan predicate `legacy_migration_map.status = 'migrated' AND binding_status IS NULL`. Retryable outcomes never write `binding_status`, so resume-after-interruption and retry-after-transient-failure are the same code path.
- Source drift: migration-state drift is excluded structurally (scan predicate only selects `migrated` rows); topic-state drift since Phase A's snapshot is caught by the live re-check (item 7), performed inside the same locked transaction as the quiescence assertion.

## 7. Operator workflow (Support Chat WP-CLI)

```
wp universal-support-chat legacy-bind status
wp universal-support-chat legacy-bind validate
wp universal-support-chat legacy-bind run [--dry-run] [--assume-binding-authority] [--limit=<n>]
```

- `status`: counts by terminal outcome (broken out by reason; conflicts broken out by all three sub-reasons with `binding_conflict_existing_active` given elevated visibility as its own line); count/age of retryable rows and their last `binding_last_attempt_reason`; live `is_quiescent()` value.
- `validate`: dry preview of eligibility against current map/state, no writes; structural cross-check that every `created` row's `binding_uuid` resolves to a real Universal Telegram binding with status `prepared` (never `active`).
- `run --dry-run` (default without `--assume-binding-authority`): exercises the full pipeline including the in-process Universal Telegram call and its live re-check and lock-scoped quiescence assertion, but commits nothing on either side — the Universal Telegram service itself takes a `$dry_run` parameter so no lock outlives the call and no write occurs.
- `run --assume-binding-authority`: required before any real write; operator-confirmation guard only (ADR-0009 §7), not a security control.
- Batches of ≤100 candidates per `import_batch()` call. No separate checkpoint table: the `status = 'migrated' AND binding_status IS NULL` scan predicate is simultaneously the checkpoint and the automatic-retry mechanism.
- Refusal conditions, each a distinct diagnostic: Universal Telegram plugin inactive or service class missing; incompatible schema; early `is_quiescent()` pre-check false at run start (non-authoritative, §6); `--assume-binding-authority` omitted for a non-dry-run; invoked outside WP-CLI. An individual candidate's UT-side locked-check failure is a per-candidate retryable outcome, never a whole-run refusal.
- `run` never touches Universal Telegram's quiescence state machine (`enter`/`confirm`/`exit`), Support Chat's own routing, or any live-traffic path, and never writes `status = 'active'` on any row.

## 8. Data model and schema impact

### 8.1 Support Chat (`universal_support_chat_db_version` next step)

Additive columns on `universal_support_chat_legacy_migration_map`:

| Column | Type | Written by |
|---|---|---|
| `binding_status` | `VARCHAR(16) NULL` | Terminal outcomes only (`created`\|`skipped`\|`conflict`) — `NULL` is the rescan predicate |
| `binding_error_reason` | `VARCHAR(191) NULL` | Terminal outcomes only — extends `LegacyMigrationValidator::KNOWN_ERROR_REASONS` |
| `binding_uuid` | `CHAR(36) NULL` | Terminal `created` outcome only |
| `binding_attempted_at` | `DATETIME NULL` | Terminal outcomes only |
| `binding_last_attempt_at` | `DATETIME NULL` | Every attempt |
| `binding_last_attempt_reason` | `VARCHAR(191) NULL` | Every retryable attempt |

No new table (rationale: ADR-0009 §6).

### 8.2 Universal Telegram (implemented in that repository, pinned by ADR-0009)

`universal_telegram_support_chat_bindings.status` becomes `ENUM('active','unavailable','closed','prepared')`, additive, at schema step 34. `ChannelBindingRepository::create()` gains either an optional initial-status parameter (default `'active'`, preserving `BindingImportCommand`'s and `EnsureChannelCaseService`'s existing callers byte-for-byte) or a dedicated `create_prepared()` method — an implementation-time API-shape choice made in that repository's own plan.

## 9. Security and privacy impact

No plaintext, message content, or per-message delivery correlation anywhere in this boundary (ADR-0009 § Security and privacy impact). Every field moved is already non-content. No new network-reachable endpoint, capability, or shared secret.

## 10. Test and CI impact

Mirrors WP3–4/WP2's three-tier shape (unit / integration / real dual-plugin interop) in both repositories.

**Support Chat**
- Unit: every terminal eligibility condition (§5) independently; the terminal/retryable vocabulary separation is itself structurally tested (retryable reasons must never appear in `binding_status`/`binding_error_reason`); batching ≤100.
- Integration (fake `LegacyBindingImportServiceV1`, mirroring `FakeLegacyExportClient`): `--dry-run` performs zero writes including zero `binding_last_attempt_*` writes; one test per terminal exclusion reason, asserting the row is not rescanned; one test per retryable reason, asserting the row **is** rescanned by the next run with no special flag, plus a "fails twice, then succeeds" test; one test per existing-binding-status branch (8a–8d) against a fake seeded with each of the four statuses; `--assume-binding-authority` omitted refuses before any UT call; refusal against a fake `is_quiescent() === false`.
- Real dual-plugin interop (`tests/integration/Interop/LegacyBindingImportIntegrationTest.php`, new): seeds a real UT legacy conversation with a real created topic, runs it through the real WP3–4 engine to `migrated`, runs the real command against UT's real `LegacyBindingImportServiceV1`, confirms a real row lands with `status = 'prepared'` and correct field values; **drives a real inbound webhook request through UT's real `WebhookController::handle_request()` against that exact topic and confirms `InboundAdapterBridge::try_handle()` does not claim it** — the direct proof for ADR-0009 §3; confirms rerun idempotency (`binding_skip_already_bound`, not rescanned); confirms real conflict outcomes for each of the 8b/8c/8d branches against real `ChannelBindingRepository` rows, never a second binding row; confirms the atomic quiescence lock via a held-open-lock-in-one-connection test and a state-transition-in-the-gap test; confirms no `universal_telegram_*` table literal (extends `NoTelegramCouplingTest`); confirms `--dry-run` against the real UT service writes nothing and holds no lock past the call.

**Universal Telegram** (implemented and tested in that repository per its own plan, pinned by ADR-0009): WP-CLI-only gate test; live re-check test; atomic lock-scoped assertion test (forced exception after lock/check but before `create()` leaves no row, releases lock); every created row asserted `status === 'prepared'`, never `'active'` (permanent regression test); a permanent non-interference test proving `try_handle()` never claims an update for a `prepared`-only topic, across all four quiescence states.

Both repositories' full existing quality gates (unit, integration at both supported version pairs, interop, PHPCS, PHPStan, doc-link check) must remain green, extended with the new suites above.

## 11. Work packages in execution order

1. **UT-1** (Universal Telegram repository): schema step 34 (`prepared` ENUM value); `ChannelBindingRepository` initial-status parameter/`create_prepared()`; `LegacyBindingImportServiceV1` with live re-check and lock-scoped quiescence assertion; permanent non-interference regression test; unit/integration coverage.
2. **SC-1** (this repository): schema migration (§8.1); `InProcessLegacyBindingImportClient` (defensive-call pattern mirroring `InProcessLegacyExportClient`); eligibility evaluator (§5); `legacy-bind` WP-CLI command (§7); unit/integration coverage against a fake client.
3. **Interop** (both repositories, run from this repository's interop harness): `LegacyBindingImportIntegrationTest.php` against UT-1's real, merged implementation.
4. **Closure**: this repository's closure record, citing this plan's freeze commit SHA and UT-1's pinned commit SHA.

UT-1 must merge to Universal Telegram `main` before SC-1's implementation begins consuming a real (non-fake) service; SC-1's fake-backed unit/integration work may proceed in parallel.

## 12. Risks and mitigations

- **Busy-install quiescence risk** (inherited from Phase B, not new): mitigated by the retryable-outcome design — a candidate blocked by transient quiescence loss is automatically retried next run, not a whole-batch restart.
- **New multi-connection test infrastructure required** for the lock-contention interop tests (§10): no existing WP3–4/WP2 test exercises a second caller contending for the quiescence-state row lock.
- **`BindingImportCommand`'s pre-existing gap** (Universal Telegram-owned, out of this plan's scope): `binding_conflict_existing_active` (§5 item 8c) is this plan's detection mechanism when it collides with a later run of this work package; it does not fix the source command.

## 13. Explicit out-of-scope list

- Production binding execution, cutover, route switch, or any change to which system Telegram traffic reaches.
- Forwarding/reply delivery through Support Chat for these topics.
- Buffered-update handoff from Universal Telegram to Support Chat.
- Soak, rollback, or retirement/deletion of Universal Telegram legacy chat data.
- `prepared → active` activation and its future cutover work package.
- Hardening or modification of `BindingImportCommand`.
- A `--retry-conflicts` mode (deferred — [PO decision record](../decisions/sc-m03-wp5-legacy-binding-po-decisions.md)).
- Any AI-related migration.

## 14. Definition of done

- UT-1 and SC-1 (§11) merged to their respective `main` branches, with a green interop run (§10) proving: correct binding creation; idempotent rerun; all terminal eligibility exclusions; all retryable outcomes auto-reselected; all four 8a–8d branches producing their distinct outcomes against real UT state; the atomic quiescence lock; and the direct `WebhookController`-driven non-interference proof.
- No binding ever created with `status = 'active'` by this work package's own code, verified by a permanent Universal Telegram regression test.
- Both repositories' full existing CI gates green.
- This repository's closure record filed citing this plan's freeze commit SHA.
