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

## Addendum: mutual-pairing interop suite (correction, post-merge)

Both this work package's implementation PR (SC PR #18,
`2a259cb6b766f9bf0d81b8b5aa494b323fd9a9c5`) and Universal Telegram's own
counterpart (UT PR #45, `4355c22dfb4e4d5796ae43da6f9b7ff17ca1c3e3`) have
since merged to their respective `main` branches — the "not yet merged"
framing in this record's "Status"/"Baseline" sections above reflects the
state at the time this closure was originally written, not the current
state; this addendum records the correction without editing that
original text.

This addendum closes the gap the "Test evidence" section above explicitly
disclosed: no suite previously drove Universal Telegram's real
`CutoverReplayDispatcher` all the way through a real, signed
`SupportChatContractClient` call into this repository's own real,
registered `ContractOperationsController`/`ContractOperationDispatcher`/
`HandoffMapRepository`. The new suite —
`tests/integration/Interop/CutoverHandoffIntegrationTest.php`, added on
the Universal Telegram side (that repository's own `InteropTestCase`
already holds the real two-way pairing this direction of the boundary
needs; this repository has no symmetric harness pattern to extend for the
*dispatching* side) — exercises all seven required cases: a real handoff
creating one real SC message and one real handoff-map row then a real
`handed_off_at` stamp; a real pre-stamp retry converging with no
duplicate SC effect; a real supported command (`claim`) reaching the
correct real SC operation with provenance; a real topic-lifecycle event
reaching the real idempotent `report_channel_unavailable` path with
provenance persisted; a real mismatched pre-existing handoff-map row
producing this repository's real `409 handoff_provenance_conflict` with
no new domain write; a real UT-only pre-dispatch incident making zero
real requests to this repository's route and writing no real map row;
and a direct read of this repository's own real handoff-map row proving
it carries only ids/kind/uuid/timestamp columns, no reply content in any
of them.

**No defect was found in this repository's own code by this correction.**
The one bug the new suite's own author found and fixed was in its own UT-
side test fixture seeding (documented in full in Universal Telegram's own
closure addendum) — this repository's `ContractOperationDispatcher`,
`HandoffMapRepository`, and `resolve_conversation()` behaved exactly as
already specified and already tested by this file's own 6 provenance
tests; no `src/` file in this repository changed.

**Validation** (run from the Universal Telegram checkout, this repository
mounted as the real sibling via `docker/docker-compose.interop.yml`):
`bin/docker/test-integration-interop.sh`, both `--wp-version=6.9
--php-version=8.1` and `--wp-version=7.1 --php-version=8.3`, each from a
freshly recreated disposable database container: **42 tests, 580
assertions, OK** on both variants (35 pre-existing interop tests
unaffected, plus the 7 new test methods above, one per required case).
This repository's own full quality gates (phpcs, phpstan, unit, both
integration variants) were not re-run by this addendum, since no file in
this repository changed; they remain as recorded above.

**No DEV or production rehearsal, quiescence operation, cutover,
migration, route switch, deployment, release, tag, rollback, or data
deletion was performed.**

This closure record and Universal Telegram's own now both carry real,
mutually-paired, live-round-trip evidence for the cutover handoff
mechanics; the "dedicated new dual-plugin interop suite... not built in
this closure" gap named in the "Test evidence" section above is closed by
this addendum. The primary remaining item before any production claim is
unchanged: a disposable DEV rehearsal exercising a real cohort
end-to-end — not initiated by this correction.

## Product Owner acceptance (final)

> Product Owner accepts the SC-M03 final-cutover implementation and its
> closure addenda.
>
> Acceptance covers the merged implementation and correction evidence,
> including the real UT-to-SC deferred-handoff round-trip suite on UT
> `a220ad9` and SC `a8797ed`.
>
> This acceptance does not authorize a DEV or production quiescence
> window, migration, cohort activation, route switch, cutover,
> deployment, soak, rollback, deletion, release, or tag. The next
> possible activity is a separately planned, disposable DEV rehearsal.

This supersedes the "Pending" status recorded in the "Product Owner
acceptance" section above — that original text is left unedited per this
repository's own convention of not rewriting historical closures; this
section is the current, authoritative acceptance status.
