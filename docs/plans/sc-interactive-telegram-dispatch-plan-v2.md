# Plan: Low-latency interactive Support Chat → Telegram dispatch (v2)

Supersedes `sc-interactive-telegram-dispatch-plan-v1.md` (unchanged, retained as the historical
record). Realises [ADR-0014](../adr/0014-interactive-chat-delivery-class-and-immediate-dispatch.md)
**including its Amendment 1** (fully asynchronous expedited dispatch — review correction).

## 1. Why v2

v1's design ran a "bounded immediate delivery attempt" in the visitor / Hub request. For a new
conversation that attempt calls `ensure_channel_case`, whose Universal Telegram handler makes a
**synchronous** `createForumTopic` Bot API call — so the website response could block on
Telegram. ADR-0014 Amendment 1 replaces the in-request attempt with an **asynchronous-only**
mechanism. v2 implements that.

## 2. Corrected architecture

| Concern | v1 (withdrawn) | v2 |
|---|---|---|
| In-request work | atomic commit + one synchronous `deliver_one()` (`ensure_channel_case` → `deliver_message`) | atomic commit **only**, then a non-blocking async kick |
| Telegram I/O in the request | possible (`createForumTopic` for a new conversation) | **never** |
| Expedite for a new conversation | in-request attempt, then worker | worker only (creates topic + binding, then delivers) |
| Expedite for an existing binding | in-request attempt | immediate async worker run + ADR-0045 queue priority |
| Kick | `wp_schedule_single_event( now )` | `wp_schedule_single_event( now )` **+** `spawn_cron()` non-blocking loopback |
| Exception containment | `attempt_now()` body wrapped | the **whole** kick seam (schedule + `spawn_cron` + audit) wrapped, non-throwing |

## 3. Repository findings (delta from v1)

- `EnsureChannelCaseService::ensure()` (UT) → `ForumTopicService::create()` →
  `TelegramApiClient::create_forum_topic()` = a synchronous `wp_remote_post` to
  `api.telegram.org`. Confirmed against UT `1af1cf3` and the ADR-0045 branch.
- WordPress core `spawn_cron()` (`wp-includes/cron.php`) does a `wp_remote_post` with
  `blocking => false`, `timeout => 0.01`, guarded by the `doing_cron` transient lock; it does
  **not** early-return under `DISABLE_WP_CRON` when called directly (only the automatic `wp_cron()`
  on `init` honours that constant). It is the SC-appropriate non-blocking kick — SC has no
  Action Scheduler dependency (ADR-0012 rejected adding one).
- `DispatchEnqueuer::persist_and_enqueue()` already commits message + outbox atomically and
  calls `kick()` after commit.

## 4. Implementation decisions (v2)

1. **Remove** `TelegramDispatchService::attempt_now()`, `immediate_outcome_label()`,
   `IMMEDIATE_RETRY_BACKOFF`; **remove** `DispatchOutboxRepository::claim_one()`; **remove**
   `DispatchEnqueuer`'s `?TelegramDispatchService` field / `set_immediate_dispatch()` / the
   post-commit `attempt_now()` call; **remove** the `Plugin` `set_immediate_dispatch()` wiring.
2. **Keep** `AdapterContractClient::deliver_message()`'s optional `delivery_class` (default
   `standard`, always on the wire, not in the idempotency key) — the worker uses it.
3. **Keep** `TelegramDispatchService::DELIVERY_CLASS_INTERACTIVE` and
   `deliver_one( DispatchRecord $record, string $delivery_class )`; `dispatch_due()` passes
   `DELIVERY_CLASS_INTERACTIVE` (every outbox row is a website-chat message).
4. **New** `DispatchWorker::IMMEDIATE_HOOK` (`universal_support_chat_telegram_dispatch_immediate`)
   — a one-off hook **distinct** from the recurring `DispatchWorker::HOOK`
   (`universal_support_chat_telegram_dispatch_run`, 60 s). The recurring event is normally
   already scheduled on `HOOK`, so a `wp_next_scheduled( HOOK )` guard would never create an
   expedited one-off in the deployed state. `register()` binds **both** hooks to `run()`.
5. **New** `DispatchWorker::request_immediate_run(): void` — `static`, **non-throwing**
   (`try { … } catch ( \Throwable ) {}`): if no `IMMEDIATE_HOOK` event is pending, schedule one
   at `time()`; then call `spawn_cron()`. `DispatchEnqueuer` calls it after commit. At most one
   immediate event pending (`wp_next_scheduled` guard + `wp_schedule_single_event`'s own
   de-dup).
