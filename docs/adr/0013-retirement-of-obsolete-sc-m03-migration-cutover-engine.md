# ADR-0013: Retirement of the obsolete SC-M03 legacy-migration / final-cutover engine

## Status

**Accepted** — implemented on branch `feature/sc-retire-obsolete-sc-m03-engine`. Removes dead
runtime code, WP-CLI commands, and tests. **No** change to Contract v1 (ADR-0005), the
authentication profile (ADR-0007), `channel_case_ref` semantics (ADR-0011), or ADR-0012
Telegram dispatch. **No** database schema version change (`universal_support_chat_db_version`
stays at `12`). **No** installed data is dropped, purged, or reinterpreted by this change. No
DEV, production, migration, quiescence, cutover, binding, replay, deployment, release, tag, or
deletion operation was performed.

Historical SC-M03 ADRs, plans, decision records, and closure records (ADR-0008 through
ADR-0011, the `sc-m03-*` plans/decisions/closures) are **preserved unchanged** as the
historical account of that work.

## Context

Universal Telegram ADR-0044 made that plugin **transport / Support Chat adapter only** and
retired the entire SC-M03 controlled-migration-and-cutover track. Support Chat's side of that
track — a large engine reachable only from two dedicated WP-CLI commands — can no longer
operate, because every counterpart it called in Universal Telegram has been removed:

- `wp universal-support-chat legacy-migrate` (`Migration\Cli\LegacyMigrateCommand`) — Phase A
  preparatory backfill and Phase B final reconciliation, driven by `PhaseABackfillService` /
  `PhaseBReconciliationService`, reading Universal Telegram through
  `InProcessLegacyExportClient` (UT `LegacyExportServiceV1`, removed) and gating on
  `UniversalTelegramQuiescenceStateProvider` (UT `QuiescenceGate`, removed).
- `wp universal-support-chat legacy-bind` (`Migration\Cli\LegacyBindCommand`) — legacy
  Telegram-topic binding preparation via `LegacyBindingImportService` /
  `InProcessLegacyBindingImportClient` (UT `LegacyBindingImportServiceV1`, removed).
- The final-cutover handoff-provenance path inside `ContractOperationDispatcher` — the optional
  `source_bot_id`/`source_update_id` request fields, `dispatch_with_provenance()`'s wrapping
  transaction, and the `legacy_handoff_map` co-write (ADR-0010 §4). Ordinary adapter traffic
  **never populates those fields**, so for every live call the wrapper already just ran the
  domain work directly; the branch was dead the moment cutover replay ceased to exist.
- Migration schema steps **9–11** (`legacy_migration_runs` / `legacy_migration_map` /
  `legacy_migration_message_map` / `legacy_migration_batch_log` / `legacy_handoff_map`).

This machinery was audited class by class before removal. Every item fell into exactly one of:
still required by the transport-only adapter and the ADR-0012 dispatch path (kept untouched);
historical installed schema/data that must remain but is now unreachable (left in place);
obsolete code/test/CLI/wiring (removed here). Nothing under `src/Migration/` had a caller
outside that engine and its own tests.

## Decision

### 1. Runtime and CLI retirement

- `src/Migration/` is **deleted in full** (legacy export, Phase A/Phase B, quiescence
  providers, legacy binding, both WP-CLI commands, field maps, validators, repositories,
  exceptions).
- `src/ChannelContract/HandoffMapRepository.php` is deleted.
- `Core\Plugin` loses every SC-M03 import, field, accessor (`legacy_migration_map()`,
  `phase_a_backfill_service()`, `phase_b_reconciliation_service()`,
  `legacy_migration_validator()`), the whole legacy-engine construction block, and both
  `->register()` calls. The composition root no longer references Universal Telegram from
  anywhere in `src/` — `NoTelegramCouplingTest` now asserts zero authorized exceptions.
- `ContractOperationDispatcher` loses `dispatch_with_provenance()`, `provenance()`, the
  `HandoffMapRepository` constructor parameter, and the provenance docblocks. Each of the six
  formerly-wrapped operations now runs its domain work directly — **the exact code path
  ordinary adapter traffic already took**. The inbound `ingest_operator_reply` operation and
  its ADR-0012 `mark_telegram_origin()` loop-prevention hook are retained verbatim; the
  optional `DispatchEnqueuer` argument stays.

### 2. Schema: monotonic at 12, additive-inert

`target_version()` stays `12`. Steps 9, 10, and 11 become **inert no-ops** with a
`verify_step_*` postcondition that is always satisfied:

- **Fresh install** — migration still advances 8 → 9 → 10 → 11 → 12, creating **none** of the
  `legacy_migration_*` or `legacy_handoff_map` tables, then installing the ADR-0012 dispatch
  outbox (step 12) exactly as before.
