# Closure Record — SC-M03 Work Package 5: Legacy Binding Preparation

## Status

**PASS.** Implements the [work package 5 implementation plan v1](../plans/sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md), authorized by [ADR-0009](../adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md), against Universal Telegram's real, complete `LegacyBindingImportServiceV1` (Universal Telegram ADR-0041, implemented on branch `feature/sc-m03-wp5-legacy-binding-import-service`, PR #41 — not yet merged to Universal Telegram `main`; Product Owner acceptance pending on that side, per that repository's own closure record).

This closure does **not** claim: production binding execution, cutover, route switching, `prepared → active` activation, soak, rollback, or that Universal Telegram's own implementation PR has merged. Every claim below is scoped to the preparation *engine* — exactly as ADR-0009 authorizes.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `590b53b` (merge of PR #13, the ADR-0009 documentation freeze)
- Branch: `feature/sc-m03-wp5-legacy-binding-import-service`
- Frozen plan: `docs/plans/sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md` (unedited by this implementation)
- Universal Telegram counterpart pinned to: branch `feature/sc-m03-wp5-legacy-binding-import-service` (PR #41), implementing ADR-0041 §2 in full — **not yet merged to Universal Telegram `main`**; this closure's real dual-plugin interop evidence (§ below) is proven against that unmerged-but-complete branch, mounted via this repository's own interop harness, exactly as the harness mounts whatever is checked out at `/opt/biopentra/dev/universal-telegram`.
- Plugin version: unchanged (this repository has no plugin-version header comparable to Universal Telegram's; `universal_support_chat_db_version` advances `9` → `10`).

## Accurate scope statement

**This work package creates only non-routing `prepared` bindings, via a new orchestrator (`LegacyBindingImportService`) that identifies candidates entirely from this repository's own already-finalized `legacy_migration_map` rows, applies the structural eligibility checks ADR-0009 §2 items 2-6 assign to this repository, and calls Universal Telegram's own `LegacyBindingImportServiceV1::import_batch()` in-process for everything else. It never writes to Universal Telegram's binding table directly, never invents an outcome from ambiguous evidence, and never writes `binding_status` for a retryable outcome.**

## Scope closed

- **Schema step 10** (`src/Persistence/Migrator.php`) — six additive columns on `universal_support_chat_legacy_migration_map`: `binding_status`, `binding_error_reason`, `binding_uuid`, `binding_attempted_at`, `binding_last_attempt_at`, `binding_last_attempt_reason`, plus a `KEY binding_status`. Idempotent (`SHOW COLUMNS`/`SHOW INDEX` checks, this connection, not `INFORMATION_SCHEMA` — see Errors and fixes below). `Migrator::target_version()` bumped `9` → `10`.
- `src/Migration/LegacyMigrationMapEntry.php` (amended) — six new accessors, additive trailing constructor parameters (default `null`), `from_row()` extended.
- `src/Migration/LegacyMigrationMapRepository.php` (amended) — `find_bindable()` (the scan predicate `status = 'migrated' AND binding_status IS NULL`, simultaneously the checkpoint and the automatic-retry mechanism), `mark_binding_terminal()`, `mark_binding_retry()`, `counts_by_binding_status()`.
- `src/Migration/LegacyBindingOutcome.php` (new) — the full outcome vocabulary ADR-0009 §4 fixes, `terminal()`/`retryable()`/`binding_status_for()`/`is_terminal()` helpers.
- `src/Migration/LegacyBindingImportClient.php` (new interface) + `src/Migration/InProcessLegacyBindingImportClient.php` (new) — symmetric to `LegacyExportClient`/`InProcessLegacyExportClient`, calling Universal Telegram's `Core\Plugin::instance()->legacy_binding_import_service()->import_batch()` in-process, defensively (`class_exists()`/`method_exists()`/null-check/`try`-`catch`).
- `src/Migration/LegacyBindingImportUnavailableException.php` (new) — a whole-batch refusal, distinct from a per-candidate retryable outcome.
- `src/Migration/LegacyBindingImportService.php` (new) — the orchestrator: `run( bool $dry_run, int $limit ): array` (candidate identification, structural eligibility, the early non-authoritative quiescence pre-check, calling the client, writing every outcome back) and `validate( int $limit ): array` (a read-only structural preview, never calling Universal Telegram, never writing).
- `src/Migration/Cli/LegacyBindCommand.php` (new) — `wp universal-support-chat legacy-bind {run,status,validate}`, `--dry-run`, `--assume-binding-authority` (named distinctly from `--assume-migration-authority`), `--limit=<n>`.
- `src/Core/Plugin.php` (amended) — composition-root wiring, reusing the identical `$quiescence` provider instance already wired for Phase B as this work package's own early pre-check.
- `tests/unit/Core/NoTelegramCouplingTest.php` (amended) — `LegacyBindingImportClient.php`/`InProcessLegacyBindingImportClient.php` added to the narrow authorized-exception list (ADR-0009 §2); `LegacyBindingImportService.php`/`LegacyBindingOutcome.php` deliberately kept **out** of that list by rewording their docblocks to avoid the scan pattern, since neither file actually references Universal Telegram's namespace or tables — a narrower, more accurate exception list than adding every file loosely.

## Test evidence

- `tests/integration/Migration/LegacyBindingImportServiceTest.php` (new, 13 tests) — against real `LegacyMigrationMapRepository`/DB, a fake Universal Telegram write boundary (this work package's own ADR-0009-authorized test seam): a created row is terminal and never re-scanned; every one of the five structural exclusions (§2 items 2-6) is terminal and never calls the client; a conflict outcome is terminal; a retryable outcome never writes `binding_status` and is automatically re-selected by the very next ordinary run (proven by queuing a transient failure then a real success); the early quiescence pre-check refuses before any client call; a whole-batch `LegacyBindingImportUnavailableException` is retryable for every candidate; `--dry-run` writes nothing at all, on either side; `validate()` never calls the client and never writes.
- `tests/integration/Migration/Cli/LegacyBindCommandTest.php` (new, 6 tests) — `--assume-binding-authority` gating mirrors `LegacyMigrateCommandTest`'s existing shape exactly; `--dry-run` never requires it; a non-quiescent refusal surfaces before any authority check; `status`/`validate` never require it and never write.
- `tests/integration/Interop/LegacyBindingImportIntegrationTest.php` (new, **5 tests, real dual-plugin, not a fake**) — against Universal Telegram's real, complete `LegacyBindingImportServiceV1` (unmerged branch, see Baseline): a real binding is created with `status = 'prepared'`, resolved back through a real `ChannelBindingRepository::find_by_uuid()` call, and asserted `!== 'active'`; a real rerun is idempotent (exactly one real binding row, confirmed by a real `COUNT(*)`); a real pre-existing `active` binding produces `binding_conflict_existing_active`, never idempotent success, and no second row is written; a real non-quiescent Universal Telegram (left at its default `idle` state) refuses and writes zero real binding rows; a real `--dry-run` writes zero real binding rows on either side. Real quiescence transitions are driven by directly constructing Universal Telegram's own `\UniversalTelegram\Migration\QuiescenceGate` and calling `enter()`/`confirm()`, the identical pattern `QuiescenceProviderIntegrationTest` already established.
- `tests/unit/Core/NoTelegramCouplingTest.php` — both existing assertions (broad Telegram-coupling scan; authorized-exceptions never touch a `universal_telegram_*` `$wpdb` table) pass unchanged against the two new authorized files.

## Errors and fixes found during this implementation

- **A genuine `INFORMATION_SCHEMA` staleness bug**, found and fixed before this closure: the first draft of schema step 10 checked column existence via `INFORMATION_SCHEMA.COLUMNS`, mirroring step 8's own existing pattern. Under `MigratorTest::test_maybe_migrate_creates_audit_table_and_is_idempotent` (which resets `db_version` to `0` and re-runs every step from scratch mid-suite, against a `legacy_migration_map` table already created by an earlier bootstrap-time migration), `INFORMATION_SCHEMA` reported every new column as already existing (stale cached view) while the live table genuinely did not have them yet, so the loop's own `SELECT ... INFORMATION_SCHEMA` gate skipped every `ADD COLUMN` statement, and the subsequent `ALTER TABLE ... ADD KEY binding_status` then failed with a real MySQL error ("Key column 'binding_status' doesn't exist in table"). Fixed by switching to `SHOW COLUMNS FROM {table} LIKE '{column}'` (this session's own live view, not the cached data dictionary) — the identical reasoning step 29's own pre-existing column checks in this file already document for exactly this class of hazard. Two pre-existing test files' hardcoded `db_version` expectations (`MigratorTest.php`, `ActivationTest.php`) were updated `9` → `10` accordingly — a legitimate consequence of the version bump, not a defect.
- **WP-CLI-context-rejection cannot be observed in this repository's integration suite**: this repository's integration bootstrap always runs with `WP_CLI` already `true` (confirmed identical to the pre-existing constraint `LegacyExportServiceV1Test.php`'s integration counterpart already documents). `InProcessLegacyBindingImportClient`'s own WP-CLI gate is exercised only implicitly (it never throws in this environment); no dedicated unit test for it was written, since this repository's `InProcessLegacyExportClient` has no such gate of its own to test either — the gate lives in Universal Telegram's `LegacyBindingImportServiceV1` (already covered by that repository's own `LegacyBindingImportServiceV1Test.php` unit suite, 4 tests).

## Explicit confirmation of every excluded scope item

- **No production binding execution, cutover, route switch, activation, or any live-routing change.** Every write in every test occurred against disposable, per-test-run WordPress databases.
- **No `prepared → active` activation mechanism, anywhere in this repository.**
- **No modification to `BindingImportCommand`, `InboundAdapterBridge`, `DeliverMessageService`, `WebhookController`, or `EnsureChannelCaseService`** — all five live in the Universal Telegram repository and were not touched by this work (confirmed by that repository's own closure record's `git diff` accounting).
- **No direct `universal_telegram_*` SQL from this repository** — confirmed by `NoTelegramCouplingTest`'s existing structural scan, passing unchanged against the two new authorized files.
- **No `--retry-conflicts` mode** — deferred per the [Product Owner decision record](../decisions/sc-m03-wp5-legacy-binding-po-decisions.md) item 1; ordinary reruns never revisit a terminal conflict row (confirmed by `test_terminal_row_is_never_rescanned`).
- **No AI-related migration, release, tag, or deployment.** This branch is not merged by this task.

## Test and CI evidence

| Check | Command | Result |
|---|---|---|
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 0 warnings, 137 files |
| PHPStan (level 5) | `bin/docker/phpstan.sh` | 0 errors |
| Unit | `bin/docker/test-unit.sh --php-version=8.3` | 88 tests, 756 assertions — OK (1 pre-existing unrelated skip) |
| Integration (WP 7.1 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` | 117 tests, 467 assertions — OK |
| **Interop (dual-plugin, WP 7.1 / PHP 8.3)** | `bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3` | 18 tests, 122 assertions — OK (13 pre-existing + 5 new), against Universal Telegram's real, unmerged `feature/sc-m03-wp5-legacy-binding-import-service` branch (PR #41) |

New test files: `tests/integration/Migration/LegacyBindingImportServiceTest.php` (13 tests), `tests/integration/Migration/Cli/LegacyBindCommandTest.php` (6 tests), `tests/integration/Interop/LegacyBindingImportIntegrationTest.php` (5 tests), `tests/integration/Migration/Support/FakeLegacyBindingImportClient.php` (test double, not production code).

## Ordered-merge re-validation (post-merge addendum)

Performed after Universal Telegram PR #41 was corrected (a real test-isolation defect found, root-caused, and fixed in a narrowly-scoped, test-only change — see that repository's own closure record's Validation section) and reached fully green CI on both `push` and `pull_request` triggered runs, every job, on its final head `496cbdb`.

1. **Universal Telegram PR #41 merged first.** Merge commit: `700f740a5dec2d1edffb4fe46b93d55e9d93492e` (`magpern/universal-telegram`, `main`).
2. **Universal Telegram `main` fetched fresh** into the sibling checkout this repository's interop harness mounts (`/opt/biopentra/dev/universal-telegram`), confirmed at `700f740a5dec2d1edffb4fe46b93d55e9d93492e`.
3. **This repository's real dual-plugin interop suite re-run from a freshly recreated database container** (`docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v`, then `bin/docker/test-integration-interop.sh`) against that freshly merged Universal Telegram `main`, both supported WP/PHP pairs:
   - `--wp-version=6.9 --php-version=8.1` (floor): **18 tests, 122 assertions — OK.**
   - `--wp-version=7.1 --php-version=8.3` (current): **18 tests, 122 assertions — OK.**
   Both runs are identical in count/result to the pre-merge evidence above (§ Test and CI evidence), now proven against Universal Telegram's real, merged `main` rather than only its unmerged feature branch — including the 5 `LegacyBindingImportIntegrationTest` tests confirming a real `prepared`-status binding, real idempotent rerun, real `binding_conflict_existing_active` on a pre-existing `active` binding, real non-quiescent refusal, and real `--dry-run` no-op.
   No rebase of this branch was needed for this validation: this repository's own CI matrix does not build against the Universal Telegram sibling (only the manually-invoked interop suite does), and PR #14's own required checks (`docs`, `integration-wp-only-{floor,current}`, `phpcs`, `static-analysis`, `unit` 8.1/8.3/8.4) were confirmed still green and unchanged on head `e0a3890` immediately before merge.
4. **SC PR #14 merged.** Merge commit: `33ba2f126efcbed73218cc7f291c1f6b50c10b62` (`magpern/universal-support-chat`, `main`).

## Product Owner acceptance

**Accepted.** Ordered merge (Universal Telegram PR #41 first, re-validated interop, then this PR) explicitly directed and reviewed by the Product Owner in this session, 2026-08-27, after the Universal Telegram-side test-isolation correction described above. Final merge SHAs: Universal Telegram `700f740a5dec2d1edffb4fe46b93d55e9d93492e`, Universal Support Chat `33ba2f126efcbed73218cc7f291c1f6b50c10b62`.

## Next task

Both repositories' SC-M03 work package 5 implementation PRs are now merged to `main`. WP5 remains preparation-only: no `prepared → active` activation mechanism exists in either repository, and no route switch, soak, rollback, production migration/binding operation, or cutover has been designed, authorized, or performed by this or any prior task in this work package. Any future work package building the `prepared → active` activation mechanism (§10 item 4 of the frozen implementation plan) is separate, later, and not started by this closure.
