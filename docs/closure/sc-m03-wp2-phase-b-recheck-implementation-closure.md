# Closure Record — SC-M03 Work Package 2 (Support Chat side): Real Quiescence Provider and Phase B Continuous Re-check

## Final status

**PASS.** Implements `docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md` in full: `UniversalTelegramQuiescenceStateProvider`, the `PhaseBReconciliationService` continuous-recheck amendment, the composition-root swap, and the dual-plugin interop proof against Universal Telegram's real, complete ADR-0040 implementation.

This does **not** claim:

- **No production quiescence operation was ever performed.** Every state transition, every real UT quiescence-gate call, and every Phase B run in this closure's own evidence ran against disposable, per-test-run WordPress databases.
- **No cutover, route switch, soak, or rollback.** Unaffected, unauthorized, unimplemented.
- **The original WP3-4 closure's scope is not reopened.** That closure's own PASS status and everything it evaluated against `DefaultDenyQuiescenceStateProvider`/`FakeQuiescenceStateProvider` stand unchanged; this closure is additive.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `6c3a0c5` (merge of PR #10, the Phase B continuous-recheck addendum documentation freeze)
- Branch: `feature/sc-m03-wp2-phase-b-recheck-implementation`
- Frozen addendum: `docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md` (unedited by this implementation)
- Plugin version: `0.5.0` → `0.6.0` (minor: the migration engine's quiescence gate becomes genuinely usable in production for the first time — the prior `DefaultDenyQuiescenceStateProvider` made Phase B permanently unusable by design)
- Schema version (`universal_support_chat_db_version`): unchanged at `9` — no new table; this work adds a class and amends an existing method's call pattern only.

## New source

- `src/Migration/UniversalTelegramQuiescenceStateProvider.php` — delegates in-process to Universal Telegram's `quiescence_status()` accessor, mirroring `InProcessLegacyExportClient`'s exact defensive-call shape (`class_exists()` → `instance()` → `method_exists()` → null-check → `try`/`catch \Throwable`), fully-qualified names only, no `use` import of Universal Telegram's namespace. Fails closed (`is_quiescent(): false`, `since(): null`) on every error path.
- `src/Migration/QuiescenceLostDuringReconciliationException.php` — internal signal used by `PhaseBReconciliationService`'s pre-promotion re-check.

## Amended source

- `src/Migration/PhaseBReconciliationService.php` — `is_quiescent()` is re-checked at the top of every loop iteration in `run()` (not only once at entry) and again immediately before each of `reconcile_one()`'s two promotion-to-`migrated` writes. On loss, `run()` stops immediately, promotes nothing further, and returns the existing `REFUSED_NOT_QUIESCENT` reason — no new reason code, no interface change. Rows already promoted earlier in the same run before the loss remain promoted.
- `src/Core/Plugin.php` — composition root now wires `UniversalTelegramQuiescenceStateProvider` in place of `DefaultDenyQuiescenceStateProvider` (which remains available, unremoved, still the class `test_phase_b_refuses_to_run_against_the_default_deny_provider` constructs directly).
- `universal-support-chat.php` — version bump.

## Test evidence

- `tests/integration/Migration/Support/FakeQuiescenceStateProvider.php` gains `make_not_quiescent()`, enabling tests to simulate quiescence being lost mid-run.
- `tests/integration/Migration/PhaseBReconciliationServiceTest.php` — two new tests: quiescence lost between the top-of-loop check and a later row's processing (earlier row stays promoted, run refuses before touching the later row); quiescence lost between a row's drift-import work and its own promotion write (that row is not promoted, run refuses). All 5 pre-existing tests in the file pass unchanged.
- New unit test for the provider (the "Universal Telegram inactive" path only — the "active, returns true" paths are covered instead by the real dual-plugin interop suite below, since this repository's own unit-test process never loads Universal Telegram and there is no existing precedent here for stubbing an absent cross-plugin class without risking leakage into unrelated tests).
- `tests/unit/Core/NoTelegramCouplingTest.php` — the new provider added to the existing narrow authorized-exception list, plus a new test asserting none of the three authorized files reference a `universal_telegram_*` table in an actual string literal.
- **`tests/integration/Interop/QuiescenceProviderIntegrationTest.php`** (new, the interop test the original WP3-4 closure's own "Next task" section anticipated by name) — proves, against Universal Telegram's real, complete ADR-0040 implementation (not `FakeQuiescenceStateProvider`): fail-closed baseline while UT is `idle`; Phase B refusal while `draining`; Phase B refusal while `quiescent` with a real nonempty encrypted deferred-update backlog; Phase B success/promotion once that backlog is genuinely replayed; and — using the real `UniversalTelegramQuiescenceStateProvider` for the full stack, not a fake at any layer — a real mid-run quiescence loss (a genuine buffered Telegram update landing between two rows of a multi-row run) caught by the continuous-recheck amendment. State transitions are driven through UT's own real `QuiescenceGate`/`DeferredUpdateRepository` classes and the same replay call sequence `QuiescenceCommand::replay_deferred_updates()` makes.

## Test and CI evidence

| Check | Command | Result |
|---|---|---|
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 127 files |
| PHPStan | `bin/docker/phpstan.sh` | 0 errors |
| Unit | `bin/docker/test-unit.sh` | 88 tests, 756 assertions — OK |
| Integration (WP 7.1 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` | 98 tests, 399 assertions — OK |
| **Interop (dual-plugin, against Universal Telegram's real ADR-0040 implementation)** | `bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3` | 13 tests, 99 assertions — OK (8 pre-existing + 5 new) |

## Explicit confirmation of every excluded scope item

- **No production migration execution or production quiescence operation.** Every write in every test occurred against disposable, per-test-run WordPress databases.
- **No `QuiescenceStateProvider` interface change.** Both methods (`is_quiescent(): bool`, `since(): ?DateTimeImmutable`) are byte-for-byte unchanged.
- **No cutover, route switch, soak, or rollback.**
- **No Universal Telegram repository modification.** The interop test reads Universal Telegram's real classes/tables through its own repositories only; nothing in `src/` was changed in the sibling checkout.
- **No reopening of the original WP3-4 closure's scope or PASS status.**

## Product Owner acceptance

Pending. This PR is opened for review and is not merged by this task.
