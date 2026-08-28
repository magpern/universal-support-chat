# Plan: Automatic Support Chat → Telegram message dispatch (v1)

Implements **ADR-0012**. Branch: `feature/sc-telegram-adapter-dispatch`.

## 1. Milestone charter and ADRs

- Milestone: `docs/milestones/sc-m04-telegram-optional-acceptance.md` — the SC-owned-delivery
  half of the ADR-0044 end state (Support Chat owns website chat; Universal Telegram is a
  transport/adapter).
- ADRs relied on: ADR-0005 (Contract v1), ADR-0006 (optional-channel failure model), ADR-0007
  (mutual signed auth), ADR-0011 (`channel_case_ref` = conversation UUID). Universal Telegram
  ADR-0044 (transport-only) is the external dependency, merged on UT `main`
  `1af1cf3d9011060cb9244adfd93cfa916acfbdc6`.
- ADR introduced: **ADR-0012**.

## 2. Repository findings at drafting time

- `AdapterContractClient` is wired in `Core\Plugin` with **zero** call sites.
- `HubActions::handle_reply()` and `ConversationsController::handle_post_message()` only persist
  the message; neither dispatches anything.
- Inbound Telegram replies already arrive via the real Contract v1 `ingest_operator_reply`
  (`ContractOperationDispatcher`) + UT `InboundAdapterBridge` / `OperatorIdentityMap`.
- `IdempotencyKeys::for_message_delivery($message_uuid)` is the durable delivery boundary on
  both sides; UT `DeliveryIdempotencyRepository` dedupes.
- Schema is version 11; migrations are numbered forward-only steps with a `verify_step_N`
  postcondition. WP-Cron (not Action Scheduler) is the established background pattern
  (`RetentionCleanupHandler`, `NonceCleanupHandler`).
- The SC↔UT interop harness (`bin/docker/test-integration-interop.sh`) loads both plugins'
  real source; UT's own `tests/integration/Interop/InteropTestCase` demonstrates a real
  two-way Ed25519 pairing driving real `ensure_channel_case` / `deliver_message`.

## 3. Assumptions and open questions

- **A1** — "product policy requires operator notification" is interpreted minimally: notify
  operators once, when `ensure_channel_case` actually **creates** a new forum topic. Broader
  presence/assignment-aware notification is out of scope.
- **A2** — the fixed Contract v1 peer slug for the adapter is `universal-telegram` (matches
  UT `ContractConstants::SELF_ID` and UT's own interop suite).
- **A3** — pre-existing SC interop suites that reference now-removed UT SC-M03 classes
  (`QuiescenceGate`, `DeferredUpdateRepository`, `universal_telegram_conversations`) are
  already broken on `origin/main` by UT PR #62 / ADR-0044. Repairing/retiring them is **not**
  part of this feature (separate follow-up); this plan's interop CI job runs only the new
  ADR-0012 test.

## 4. Architectural decisions

See ADR-0012 §Decision and §Alternatives. Summary: a Support-Chat-owned, content-free outbox
table; a non-throwing post-commit enqueue seam on the two write paths; a permanent
suppression marker on the inbound-ingest path for loop prevention; a WP-Cron-only delivery
worker driving the existing signed Contract v1 client with the message-UUID idempotency key;
an opt-in `telegram_dispatch_enabled` flag (default off).

## 5. Directory, namespace, schema, and API impact

- New namespace `UniversalSupportChat\TelegramDispatch\` — `DispatchRecord`,
  `DispatchOutboxRepository`, `TelegramDispatchService`, `DispatchEnqueuer`, `DispatchWorker`.
- Schema: `Migrator` step 12 adds `universal_support_chat_telegram_dispatch`
  (`db_version` 11 → 12); `verify_step_12` forbids content columns.
- `Settings`: `telegram_dispatch_enabled` (bool, default false).
- Wiring: optional constructor arg on `ConversationsController`, `HubActions`,
  `ContractOperationDispatcher`; optional arg on `RetentionCleanupHandler` (purge orphan
  rows). `Core\Plugin` constructs and registers the new services.
- Lifecycle: `Deactivator` + `Uninstaller` unschedule the worker; `Uninstaller` drops the
  table under `remove_data_on_uninstall`.
- **No new REST route. No new Contract operation. No change to any existing table.**

## 6. Security and privacy impact

See ADR-0012 §Security and privacy impact. The outbox is content-free (verified in schema and
in `DispatchSchemaTest`); plaintext is in memory only during a `deliver_message` call; secrets
are untouched; the feature is opt-in.

## 7. Test and CI impact

- Unit: `SettingsTest` — `telegram_dispatch_enabled` default + coercion.
- Integration (wp-only): `DispatchOutboxRepositoryTest`, `TelegramDispatchServiceTest`,
  `DispatchWiringTest`, `DispatchSchemaTest`; `ActivationTest` / `MigratorTest` version
  assertions 11 → 12.
- Interop (real UT `main`, real signed Contract v1):
  `tests/integration/Interop/TelegramDispatchInteropTest.php` — visitor message → real
  encrypted UT transport row + real active binding; retry converges with no duplicate;
  Telegram-originated reply ingested but never mirrored back; message retained + retryable
  when UT is disabled.
- CI: new `interop-telegram-dispatch` job checks out UT `main` and runs the new interop test.

## 8. Work packages (execution order)

1. `DispatchRecord` + `DispatchOutboxRepository` + `Migrator` step 12 + `verify_step_12`.
2. `TelegramDispatchService` (worker core) + `DispatchWorker` (WP-Cron) + `DispatchEnqueuer`.
3. `Settings.telegram_dispatch_enabled`.
4. Wire `Core\Plugin`; optional args on the three write-path collaborators and
   `RetentionCleanupHandler`; `Deactivator` / `Uninstaller`.
5. Tests (unit, integration, interop) + CI job.
6. Docs: ADR-0012, this plan, README/milestone updates.

## 9. Risks and mitigations

- **Unbounded outbox growth while UT is down** → capped backoff, opt-in flag, retention purge
  clears rows, `count_by_state()` diagnostics.
- **Duplicate Telegram delivery on retry** → message-UUID idempotency key on both sides + the
  outbox `state` machine + guarded row claim.
- **Loop (Telegram reply mirrored back)** → permanent `suppressed` marker written on the
  inbound-ingest path, independent of the feature flag; the worker cannot claim `suppressed`
  rows.
- **Write path made slower/fragile** → enqueue is post-commit, non-throwing, and a no-op when
  disabled or when the schema is unavailable.

## 10. Out of scope

- Any change to Contract v1, ADR-0007, or ADR-0011.
- Repairing/retiring the pre-existing SC-M03 interop suites broken by UT ADR-0044 (A3).
- Universal Telegram's stale-settings cleanup (separate follow-up).
- Presence/assignment-aware operator notification policy beyond "notify on topic creation".
- Any DEV or production deployment, release, or tag.
- A Hub/admin UI surface for the dispatch backlog (diagnostics are audit + repository method
  only in v1).

## 11. Definition of done

- Visitor messages and Hub operator replies are automatically delivered to the linked Telegram
  topic when `telegram_dispatch_enabled` is on.
- A Telegram-originated reply is ingested into Support Chat and never mirrored back out.
- A committed Support Chat message is never lost when UT is absent/unpaired/disabled/
  unavailable — it is recorded as retryable delivery state.
- Repeated events/retries converge with exactly one Telegram delivery.
- With the flag off, visitor and Hub behaviour is byte-for-byte unchanged.
- phpcs, PHPStan, unit, integration (wp-only), docs, and the new interop test are green.
