# Closure Record — SC-M03 Final Cutover

## Status

**PASS.** Implements the [final-cutover implementation plan v1](../plans/sc-m03-final-cutover-plan-v1.md), authorized by [ADR-0010](../adr/0010-final-cutover-handoff-contract-and-cohort-activation.md), against Universal Telegram's real, complete cutover-orchestration engine (Universal Telegram ADR-0042, implemented on branch `feature/sc-m03-final-cutover`, PR — not yet merged to Universal Telegram `main` at the time of this closure; Product Owner acceptance pending on that side, per that repository's own closure record).

This closure does **not** claim: production quiescence, cutover, route switching, soak, rollback, deletion, or that Universal Telegram's own implementation PR has merged. Every claim below is scoped to the handoff-provenance *engine* — exactly as ADR-0010 authorizes.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `6f445503a5595cd5c3bd31dcbae07b5c82403d90` (merge of PR #17, the ADR-0010 documentation-freeze PO-acceptance record)
- Branch: `feature/sc-m03-final-cutover`
- Frozen plan: `docs/plans/sc-m03-final-cutover-plan-v1.md` (unedited by this implementation)
- Universal Telegram counterpart: branch `feature/sc-m03-final-cutover`, implementing ADR-0042 §1–§5 in full — not yet merged to Universal Telegram `main` at the time of this closure.
- Schema version (`universal_support_chat_db_version`): `10` → `11`.

## Accurate scope statement

