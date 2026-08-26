# Closure Record — SC-M03 Work Packages 3–4: Controlled Legacy Conversation Migration Engine

## Final status

**PASS.** Implements `docs/plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md` in full: work package 3 (batch migrator + checkpoints, Phase A preparatory backfill) and work package 4 (validators, Phase B final reconciliation). This does **not** close SC-M03 itself, and does **not** claim any of the following, per ADR-0008 §6 and the plan's own closure constraint (§11):

- **No real quiescence signal was ever consumed.** Phase B is proven only against `Tests\Integration\Migration\Support\FakeQuiescenceStateProvider`, the one test seam ADR-0008 §6 authorizes. The production-registered `DefaultDenyQuiescenceStateProvider` was never bypassed anywhere in this work.
- **No conversation has been validated as cutover-ready.** Every `migrated` map row this closure's own test runs produced was validated against fixture or fake-provider-gated data, never a real production quiescence window.
- **No route switch, soak, or rollback was proven or performed.** Those remain work packages 6–7, entirely unimplemented and unauthorized here.
- **No production migration was executed.** Every write in every test in this closure ran against disposable WordPress test databases, seeded by tests, never a real site's data.

## Corrections made after initial review

This closure record originally described a 42-column registry and an unbounded Phase A batch-size request. Both were corrected before Product Owner review, in this same branch/PR, without broadening scope:

1. **`LegacyFieldMap` disposition accuracy.** The original registry marked several fields `exclude` even though this engine actually retains them — verbatim — in `legacy_migration_map`/`legacy_migration_message_map` for correspondence, audit, or work package 5's future binding creation. `exclude` was corrected to mean, precisely, "never read into this engine at all, and never written anywhere, including this engine's own metadata tables" — not merely "not copied into the target row." A new disposition, `preserve_for_map`, was added for the first, truthful case. Corrected: conversations' `id`, `conversation_uuid`, `bot_id`, `destination_id`, `topic_creation_state`, `telegram_topic_id`, `topic_lifecycle_state` (all `preserve_for_map`); `conversation_messages`' `id`, `message_uuid` and `conversation_notes`' `id` (all `preserve_for_map`); `conversation_messages`' `conversation_id` and `conversation_notes`' `conversation_id` (`remap` — Universal Telegram's own ADR-0008 export shape never emits either field at the row level at all, so nothing is copied or separately stored, but the parent/child relationship each expresses is reconstructed via the already-established conversation-level map, which is exactly what `remap` means elsewhere in this registry). The real physical column count was also corrected throughout this record, the registry's own docblock, and the test suite: **43 total** (27 `conversations` + 11 `conversation_messages` + 5 `conversation_notes`), not 42 — the registry itself already held all 43 entries; only the stated count was wrong.
2. **Phase A effective batch-size handling.** `PhaseABackfillService::run()` previously compared Universal Telegram's response size against the caller's *raw requested* `$batch_size` to decide whether more rows remained, but Universal Telegram's own `LegacyExportServiceV1` never returns more than 100 rows per call regardless of what is requested (ADR-0008 §5). Requesting, say, 500 meant a full 100-row response was always treated as "short" against 500 and Phase A stopped after the very first batch, silently leaving further source rows unprocessed. Fixed by computing `$effective_batch_size = min( max( $batch_size, 1 ), 100 )` once per `run()` call and using it consistently for the export call itself, the empty/short-batch termination check, and the run/batch-log records — for both a real run and a dry run.

Both corrections are covered by new regression tests (§"Test and CI evidence" below) and were verified against the real Universal Telegram schema/service in the dual-plugin interop suite, not only offline.

## What this closes

