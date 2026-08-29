# Closure Record — Retirement of the obsolete SC-M03 legacy-migration / final-cutover engine

## Status

**Complete.** Implemented on branch `feature/sc-retire-obsolete-sc-m03-engine`, starting from
`origin/main` `a6019e9f606b7ee2aca04bbbf097634017edea5c`. Governed by
[ADR-0013](../adr/0013-retirement-of-obsolete-sc-m03-migration-cutover-engine.md).

## Why

Universal Telegram ADR-0044 made that plugin transport / Support Chat adapter only and retired
the SC-M03 controlled-migration-and-cutover track. Support Chat's legacy export, Phase A /
Phase B migration, quiescence, binding-preparation, and cutover-handoff machinery can no longer
operate — every Universal Telegram counterpart it called has been removed — so it must not
remain wired into the plugin.

## What was removed

| Area | Removed |
|---|---|
| Engine source | `src/Migration/` in full (25 files): `LegacyExportClient` / `InProcessLegacyExportClient`, `LegacyBindingImportClient` / `InProcessLegacyBindingImportClient` / `LegacyBindingImportService` / `LegacyBindingOutcome` / `LegacyBindingImportUnavailableException`, `PhaseABackfillService`, `PhaseBReconciliationService`, `QuiescenceStateProvider` / `UniversalTelegramQuiescenceStateProvider` / `DefaultDenyQuiescenceStateProvider` / `QuiescenceLostDuringReconciliationException`, `LegacyMigration{Run,Map,MessageMap,BatchLog}Repository`, `LegacyMigrationMapEntry`, `LegacyMigrationValidator`, `LegacyFieldMap`, `IdempotencyKeyDeriver`, `LegacyExportUnavailableException`, `Cli/LegacyMigrateCommand`, `Cli/LegacyBindCommand` |
| Contract | `src/ChannelContract/HandoffMapRepository.php`; `ContractOperationDispatcher::dispatch_with_provenance()` + `provenance()` + the `HandoffMapRepository` constructor param + the `source_bot_id`/`source_update_id` handling |
| Composition root | `Core\Plugin`: all `Migration\*` + `HandoffMapRepository` imports, the four legacy accessor methods and their fields, the entire legacy-engine construction block, both WP-CLI `->register()` calls |
| CLI | `wp universal-support-chat legacy-migrate`, `wp universal-support-chat legacy-bind` |
| Tests | `tests/integration/Migration/` and `tests/unit/Migration/` in full; the five cutover-provenance tests and the `HandoffMapRepository` fixture in `ContractOperationsControllerTest`; the transaction-isolation cleanup that only that provenance path needed |

## What was retained (proven still needed)

- **ADR-0012 Telegram dispatch** — `TelegramDispatch\*`, `DispatchWorker`, `DispatchEnqueuer`,
  the `universal_support_chat_telegram_dispatch` table (step 12), the opt-in flag: untouched.
- **Inbound `ingest_operator_reply`** Contract operation and its ADR-0012
  `mark_telegram_origin()` loop-prevention hook: verbatim.
- The other seven adapter → Support Chat Contract operations: each now runs its domain work
  directly — the exact path all real traffic already took (provenance fields were never
  populated by live callers).
- `AdapterContractClient`, pairing, signatures, discovery, `ChannelStatusRepository`,
  retention, widget, Hub: unchanged.
- `Migrator` name-only constants `LEGACY_MIGRATION_{RUNS,MAP,MESSAGE_MAP,BATCH_LOG}_TABLE` and
  `LEGACY_HANDOFF_MAP_TABLE` — kept for uninstall compatibility and diagnostics.

## Schema behaviour

`universal_support_chat_db_version` stays at **12** (`target_version()` unchanged).

- **Fresh install** — migration advances 8 → 9 → 10 → 11 → 12. Retired steps 9–11 are inert
  no-ops: **no** `legacy_migration_*` and **no** `legacy_handoff_map` table is created. Step 12
  (the ADR-0012 dispatch outbox) still installs. Proven by
  `ScM03RetirementSchemaTest::test_fresh_migration_reaches_12_and_creates_no_retired_sc_m03_table`.
- **Upgraded install** — a site that already ran the old steps keeps its
  `legacy_migration_*` / `legacy_handoff_map` tables and every row, byte-for-byte. Nothing is
  dropped, altered, or reinterpreted. Proven by
  `ScM03RetirementSchemaTest::test_upgraded_install_keeps_pre_existing_legacy_tables_and_data_untouched`.
- **Uninstall** — unchanged for every table it already cleaned; when `remove_data_on_uninstall`
  is explicitly enabled the five retired legacy tables are now also dropped (no orphans).

## Proof that Telegram dispatch and ordinary adapter traffic remain intact

- Full dual-plugin interop suite against Universal Telegram pinned at
  `1af1cf3d9011060cb9244adfd93cfa916acfbdc6`: `OK (4 tests, 54 assertions)` — real two-way
  Ed25519 pairing, real signed Contract v1, visitor message → real encrypted UT transport row,
  retry converges with no duplicate, Telegram-originated reply never loops back out, message
  retained + retryable when the adapter is disabled.
- `ContractOperationsControllerTest` (real signed requests): claim / ingest / duplicate-ingest
  idempotency / resolve↔reopen / channel-unavailable / delivery-failure / the full uniform-
  denial matrix — all green.
- `NoTelegramCouplingTest` — `src/` now has **zero** authorized Universal Telegram references.

## Test and CI evidence

| Gate | Result |
|---|---|
| PHPCS (`phpcs.xml.dist`, 106 files) | clean |
| PHPStan (level 5, `src`) | `No errors` |
| Unit (`test-unit.sh`) | `OK (66 tests, 185 assertions)` |
| Integration WP-only, WP 7.1 / PHP 8.3 | `OK (95 tests, 406 assertions)` |
| Integration WP-only, WP 6.9 / PHP 8.1 | `OK (95 tests, 406 assertions)` |
| Interop (full suite, UT `1af1cf3…`) | `OK (4 tests, 54 assertions)` |
| `check-doc-links` | pass |

New coverage: `tests/unit/Core/ScM03EngineRetirementTest.php`,
`tests/integration/Persistence/ScM03RetirementSchemaTest.php`, plus the tightened
`NoTelegramCouplingTest`.

## Explicit exclusions

- **No** DEV, production, migration, purge, quiescence, cutover, binding, replay, route-switch,
  deployment, release, tag, or deletion operation was performed.
- **No** Universal Telegram change.
- **No** historical ADR / plan / decision / closure record was removed or rewritten (ADR-0008
  through ADR-0011 and the `sc-m03-*` documents stand as historical evidence).
- **No** installed database row or table was deleted, altered, or reinterpreted.
- `universal_support_chat_db_version` was **not** reduced.
- Contract v1, pairing, signatures, the ADR-0012 dispatch outbox, and the current Support Chat
  ↔ Telegram runtime paths are **unchanged**.
- Removing the historical `legacy_migration_*` / `legacy_handoff_map` data from an installed
  site (short of a full opted-in uninstall) is **not** included — it needs a separately
  approved, guarded cleanup task.

## Recommended next task

A small follow-up may retire the now-inert migration **step slots 9–11** entirely by bumping
`target_version()` to a fresh number with a single collapsed no-op step, if the team prefers
that to three inert slots — cosmetic only, not required. Otherwise none: the retirement is
complete and the plugin's live surface is Support Chat as sole system of record plus ADR-0012
dispatch.
