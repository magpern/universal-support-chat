# Closure — ADR-0014: Interactive delivery class and fully asynchronous expedited dispatch

## Status

**Complete and merged.** Documentation-only closure record. No runtime, DEV, production,
Telegram, settings, credential, pairing, release, or tag action.

## Implementation PRs and merge SHAs

| Repo | Freeze PR | Freeze merge SHA | Implementation PR | Implementation merge SHA |
|---|---|---|---|---|
| universal-support-chat | [#38](https://github.com/magpern/universal-support-chat/pull/38) | `530e84ad94593d00444921173315b11ee5870201` | [#39](https://github.com/magpern/universal-support-chat/pull/39) | **`4bf012a0edba96d1fd66aa187b908154f867b624`** |
| universal-telegram (counterpart, ADR-0045) | [#63](https://github.com/magpern/universal-telegram/pull/63) | `6d02aae2fab2648b78e78fdc55cc4a4572550cf1` | [#64](https://github.com/magpern/universal-telegram/pull/64) | `9b4a6ef2bfc56b4bb514567c797d41c8a285727a` |

Merge order followed: Universal Telegram #64 first; then this repo's interop CI checkout was
re-pinned from the UT branch SHA to Universal Telegram's merged `main` `9b4a6ef…`, SC CI re-run
fully green (including both WP/PHP interop variants against merged UT `main`), and PR #39 merged.

## What shipped

- **`AdapterContractClient::deliver_message()`** gains an optional trailing `delivery_class`
  (default `standard`), always sent on the wire, **not** part of the idempotency key
  (`IdempotencyKeys::for_message_delivery( $message_uuid )` unchanged). Sent **only by the
  worker**. Wire-compatible with a pre-ADR-0045 Universal Telegram (unknown field ignored).
- **`TelegramDispatchService`** — `DELIVERY_CLASS_INTERACTIVE = 'interactive_chat'` constant;
  `deliver_one( $record, $class = interactive )` threads the class into the worker's
  `deliver_message` call; `dispatch_due()` (the WP-Cron sweep) is the **only** place
  `ensure_channel_case`, `notify_operators`, and `deliver_message` are ever called. There is no
  in-request delivery code.
- **`DispatchEnqueuer`** — after the atomic message + content-free outbox `COMMIT` for a
  mirrored direction, calls **only** `DispatchWorker::request_immediate_run()`. No
  `?TelegramDispatchService` dependency; the first-draft `attempt_now()` /
  `DispatchOutboxRepository::claim_one()` were removed. Disabled or non-mirrored ⇒ no
  transaction, no outbox row, no kick.
- **`DispatchWorker`** —
  - `HOOK` (`universal_support_chat_telegram_dispatch_run`), the recurring **60-second recovery
    sweep**, unchanged.
  - **new** `IMMEDIATE_HOOK` (`universal_support_chat_telegram_dispatch_immediate`), a one-off
    hook **distinct** from `HOOK`. `register()` binds **both** to the identical `run()` callback.
    Distinct because the recurring event is normally always scheduled on `HOOK`, so guarding an
    expedite one-off on `wp_next_scheduled( HOOK )` would never fire it in the deployed state.
  - **new** static `request_immediate_run()` — non-throwing across its whole boundary
    (`try { … } catch ( \Throwable )`): schedules `IMMEDIATE_HOOK` due now **only if none is
    pending** (the `wp_next_scheduled` guard plus `wp_schedule_single_event`'s own 10-minute
    same-hook de-dup collapse repeated kicks to **at most one** pending immediate event), then
    calls WordPress core's non-blocking `spawn_cron()` (a `wp_remote_post` with
    `blocking => false`, `timeout => 0.01`; works under `DISABLE_WP_CRON`).
  - `unschedule()` now clears **both** hooks. `Deactivator` / `Uninstaller` already call it —
    no change there.
- **`Core\Plugin`** — no immediate-dispatch wiring; enqueuer and worker constructed as before.

**No** schema / `db_version` / version / route / setting / menu / dependency change.
`telegram_dispatch_enabled` remains the sole opt-in.

## The final invariant

A visitor or Hub request, on the enabled `interactive_chat` path:

1. atomically persists the Support Chat conversation message and the content-free outbox row in
   one transaction (unchanged ADR-0012 guarantee);
2. requests **one** non-blocking asynchronous WP-Cron run (schedule `IMMEDIATE_HOOK` + call
   `spawn_cron()`), non-throwing;
3. makes **no** Contract v1 call and **no** Telegram API call, and its HTTP response **does not
   depend on** Universal Telegram / Telegram completion, availability, or timeout.

All topic creation (including new-conversation `createForumTopic` on the Universal Telegram
side), operator notification, and message delivery run **only** in
`DispatchWorker` → `TelegramDispatchService::dispatch_due()`. A new conversation converges the
same way an existing binding does — the worker creates the topic + binding, then delivers.

### Immediate-hook behaviour

- Separate from the recurring hook; routes to the identical `run()`.
- At most one pending immediate event (guard + `wp_schedule_single_event` de-dup); repeated
  kicks under load collapse.
- Due now (`time()`); the recurring hook keeps its 60-second interval.
- Cleared alongside the recurring hook on deactivate / uninstall.
- A failure of scheduling or the `spawn_cron()` loopback is swallowed; the committed outbox row
  stays recoverable and the recurring 60-second sweep still delivers it; the visitor / Hub
  response is unaffected.

### Recovery sweep

`DispatchWorker::HOOK` remains the recurring 60-second safety-net sweep and the guarantee of
eventual delivery, with the unchanged lease / reclaim / capped-backoff retry
(`{60,120,300,900,1800,3600}` s).

### Idempotency

Exactly one Telegram delivery per Support Chat message: the message-UUID idempotency key
(`IdempotencyKeys::for_message_delivery`) is unchanged and used by every worker attempt;
Universal Telegram's `DeliveryIdempotencyRepository` dedupes on accept. `delivery_class` is
never part of any idempotency key, so a retry that specifies (or corrects) the class still
converges to the one delivery. Loop prevention unchanged: a Telegram-originated
`ingest_operator_reply` gets a permanent `origin=telegram`, `state=suppressed` outbox marker,
which the worker's `claim_due()` can never select.

### `interactive_chat` vs `standard`

The worker labels every ADR-0012 mirror send (visitor messages, Hub operator replies)
`interactive_chat` — a code constant, never user-controlled, never from message text, no
selector. Universal Telegram places `interactive_chat` `deliver_message` jobs ahead of
`standard` work in its Action Scheduler queue (FIFO within each class) and fires its ADR-0023
expedited trigger for them. Everything else — diagnostics, generic alerts, transcript
backfill — is `standard` and unaffected.

## CI and real dual-plugin interop evidence

**Support Chat PR #39 CI (final run, interop re-pinned to merged UT `main` `9b4a6ef…`)** — all
20 checks green: PHPCS, PHPStan L5, unit ×3 PHP, integration WordPress-only floor (WP 6.9 /
PHP 8.1) + current (WP 7.1 / PHP 8.3), **interop (6.9, 8.1)** and **interop (7.1, 8.3)**,
check-doc-links.

**Real dual-plugin interop against Universal Telegram's actual merged `main` (`9b4a6ef…`)** —
`TelegramDispatchInteropTest`, real two-way Ed25519 pairing, real signed Contract v1:

| Variant | Result |
|---|---|
| WordPress 6.9 / PHP 8.1 | `OK (10 tests, 126 assertions)` |
| WordPress 7.1 / PHP 8.3 | `OK (10 tests, 126 assertions)` |

Interop coverage: a new-conversation visitor message, a Hub reply, and a message on an
already-bound conversation each make **zero** `api.telegram.org` calls and create **no**
Universal Telegram binding during the originating request; the worker (`dispatch_due()`) then
creates the topic / binding and delivers exactly **one** `interactive_chat` transport row each;
a failed first sweep converges on the next with **no duplicate**; message + outbox commit
**survive a failing async kick**; an ordinary `standard` Universal Telegram delivery is **not**
promoted; a Telegram-originated reply is never echoed back.

Also `DispatchWorkerTest` (with the recurring hook already scheduled — the normal deployed
state): `request_immediate_run()` schedules exactly one due `IMMEDIATE_HOOK` event; repeated
kicks do not stack; `IMMEDIATE_HOOK` runs the same `run()`; non-throwing when the loopback
errors / raises / scheduling is refused; `unschedule()` and `Deactivator::deactivate()` clear
both hooks.

## Documents

- [ADR-0014](../adr/0014-interactive-chat-delivery-class-and-immediate-dispatch.md) (Proposed +
  **Amendment 1 Accepted** — fully asynchronous expedited dispatch).
- `docs/plans/sc-interactive-telegram-dispatch-plan-v2.md` (supersedes v1, retained unchanged).
- Counterpart: Universal Telegram ADR-0045 + Amendment 1;
  `docs/closure/ut-adr-0045-interactive-priority-transport-closure.md` in `universal-telegram`.

## Non-authorization

This closure authorizes nothing operational. No DEV or production deployment or test; no real
Telegram message, webhook, bot, group, topic, destination, pairing, or credential change; no
settings change; no route switch, migration/cutover, release, tag, or database purge.