- **Already-upgraded install** — `maybe_migrate()` early-returns at `db_version >= 12`; the
  historical tables and their rows are **never dropped, altered, or reinterpreted**.

The name-only manifest constants (`LEGACY_MIGRATION_RUNS_TABLE`, `LEGACY_MIGRATION_MAP_TABLE`,
`LEGACY_MIGRATION_MESSAGE_MAP_TABLE`, `LEGACY_MIGRATION_BATCH_LOG_TABLE`,
`LEGACY_HANDOFF_MAP_TABLE`) are **retained** in `Migrator` for uninstall compatibility and
diagnostics.

### 3. Uninstall

`remove_data_on_uninstall` behaviour is unchanged for every table it already cleaned. When the
operator has explicitly opted into full data removal, the five retired legacy tables are now
also dropped, so no plugin-owned table is orphaned. Absent that opt-in, uninstall touches
nothing new.

### 4. Removing the historical data later

If a site ever wants the historical `legacy_migration_*` / `legacy_handoff_map` tables and
their rows gone without a full uninstall, that requires a **separate, explicitly approved,
guarded cleanup task**. It is deliberately out of scope here (SC-M03 PO decision record 3:
"cutover completed" alone never authorizes retirement of migration data).

## Alternatives

- **Drop the legacy tables in a new migration step.** Rejected: ADR-0044's safety posture and
  SC-M03 PO decision record 3 forbid deleting migration/cutover data as a side effect; the
  hard rules for this task forbid it outright.
- **Keep the engine wired but dormant.** Rejected: it references Universal Telegram symbols
  that no longer exist, so it cannot even be constructed; leaving dead cross-plugin coupling in
  the composition root is exactly what ADR-0002 and `NoTelegramCouplingTest` exist to prevent.
- **Bump `db_version` to 13 with real no-op steps.** Rejected: needless version churn; making
  the existing steps inert is monotonic and equivalent.
- **Delete the `LEGACY_*_TABLE` constants too.** Rejected: uninstall-with-data-removal and
  diagnostics still need the names; the constants cost nothing.
- **Also retire ADR-0010/ADR-0011 as documents.** Rejected: historical decision records are
  evidence and are never rewritten; this ADR supersedes their *runtime* effect only.

## Consequences

- `src/` is fully decoupled from Universal Telegram again (only the ADR-0012 signed Contract v1
  path crosses the boundary, and that is outbound HTTP-shaped, not a namespace reference).
- No `wp universal-support-chat legacy-migrate` / `legacy-bind` command exists; invoking it is
  an unknown-command error.
- Contract v1's adapter → Support Chat operations behave identically for all real traffic. A
  hypothetical request carrying `source_bot_id`/`source_update_id` is simply ignored (unknown
  body fields), rather than opening a transaction and writing a handoff-map row.
- Fresh installs carry a smaller schema; upgraded installs are unchanged on disk.
- Test surface shrinks by the SC-M03 engine suites; new focused coverage asserts the retirement
  holds (no obsolete table on fresh migrate, historical tables survive an upgrade, no retired
  class/accessor, dispatcher has no provenance surface).

## Security and privacy impact

- No new data is stored or exposed. The retired tables, if present, are inert historical rows
  that already contained only ids, timestamps, counts, and short fixed-vocabulary strings
  (their original `verify_step_*` forbade content columns).
- Removing `dispatch_with_provenance()` removes a `START TRANSACTION`/`COMMIT` that live traffic
  never triggered — no behavioural or isolation change for real calls.
- Uninstall-with-data-removal now also clears the legacy tables, which is strictly more
  complete disposal, only ever on explicit operator opt-in.

## Affected Documents/Milestones

- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` — a planning-only note that the
  engine is retired (the milestone's historical record is otherwise unchanged).
- `docs/closure/sc-m03-engine-retirement-closure.md` — this change's closure record.
- ADR-0008, ADR-0009, ADR-0010, ADR-0011 — their runtime effect is retired; the documents
  stand as historical evidence.
- Universal Telegram ADR-0044 — the upstream decision this follows.

## Compatibility/Migration Impact

- No schema version change; `db_version` stays at `12`. Forward-only and idempotent.
- No installed row or table is dropped, altered, or reinterpreted by upgrading to this build.
- ADR-0012 Telegram dispatch, the inbound `ingest_operator_reply` path, ordinary conversation /
  widget / Hub / retention behaviour, pairing, signatures, and discovery are all unchanged —
  verified by the full existing quality gate plus the pinned dual-plugin interop suite
  (Universal Telegram `1af1cf3d9011060cb9244adfd93cfa916acfbdc6`).
- A downgrade to a pre-ADR-0013 build finds the same `db_version` 12 and simply reinstates the
  (still non-functional) engine code; no data migration either way.