- Work package 3: the `legacy_migration_runs`/`legacy_migration_map`/`legacy_migration_message_map`/`legacy_migration_batch_log` schema, `LegacyFieldMap::REGISTRY`, the Phase A backfill engine (resumable, per-conversation-transactional, dry-run, checkpointed), the frozen `QuiescenceStateProvider` interface plus its default-deny stub and test fake, and the `wp universal-support-chat legacy-migrate` WP-CLI command shell gated by `--assume-migration-authority`.
- Work package 4: the Phase B reconciliation-and-diff engine, count/correspondence/content-integrity validators, the `legacy-migrate status`/`validate` subcommands, and the full test suite plan §7 requires — including a real dual-plugin interoperability harness proving this engine against Universal Telegram's actual, merged `LegacyExportServiceV1` (ADR-0008), not only a fake.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `7546d43be66f8e3b2f179f03a1c81c9aadef59db` (merge of PR #8, ADR-0008 + PO decision record + this plan)
- Branch: `feature/sc-m03-legacy-migration-engine`
- Frozen plan: `docs/plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md` (unedited by this work)
- Frozen ADR: `docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md` (unedited)
- Frozen PO decisions: `docs/decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md` (unedited)
- Universal Telegram pin (unchanged, this work's own precondition): `LegacyExportServiceV1` implemented, merged, PR #37 — `magpern/universal-telegram` `main` commit `5d16119244b6574b22906e0833a9067ff191ab8c`
- Plugin version: `0.4.0` → `0.5.0` (minor bump: genuine new capability class, per this repository's own versioning convention documented in the WP0/WP1 closure records)
- Schema version (`universal_support_chat_db_version`): `8` → `9` (step 9: four new `legacy_migration_*` tables)

## Schema changes

| Step | Table | Purpose |
|---|---|---|
| 9 | `universal_support_chat_legacy_migration_runs` | Run-level operational evidence: phase, status, dry-run flag, batch size, checkpoint cursor, timestamps, invoking operator. Never written for a dry run. |
| 9 | `universal_support_chat_legacy_migration_map` | The authoritative conversation-level source→target correspondence: legacy id/UUID, target id/UUID, status, work-package-5 topic-binding fields, message/note counts, validation state, a stable typed `error_reason`. |
| 9 | `universal_support_chat_legacy_migration_message_map` | The authoritative message/note-level source→target correspondence — what makes `assignee_last_seen_message_id` remapping mechanical and Phase B's drift detection possible. Carries no Telegram-correlation field of any kind. |
| 9 | `universal_support_chat_legacy_migration_batch_log` | Per-batch aggregate counts only. |

Every non-ciphertext column across all four tables holds only IDs, timestamps, booleans, counts, or a fixed-vocabulary reason string — verified by `Migrator::verify_step_9()`'s own forbidden-column check and by `LegacyMigrationValidator::validate_error_reason_is_known()`'s fixed vocabulary.

## New source (`src/Migration/`)

- `LegacyFieldMap.php` — the complete, CI-enforced disposition registry for every real Universal Telegram `conversations`/`conversation_messages`/`conversation_notes` column (27 + 11 + 5 = 43 columns), matching plan §4.1's intent, with dispositions corrected for truthfulness (see "Corrections made after initial review" above): `owner_active_slot` (a generated-index column) was present in the plan's own field-mapping table but missing from the first draft of this registry — caught by `Interop\SchemaInventoryTest` itself against Universal Telegram's real schema before this closure was written, not discovered later.
- `IdempotencyKeyDeriver.php` — deterministic, NULL-safe, UUID-shaped (36-character) derivation for `start_idempotency_key`/`idempotency_key`. **Correction from the plan's literal text**: the plan's formula (`hash('sha256', ...)`, a 64-character hex digest) does not fit either column's `CHAR(36)` width. This class derives the first 128 bits of that same SHA-256 digest, formatted as a UUID (`8-4-4-4-12` hex groups) — same deterministic, NULL-safe intent, corrected to actually fit the real schema.
- `QuiescenceStateProvider.php` (interface) / `DefaultDenyQuiescenceStateProvider.php` — the frozen ADR-0008 §6 contract and its only production implementation.
- `LegacyExportClient.php` (interface) / `InProcessLegacyExportClient.php` — the sole boundary through which this engine ever reaches Universal Telegram; the only two files in this repository referencing Universal Telegram's namespace at all (an explicit, scoped exception carved into the pre-existing `NoTelegramCouplingTest`, §"Corrections" below).
- `LegacyMigrationMapEntry.php`, `LegacyMigrationMapRepository.php`, `LegacyMigrationMessageMapRepository.php`, `LegacyMigrationRunRepository.php`, `LegacyMigrationBatchLogRepository.php` — persistence for the four new tables.
- `PhaseABackfillService.php` — the preparatory backfill engine: per-conversation transaction, checkpoint advance only after commit, typed terminal outcomes (skipped/failed) committed durably, genuinely unexpected failures roll back with no surviving map row (safe retry on the next run).
- `PhaseBReconciliationService.php` — quiescence- and preflight-gated reconciliation: imports drift (messages/notes added since Phase A), transient content-integrity comparison (never persisted), promotes to `migrated` only when every check passes.
- `LegacyMigrationValidator.php` — read-only count/correspondence/registry-self-consistency/known-error-reason checks, backing the `validate` CLI subcommand.
- `Cli/LegacyMigrateCommand.php` — `wp universal-support-chat legacy-migrate {run,status,validate}`. `run` requires `--assume-migration-authority` before any non-dry-run write (an operator-confirmation guard, per ADR-0008 §4 — never a security control).

## Amended source

- `src/Persistence/Migrator.php` — step 9 (above); `target_version()` `8` → `9`.
- `src/Conversations/ConversationRepository.php` — new `import_legacy()` (accepts explicit historical `owner_user_id`/`status`/`assigned_operator_id`/`start_idempotency_key`/timestamps — `create()` itself accepts none of these) and `set_assignee_last_seen_message_id()`. No existing method changed.
- `src/Conversations/MessageRepository.php` — new `import_legacy()`: encrypts through this plugin's own vault only when plaintext exists; a legacy body already retention-nulled at the source is inserted as `NULL` ciphertext directly, preserving that state rather than encrypting an empty string. `delivery_state` is always the constant `'stored'`.
- `src/Conversations/NoteRepository.php` — new `import_legacy()`. Requires an already-verified non-null `operator_user_id` — this table's column is `NOT NULL`, unlike Universal Telegram's own (nullable, via `anonymize_author()`), so the caller (`PhaseABackfillService`) must detect and fail closed on this mismatch before ever calling it (see Mapping/disposition evidence, §7 below).
- `src/Core/Plugin.php` — wires the migration engine and registers `LegacyMigrateCommand` (guarded by the same `defined('WP_CLI') && WP_CLI` check the command's own `register()` re-checks).
- `universal-support-chat.php` — version bump.
- `tests/unit/Core/NoTelegramCouplingTest.php` — scoped an explicit, narrow, ADR-0008-authorized exception (`LegacyExportClient.php`/`InProcessLegacyExportClient.php` only) into this repository's pre-existing "no Universal Telegram coupling" structural test, discovered when this closure's own unit run caught the violation before it was ever committed.
- `tests/integration/Persistence/MigratorTest.php`, `tests/integration/Lifecycle/ActivationTest.php` — hardcoded `db_version === 8` assertions bumped to `9`, matching the existing precedent from the WP1 closure's own `7` → `8` bump.
- `phpcs.xml.dist` — added exclude-patterns for the new Interop bootstrap file and the Interop SQL-literal source scan, mirroring this file's existing pattern for `tests/integration/bootstrap.php`.
- `phpunit-integration.xml.dist` — excludes `tests/integration/Interop` (its own dedicated `phpunit-interop.xml.dist` runs it), mirroring Universal Telegram's own identical split.
- `bin/docker/_lib.sh` — added `sc_compose_run_interop()`, mirroring Universal Telegram's own `ut_compose_run_interop()`.

## New dual-plugin interoperability harness (`tests/integration/Interop/`, mirroring Universal Telegram's own)

- `docker/docker-compose.interop.yml` — mounts the sibling `universal-telegram` checkout.
- `tests/bin/install-universal-telegram.sh` — links the real Universal Telegram source into the test WordPress install and runs its own `composer install`.
- `bootstrap.php` — loads both plugins' real code (Universal Telegram first, this repository last) into one disposable WordPress test install.
- `phpunit-interop.xml.dist`, `bin/docker/test-integration-interop.sh`.
- `SchemaInventoryTest.php` — introspects Universal Telegram's real, live schema and fails on any column missing a `LegacyFieldMap` disposition. **This test caught a real gap** (`owner_active_slot`) before this closure was written — direct evidence the CI-enforced drift guard the plan required actually works, not just that it exists.
- `LegacyExportClientIntegrationTest.php` — seeds real Universal Telegram conversation/message/note rows through Universal Telegram's own repositories, backfills them through this repository's real `PhaseABackfillService` and real `InProcessLegacyExportClient` (never a fake), and confirms: the plaintext round-trips correctly; the stored `body_ciphertext` never contains the plaintext (re-encrypted under Support Chat's own vault, not merely copied); an anonymous (ownerless) real Universal Telegram conversation is skipped, not migrated; no file in this repository's `src/` builds a query against a real Universal Telegram table name as a SQL literal.

## Mapping/disposition evidence

- `LegacyFieldMap::registry()` covers all 43 real Universal Telegram columns (`tests/unit/Migration/LegacyFieldMapTest.php`, including an exact-count assertion per table); `Interop\SchemaInventoryTest` independently confirms both the coverage and the exact count against Universal Telegram's real, live schema, not a static snapshot.
- **`preserve_for_map` vs `exclude`, corrected**: `id`/`conversation_uuid`/`bot_id`/`destination_id`/`topic_creation_state`/`telegram_topic_id`/`topic_lifecycle_state` on `conversations`, and `id`/`message_uuid` on `conversation_messages`, and `id` on `conversation_notes`, are all `preserve_for_map` — retained verbatim in `legacy_migration_map`/`legacy_migration_message_map`, never `exclude`. `conversation_id` on both `conversation_messages` and `conversation_notes` is `remap` — Universal Telegram's own export shape never emits either at the row level, so nothing is copied, but the relationship each expresses is reconstructed via the conversation-level map. Verified both offline (`LegacyFieldMapTest::test_conversation_fields_retained_in_the_migration_map_are_preserve_for_map_not_excluded`, `::test_message_and_note_row_identity_fields_are_preserve_for_map_or_remap_not_excluded`) and live against Universal Telegram's real schema (`Interop\SchemaInventoryTest::test_fields_retained_in_the_migration_map_are_not_marked_excluded`).
- **`owner_user_id` (PO decision item 1)**: code-verified match, not assumed. Universal Telegram's own docblock (`ConversationRepository.php`) states it is "the authenticated WordPress user this conversation belongs to"; Support Chat's own `ConversationRepository`/`ConversationsController` use it identically (a WP user id compared against `get_current_user_id()` for ownership). Both plugins run in the same WordPress install, so a `wp_users.ID` value carries the same meaning in both. Copy is authorized for the non-null case. For the null case — Universal Telegram's column is nullable (anonymous conversations, or an owner whose account was later deleted); Support Chat's is `NOT NULL` — **PO decision item 3 applies directly**: since representing an ownerless conversation would require a schema change beyond what this work package scopes, such rows are **excluded with the durable, queryable audit reason `ownerless_conversation_unsupported`**, never migrated with an invented placeholder owner. Proven against a real anonymous Universal Telegram conversation in `Interop\LegacyExportClientIntegrationTest::test_ownerless_ut_conversation_is_skipped_not_migrated`.
- **`assigned_operator_id` (PO decision item 2)**: copied unconditionally — schema-safe (nullable on both sides), no new UI/endpoint/workflow added anywhere in this closure.
- **A field-mapping gap this closure discovered, not inherited from the plan**: internal notes. Universal Telegram's `ConversationNoteRepository::anonymize_author()` can null `operator_user_id` on operator-account deletion — a real, reachable state. Support Chat's own `conversation_notes.operator_user_id` is `NOT NULL`. This exact mismatch shape is PO decision item 1's own pattern (verify semantics; if they diverge, fail closed rather than invent a conversion) — applied here to a field the PO decision record did not separately name. Resolution: `PhaseABackfillService` detects a null `operator_user_id` on any note **before any write**, and fails that entire conversation atomically with the durable reason `note_operator_user_id_null_unsupported` — never a placeholder author, never a partial conversation. Covered by `PhaseABackfillServiceTest::test_note_with_null_operator_user_id_fails_the_whole_conversation_atomically`.
- **`start_idempotency_key`/`idempotency_key` (NULL-safe derivation)**: see "Correction from the plan's literal text" above (`IdempotencyKeyDeriver.php`). No collision across 500 distinct NULL-source-key fixtures (`IdempotencyKeyDeriverTest::test_many_null_key_fixtures_never_collide`).
- **`delivery_state`**: always Support Chat's own `'stored'` constant; Universal Telegram's export shape does not even carry this field (ADR-0008 §5 already excludes it at the source) — confirmed by `LegacyFieldMap`'s `transform_to_constant` disposition and `PhaseABackfillServiceTest::test_delivery_state_is_always_stored_regardless_of_source_value`.
- **`telegram_message_id`/`outbound_message_uuid`**: never read (not in the export shape at all); no work-package-5 dependency on per-message Telegram correlation is introduced anywhere in this closure.
- **`consent_state`**: never read (excluded at the Universal Telegram export source per ADR-0008 §5); not migrated.
- AI drafts/config, operator identities, operator availability: never referenced anywhere in `src/Migration/`.

## Phase A/Phase B behaviour and fail-closed evidence

- **Per-conversation atomicity**: one real database transaction per conversation (`START TRANSACTION`/`COMMIT`/`ROLLBACK`), never per-batch. A forced failure partway through a conversation rolls back the entire conversation — including its own `pending` map row — leaving no partial state and no false "already attempted" record, so the next Phase A run retries it naturally (`PhaseABackfillServiceTest::test_per_conversation_transaction_rolls_back_on_forced_failure_leaving_no_map_row`).
- **Repeatable Phase A / resumable high-water mark**: the map table's own `MAX(source_conversation_id)` is the cursor; re-running after a prior "completion" picks up only conversations created since (`PhaseABackfillServiceTest::test_cursor_and_high_water_mark_advance_and_a_second_run_only_picks_up_new_rows`).
- **UT typed export errors → SC migration failures, no partial target**: a `{"id":..., "error": "decrypt_failed"}` entry produces a durable `failed` map row and creates zero target rows (`PhaseABackfillServiceTest::test_ut_typed_export_error_produces_a_failed_map_row_and_no_target_conversation`).
- **`assignee_last_seen_message_id` remapping**: resolves through the message map to the correct target id, or `NULL` when the referenced source message was not migrated (both cases tested).
- **Retention-nulled message bodies**: imported as `NULL` target ciphertext, not a failure (`PhaseABackfillServiceTest::test_a_retention_nulled_message_body_is_imported_as_a_null_body_not_a_failure`).
- **Dry-run**: zero writes to any table, in both Phase A and Phase B, including the run/map/batch-log tables themselves (`PhaseABackfillServiceTest::test_dry_run_writes_nothing_to_any_table`, `PhaseBReconciliationServiceTest::test_dry_run_reconciliation_does_not_promote_or_import_anything`).
- **Phase B default-deny refusal**: `PhaseBReconciliationServiceTest::test_phase_b_refuses_to_run_against_the_default_deny_provider` — refuses with `not_quiescent`, no map row touched.
- **Phase B fake-provider-only success path**: `test_phase_b_proceeds_only_against_the_fake_provider_returning_true` — the only way this engine's own tests ever exercise a successful Phase B run.
- **Phase B preflight blocks on new source rows**: `test_phase_b_blocks_when_source_rows_exist_beyond_the_recorded_high_water_mark` — refuses with `new_source_rows_since_last_backfill`, nothing promoted.
- **Phase B drift import**: a message added to a source conversation after Phase A backfilled it is detected and imported during reconciliation, with a transient (never-persisted) content-integrity comparison for every already-known message (`PhaseBReconciliationServiceTest::test_phase_b_imports_a_message_added_to_the_source_since_phase_a`).
- **`--assume-migration-authority` gating**: a real (non-dry-run) `run` — either phase — is refused with no write when the flag is absent; present, it proceeds. `status`/`validate` never require it (`LegacyMigrateCommandTest`, 6 tests).
- **Effective batch-size contract**: requesting a batch size above Universal Telegram's own 100-row per-call ceiling no longer stops Phase A after the first response. 150 seeded conversations, requested with `batch_size=500`, are all processed within a single `run()` call across two internal batches (100 + 50); every actual export call is confirmed to use `limit=100`, never the raw requested value — `PhaseABackfillServiceTest::test_a_request_above_100_does_not_stop_after_the_first_100_row_ut_response`. The identical clamp applies to a dry run (`::test_effective_batch_size_is_clamped_for_dry_run_too`) and to a request below the minimum, which is clamped up rather than rejected (`::test_a_batch_size_below_the_minimum_is_clamped_up_not_rejected`).

## Test and CI evidence

| Check | Command | Result |
|---|---|---|
| Unit | `bin/docker/test-unit.sh` | 85 tests, 753 assertions — OK (59 pre-existing + 26 new) |
| Integration (WP 6.9 / PHP 8.1) | `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` | 96 tests, 385 assertions — OK (70 pre-existing + 26 new) |
| Integration (WP 7.1 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` | 96 tests, 385 assertions — OK |
| **Interop (dual-plugin, WP 6.9 / PHP 8.1)** | `bin/docker/test-integration-interop.sh --wp-version=6.9 --php-version=8.1` | 8 tests, 38 assertions — OK, against Universal Telegram's real, merged `main` (`5d16119`) |
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 0 warnings |
| PHPStan (level 5) | `bin/docker/phpstan.sh` | 0 errors |
| Doc links | `bin/docker/composer.sh run-script check-doc-links` | Clean |

New test files: `tests/unit/Migration/{IdempotencyKeyDeriverTest,DefaultDenyQuiescenceStateProviderTest,LegacyFieldMapTest,LegacyMigrationValidatorTest}.php`; `tests/integration/Migration/{PhaseABackfillServiceTest,PhaseBReconciliationServiceTest}.php` (15 + 5 tests, including the three effective-batch-size regression tests), `tests/integration/Migration/Cli/LegacyMigrateCommandTest.php` (6 tests, with a minimal test-only `\WP_CLI` stub since this environment never loads the real WP-CLI binary); `tests/integration/Migration/Support/{FakeLegacyExportClient,FakeQuiescenceStateProvider}.php` (test doubles, not production code — `FakeLegacyExportClient` now also enforces Universal Telegram's own 100-row per-call ceiling, mirroring the real service exactly); `tests/integration/Interop/{SchemaInventoryTest,LegacyExportClientIntegrationTest}.php` (8 tests, real dual-plugin, including the two new live disposition/count assertions).

**A local-development-loop-only note, not a CI concern**: the `db` service in `docker/docker-compose.yml` is not `--rm` and persists across separate `docker compose run` invocations sharing the same compose project. Because `PhaseABackfillService` commits real transactions that `WP_UnitTestCase`'s own rollback-per-test fixture cannot undo, re-running the interop suite (or the plain integration suite) against a still-warm `db` container from a prior manual run can leak committed rows across runs. `docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v` between manual iterations avoids this; CI provisions a fresh service container per job and is unaffected. All test files' own `set_up()` truncates the tables Phase A/B can commit, which fully isolates test *methods* within one run.

## Explicit confirmation of every excluded scope item

- **No production migration execution.** Every write occurred against disposable, per-test-run WordPress databases (`wordpress_test`), seeded only by this closure's own tests.
- **No real quiescence implementation, writer drains, route switch, cutover, soak, rollback, or deletion of Universal Telegram legacy data.** `DefaultDenyQuiescenceStateProvider` is the only production-registered implementation anywhere in `src/`; no filter, flag, or configuration constant bypasses it.
- **No binding creation for existing Telegram topics (work package 5).** The map schema preserves `legacy_bot_id`/`legacy_destination_id`/`legacy_telegram_topic_id`/`legacy_topic_creation_state`/`legacy_topic_lifecycle_state` for that future work package to consume; nothing in this closure creates a binding.
- **No Universal Telegram repository modification.** `git diff` against the Universal Telegram sibling checkout throughout this work: none — the interop harness only mounts and reads it.
- **No Support Chat launcher/greeting polish, availability/hours, tickets, AI, or assignment UI.** `assigned_operator_id` is migrated as inert historical data only (PO decision item 2); no UI, endpoint, or workflow reads or acts on it beyond what SC-M02 already shipped.
- **No removal of Universal Telegram Conversations, AI, widget, or settings UI.** Untouched; this repository cannot modify Universal Telegram's UI at all.
- **No release, tag, deployment, or production environment mutation.** This branch is not merged by this task.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.

## Next task

**SC-M03 work package 2 (quiescence switches/drains)** — a separate, later, unstarted unit of work that must supply a real `QuiescenceStateProvider` implementation satisfying this exact frozen interface (`is_quiescent(): bool`, `since(): ?DateTimeImmutable`), per ADR-0008 §6's binding requirement. **Alternatively**, a narrowly scoped work package 5 (binding creator for existing Telegram topics) plan may be drafted — but only after work package 2 is itself frozen and completed, since work package 5's own design (per plan v2 §8) depends on quiescence having already been proven real, not merely architecturally possible. Nothing in this closure record authorizes starting either directly; both require their own plan, ADR (if architecture-touching), and Product Owner approval, per `docs/governance.md`.