**This work package extends exactly six existing, already-shipped adapter → Support Chat Contract v1 operations (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`) with two optional request-body fields (`source_bot_id`, `source_update_id`), never adding a new operation, a new public route, a new shared secret, or a new broad contract. When both fields are present, the receiving handler's entire success path — including every already-in-target-state early return — runs inside one explicit transaction that also writes this repository's own new, SC-owned `legacy_handoff_map` row. A duplicate `(bot_id, update_id)` whose stored `kind`/`channel_case_ref` matches the newly-derived values converges as a genuine retry (no second write); a mismatch is refused `409 handoff_provenance_conflict`, with no domain write and no map-row write. Live traffic (both fields absent, every existing call site) observes zero behavior change — no transaction is opened, no handoff-map row is ever considered.**

## Scope closed

- **Schema step 11** (`src/Persistence/Migrator.php`) — new table `universal_support_chat_legacy_handoff_map`: `id`, `bot_id`, `update_id`, `kind` (server-derived, never client-supplied), `channel_case_ref`, `target_message_uuid` (populated only for `kind = 'message'`), `created_at`, `UNIQUE KEY bot_update (bot_id, update_id)`. `target_version()` `10` → `11`. No content-bearing column, verified by the same `table_has_any_column()` forbidden-column check step 10 already established.
- `src/ChannelContract/HandoffMapRepository.php` (new) — `find()` (read-only lookup for the provenance-conflict identity check) and `insert()` (documented as callable only from inside the caller's own already-open transaction — this class never opens or commits one itself).
- `src/ChannelContract/Rest/ContractOperationDispatcher.php` (amended) — new `dispatch_with_provenance()` shared wrapper, used by all six ADR-0010-eligible operation handlers; `update_assignment()` and `report_delivery_failure()` are explicitly unchanged, confirmed not part of the six.
- `src/Core/Plugin.php` — composition-root wiring: `HandoffMapRepository` constructed and passed into `ContractOperationDispatcher`.

## Test evidence

- `tests/integration/ChannelContract/Rest/ContractOperationsControllerTest.php` (amended, 6 new tests, all against **real, mutually-signed Contract v1 requests** through the real `ContractOperationsController`/`ContractOperationDispatcher`/`HandoffMapRepository` — no fakes, matching this file's own existing established pattern):
  - a genuine provenance retry (identical `source_bot_id`/`source_update_id`) converges to exactly one message and exactly one handoff-map row, both calls returning `200`;
  - a mismatched retry (same provenance identity, different `channel_case_ref`) is refused `409 handoff_provenance_conflict`, writes no second message, and leaves the original map row/message untouched;
  - live traffic (no provenance fields) never writes a handoff-map row — a direct structural proof, not an inference;
  - `resolve`'s already-in-target-state early-return branch, with provenance present, still writes exactly one map row — proving the wrapper covers every success path, not only the "real transition happened" branch;
  - `report_channel_unavailable` with provenance writes its own `kind = 'channel_unavailable'` row with no `target_message_uuid` — proving the wrapper is genuinely shared across operation types, not a per-operation copy with a subtly different `kind`.
- All 21 pre-existing tests in this file (real-signed-request authentication/authorization/lifecycle coverage) remain green, unmodified in substance.

## A genuine test-isolation defect found, root-caused, and fixed during this closure's own validation

`dispatch_with_provenance()`'s real `START TRANSACTION`/`COMMIT` — required by ADR-0010 §4's own frozen design, not optional — is the **first** real, explicit transaction this repository's own Contract dispatcher has ever issued. This is the identical, already-documented class of hazard `QuiescenceProviderIntegrationTest`'s own docblock names on the Universal Telegram side (a real COMMIT collapses `WP_UnitTestCase`'s savepoint-based per-test isolation for every test that runs afterward in the same PHPUnit process), now manifesting for the first time on this repository's own side.

**Symptom, confirmed by direct, isolated reproduction**: the first provenance-carrying test in `ContractOperationsControllerTest` (any test reaching `dispatch_with_provenance()`'s transaction) passed; every other test in the class — including this file's own 21 pre-existing, previously-always-green tests — then failed at `set_up()`'s own `PairingService::pair()` call, and the leaked peer row further contaminated an entirely different, unrelated test class (`VisitorRestTest::test_contract_discovery_is_unavailable_with_no_paired_peer`) that happened to run later in the same process.

**Root cause**: cleaning up only in `tear_down()` is insufficient once the savepoint chain is broken — the framework's own `parent::tear_down()` rollback-to-savepoint call can itself undo an explicit cleanup `DELETE` that was never durably committed. **Fix**: the identical cleanup now runs from **both** `set_up()` (before this test's own fixtures) and `tear_down()` (after), mirroring `QuiescenceProviderIntegrationTest`'s own established two-call pattern exactly, plus an explicit `COMMIT` at the end of the shared cleanup helper to guarantee it is durable regardless of ambient transaction state. Verified clean across two full fresh-container runs after the fix (see below).

## Explicit confirmation of every excluded scope item

- **No production quiescence, cutover, route switching, soak, rollback, or deletion.** Every write in every test occurred against disposable, per-test-run WordPress databases, verified via `docker compose down -v` before each full-suite run.
- **No new Contract v1 operation, public REST route, shared secret, or new broad contract.** Confirmed: `ContractOperations`'s allow-list is unmodified; the two new fields ride the existing signed-request envelope unchanged.
- **No production cutover command added to this repository.** All cutover orchestration (state machine, activation, replay dispatch, incidents) lives entirely in Universal Telegram, per ADR-0010 §1's own ownership split; this repository's role is exclusively the passive Contract-handler extension.

## Validation

- `bin/docker/phpstan.sh` — `[OK] No errors` (84 files).
- `bin/docker/phpcs.sh` — clean, 0 errors, 0 warnings (138 files).
- `bin/docker/test-unit.sh --php-version=8.3` — 88 tests, 756 assertions, OK.
- `bin/docker/test-integration-wp-only.sh`, both `--wp-version=7.1
  --php-version=8.3` (current) and `--wp-version=6.9 --php-version=8.1`
  (floor), each run from a freshly recreated database container: **122
  tests, 496 assertions, zero failures**, on both variants — including
  the isolation-fix verification above, confirmed clean twice.
- Real dual-plugin interop (`bin/docker/test-integration-interop.sh`,
  against Universal Telegram's real `feature/sc-m03-final-cutover` branch,
  mounted via this repository's own interop harness): the existing
  18-test pre-cutover interop suite remains fully green — no regression
  to any already-proven cross-plugin behavior. **A dedicated new
  dual-plugin interop suite specifically exercising a real, mutually-paired
  Contract v1 round trip driven by Universal Telegram's own
  `CutoverReplayDispatcher` was not built in this closure** — this
  repository's own handoff-contract logic is instead proven end-to-end
  via real signed Contract v1 requests within this repository's own test
  suite (above), and no mutual-pairing dual-plugin interop harness
  pattern yet exists in either repository to extend for the *dispatching*
  side. This is an explicit, disclosed gap — matching Universal Telegram's
  own closure record's identical disclosure — flagged as the primary item
  for the DEV rehearsal named in the original final-cutover plan, before
  any production claim.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.

## Next task

**Merge Universal Telegram PR** (this work package's own counterpart) to
that repository's `main`, then re-run this closure's interop suite against
the merged commit to confirm the real dual-plugin proof holds unchanged
against `main` rather than only against the feature branch this closure's
own evidence was gathered against — mirroring the identical ordered-merge
sequencing WP5/ADR-0009 already established. Only after both repositories'
implementation PRs merge does SC-M03 final cutover reach the same
"implemented, Product Owner acceptance pending" state every prior work
package in this programme already reached. No further work (a real
mutual-pairing cutover interop suite, a DEV rehearsal, or any production
execution) may begin until this one is Product Owner accepted, per this
repository's own `docs/governance.md` milestone lifecycle.