6. `DispatchWorker::unschedule()` (deactivation / uninstall) clears **both** `HOOK` and
   `IMMEDIATE_HOOK`. `Deactivator` / `Uninstaller` already call it — no change there.
7. **No** schema / `db_version` / version / route / setting / menu / dependency change.
   `telegram_dispatch_enabled` stays the sole opt-in; disabled ⇒ no transaction, no outbox row,
   no kick.

## 5. Files touched (v2)

- `src/ChannelContract/Outbound/AdapterContractClient.php` — unchanged from v1 (optional
  `delivery_class`).
- `src/TelegramDispatch/TelegramDispatchService.php` — drop `attempt_now()` &c.; keep the class
  constant + `deliver_one()` signature + `dispatch_due()` threading.
- `src/TelegramDispatch/DispatchOutboxRepository.php` — drop `claim_one()`.
- `src/TelegramDispatch/DispatchEnqueuer.php` — drop the immediate dependency; `kick()` →
  `DispatchWorker::request_immediate_run()`, wrapped.
- `src/TelegramDispatch/DispatchWorker.php` — add `IMMEDIATE_HOOK`, bind it to `run()`, add
  `request_immediate_run()`, and make `unschedule()` clear both hooks.
- `src/Core/Plugin.php` — drop the `set_immediate_dispatch()` line.

## 6. Security / privacy

Unchanged from ADR-0014 §"Security and privacy impact": fixed server-derived class, no plaintext
in outbox / audit / logs / diagnostics / queue metadata, same signed paired transport used only
by the worker, `suppressed` rows never delivered. The `spawn_cron()` loopback carries only
WordPress's own cron key, never plugin data.

## 7. Tests (v2)

Remove the v1 `attempt_now` / `claim_one` tests. Add / revise:

- `DispatchWiringTest` — for **both** an existing binding and a new conversation, a real visitor
  REST POST and a real Hub reply: (a) commit the message + outbox row; (b) make **no**
  `api.telegram.org` request during the request (assert via a `pre_http_request` spy); (c)
  create **no** Universal Telegram binding during the request; (d) still return `ok` when the
  kick seam throws (inject a throwing `request_immediate_run`); (e) disabled ⇒ no outbox row,
  no kick; (f) a Telegram-originated `ingest_operator_reply` stays `suppressed` and never
  triggers a kick-delivery.
- `TelegramDispatchServiceTest` — worker path unchanged; explicit "new conversation:
  `dispatch_due()` creates the binding + topic and delivers as `interactive_chat`, converging
  with no duplicate on retry".
- `DispatchWorkerTest` (new) — **with the recurring `HOOK` event already scheduled (the normal
  deployed state)**: `request_immediate_run()` still schedules exactly one **due**
  `IMMEDIATE_HOOK` event; repeated kicks do not stack immediate events; the immediate event
  invokes the same `run()` / `dispatch_due()`; it is non-throwing when `spawn_cron` raises/errors
  or scheduling is refused, leaving the committed outbox row recoverable; `unschedule()` clears
  **both** the recurring and the immediate hook.
- Interop (`TelegramDispatchInteropTest`, both WP/PHP variants, real signed Contract v1 against
  the UT ADR-0045 branch):
  - a real visitor REST POST on a **new** conversation makes **zero** `api.telegram.org`
    calls and creates **no** UT binding in the request; then the worker (`dispatch_due()`)
    creates the topic/binding and delivers exactly one `interactive_chat` transport row;
  - same for a pre-**bound** conversation — expedited `interactive_chat` via the worker only;
  - message + outbox commit succeed when the async kick fails;
  - a failed first worker attempt converges on the next sweep with no duplicate;
  - an ordinary `standard` Universal Telegram delivery is not promoted;
  - a Telegram-originated reply is never echoed back.

Full gate: PHPCS, PHPStan, unit, integration (WP 7.1/PHP 8.3 + WP 6.9/PHP 8.1), full interop
suite both variants, `check-doc-links`, GitHub Actions.

## 8. Out of scope

As v1 §10, plus: any in-request delivery attempt; adding Action Scheduler to Support Chat; any
change to `spawn_cron()` itself.

## 9. Definition of done

ADR-0014 Amendment 1 + this plan v2 on the implementation branch; all §7 tests green; full gate
green both variants; real dual-plugin interop green both variants proving **no Telegram I/O in
the visitor/Hub request** for both new and existing-bound conversations, and asynchronous-worker
convergence with the unchanged idempotency/retry guarantees; implementation PR updated (not
merged).
