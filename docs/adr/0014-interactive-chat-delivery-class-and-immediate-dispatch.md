# ADR-0014: Interactive delivery class for Support Chat → Telegram, and a bounded immediate dispatch attempt

## Status

**Proposed** — documentation freeze. Extends ADR-0005 §4.4 (`deliver_message`) and ADR-0012
(automatic dispatch outbox). No new Contract operation, no new REST route, no new authentication
mechanism, no shared database, no `universal_support_chat_db_version` change, no plugin version
change. Implementation is authorized only from the merged freeze baseline.

## Context

ADR-0012 gave Support Chat a durable, content-free outbox that mirrors visitor messages and Hub
operator replies to the linked Telegram forum topic. Today the only thing that moves a committed
outbox row toward Telegram is the WP-Cron worker (`DispatchWorker`, 60 s recurring sweep + a
one-off `wp_schedule_single_event` kick). On a site with `DISABLE_WP_CRON` and a system cron
cadence of a few minutes (the norm), a website-chat message can wait minutes before its outbound
send even begins. For an interactive support conversation that is too slow.

Universal Telegram is transport/adapter-only (its ADR-0044). Its `deliver_message` Contract
handler is already asynchronous — it writes an encrypted outbound row and enqueues an Action
Scheduler job, then returns; the Telegram API call happens later in Universal Telegram's own
queue worker. So the latency that matters is (a) how fast Support Chat asks Universal Telegram to
deliver, and (b) where Universal Telegram's queue then places that job. This ADR addresses (a)
and defines the class Support Chat uses to let Universal Telegram address (b) — Universal
Telegram's own ADR-0045 covers the queue side.

Diagnostics, generic alerts, transcript backfill, and any other non-chat traffic must keep
flowing through the ordinary path with no priority.

## Decision

### 1. A fixed, server-derived delivery class

Support Chat labels every ADR-0012 mirror send with one fixed delivery class:

```
interactive_chat
```

- It is a **constant in Support Chat's code**, applied by `TelegramDispatchService` on every
  `deliver_message` call it makes for an outbox row. It is **never** read from a request
  parameter, a setting, or message text, and there is **no** visitor-facing or operator-facing
  selector. The only messages Support Chat mirrors through ADR-0012 are normal visitor messages
  and Hub operator replies, so every ADR-0012 send is `interactive_chat` by construction.
- The absence of a class on the wire means `standard` — the behaviour every existing caller
  (transcript backfill, any future `deliver_message` caller, Universal Telegram's own
  diagnostics/alerts) already has. `standard` is not new behaviour; it is the current behaviour
  named.

### 2. Contract v1 `deliver_message` — narrow, compatible extension

`deliver_message` (ADR-0005 §4.4) gains one optional request-body field:

| Field | Type | Default when absent | Meaning |
|---|---|---|---|
| `delivery_class` | string | `standard` | Server-derived transport class. Fixed vocabulary: `standard`, `interactive_chat`. |

- **Compatibility:** an existing caller that never sends the field is unchanged; the receiver
  treats absent as `standard`.
- **Fail closed:** a present value that is not a string, is empty, or is not in the fixed
  vocabulary is rejected `400` with a stable reason (`invalid_delivery_class`). Never silently
  coerced to `standard`, never guessed.
- The field is **not** part of the idempotency key (ADR-0005 §6). `deliver_message` stays keyed
  on `message_uuid` alone (`IdempotencyKeys::for_message_delivery`), so a retry that
  re-specifies the class — or specifies a different one — still deduplicates to the one delivery.
- No other operation gains the field. `ensure_channel_case`, `notify_operators`,
  `deliver_transcript_backfill` are unchanged.

### 3. Durable outbox first, then one bounded immediate attempt

The ADR-0012 guarantee is unchanged and comes first: for every mirror candidate,
`DispatchEnqueuer::persist_and_enqueue()` commits the encrypted message row and the content-free
outbox row **in one transaction** before any transport attempt. Nothing here weakens that.

**After that commit**, on the `interactive_chat` path (dispatch enabled, mirrored direction),
Support Chat makes exactly **one** bounded, best-effort immediate dispatch attempt in the same
request, before returning the visitor/Hub response:

- It claims the just-committed row with the existing lease protocol
  (`DispatchOutboxRepository`, single-row claim keyed on `message_uuid`; `state IN
  ('pending','failed')` guard; stamps `claimed_at` / `lease_expires_at`). If the row cannot be
  claimed (already `delivering`, `delivered`, `suppressed`, or gone) the immediate attempt is a
  no-op.
- It runs the identical delivery routine the worker runs (`ensure_channel_case` →
  `deliver_message` with `delivery_class = interactive_chat`, same message-UUID idempotency key),
  wrapped so that **any** exception, timeout, unavailable/unpaired/disabled adapter, or transport
  failure is caught, leaves the row `failed` with a short next-attempt time, and never propagates
  to the caller.
- **The website response never waits on retries.** It waits only on this single attempt. The
  attempt's cost is one in-process signed Contract call (`InProcessContractTransport` →
  `rest_do_request()`, no HTTP hop) plus a few local writes. Universal Telegram's
  `deliver_message` handler is asynchronous (row + Action Scheduler enqueue, no synchronous
  Telegram API call), so this attempt does **not** block on the Telegram network. **There is no
  synchronous Telegram API dependency for the website response.**
- The existing WP-Cron worker (`DispatchWorker`) and its lease / reclaim / capped-backoff retry
  path are retained **unchanged** as the recovery mechanism and the guarantee of eventual
  delivery. A failed immediate attempt is picked up by the worker exactly as a failed worker
  attempt is.

### 4. Latency trade-off (documented, accepted)

The enabled `interactive_chat` write path (visitor REST `handle_post_message`, Hub
`handle_reply`) does one extra in-process Contract call before it returns. That call is bounded
to a single attempt and touches no external network. When dispatch is disabled — the default —
nothing changes: no transaction, no outbox row, no immediate attempt.

### 5. Loop prevention unchanged

Telegram-originated operator replies (`ingest_operator_reply`) still receive the permanent
`origin=telegram`, `state=suppressed` outbox marker. The immediate-attempt path only ever runs
for a row it can claim in `pending`/`failed`; a `suppressed` row is structurally unreachable, so
a reply that arrived from Telegram is never mirrored back out — via the worker or the immediate
path.

## Alternatives

- **Lower the WP-Cron interval / rely on a tighter kick only.** Rejected: on a `DISABLE_WP_CRON`
  site the real cadence is the system cron's, which the plugin does not control; the one-off
  `wp_schedule_single_event` kick still only runs on the next system-cron tick. Universal
  Telegram's own ADR-0023 history records the same finding (a fire-and-forget kick alone left a
  real message 33 s behind).
- **Deliver synchronously to Telegram from the write path.** Rejected: violates ADR-0006 (a
  slow/down transport must never slow or fail a visitor message) and the explicit exclusion in
  this work — no synchronous Telegram API dependency for the website response.
- **A post-response / shutdown-hook background send in Support Chat.** Considered. Support Chat
  has no existing post-response execution pattern, `fastcgi_finish_request` is not universally
  available, and a shutdown-hook send is itself unbounded and hard to test deterministically.
  The bounded in-request immediate attempt is simpler, deterministic, and — because Universal
  Telegram's `deliver_message` is asynchronous — cheap. Documented as the chosen trade-off (§4).
- **Put the class in the idempotency key.** Rejected: would let a retry with a corrected class
  double-deliver. The class is transport metadata, not message identity.
- **A second Contract operation `deliver_message_interactive`.** Rejected: needless surface;
  a single optional, fail-closed field on the existing signed operation is the minimal change.
- **A new outbox column for the class.** Rejected for Support Chat: every ADR-0012 outbox row is
  `interactive_chat` by construction, so the class is a code constant, not per-row state. (The
  fixed value **is** persisted on the Universal Telegram side — see its ADR-0045 — because that
  queue carries both classes.)

## Consequences

- Enabled interactive path gains one bounded in-process Contract call per mirrored message; the
  visitor/Hub response is otherwise unchanged and never blocks on retries or the Telegram
  network.
- A committed message still cannot be lost: outbox-first commit, then the immediate attempt is
  pure optimisation over the unchanged durable worker.
- Exactly one Telegram delivery per Support Chat message: the message-UUID idempotency key is
  unchanged and shared by the immediate attempt and the worker; Universal Telegram dedupes on
  accept.
- `standard` traffic (backfill, diagnostics, alerts) is untouched — same wire, same path, same
  priority.
- Diagnostics: `DispatchOutboxRepository::count_by_state()` and the `telegram_dispatch.swept`
  audit line are unchanged; no plaintext is added anywhere. A new fixed audit code may record
  that an immediate attempt ran and its non-content outcome class.

## Security and privacy impact

- `delivery_class` is a fixed-vocabulary, server-derived string — never content-derived, never
  user-supplied. It carries no information about the message.
- No plaintext is added to the outbox, audit context, queue metadata, logs, or diagnostics. The
  immediate attempt handles message plaintext exactly as the worker does: in memory only, for
  the duration of the `deliver_message` call, never audited.
- The immediate attempt uses the same ADR-0007 signed, paired transport as the worker; no new
  trust path, no unauthenticated trigger.

## Affected Documents/Milestones

- ADR-0005 §4.4 (extended: optional `delivery_class`), ADR-0005 §6 (unchanged: key is still
  `message_uuid`).
- ADR-0012 (extended: the bounded immediate attempt after the atomic commit).
- Universal Telegram ADR-0045 — the queue-priority counterpart; this ADR provides the class it
  consumes.
- `docs/plans/sc-interactive-telegram-dispatch-plan-v1.md`.

## Compatibility/Migration Impact

- No schema change, no `db_version` change, no version bump.
- Forward and backward compatible on the wire: absent `delivery_class` == `standard` ==
  today's behaviour, in both directions.
- A Support Chat build with this change talking to a Universal Telegram build without ADR-0045
  still works: Universal Telegram ignores the unknown field and delivers at ordinary priority.
- The `telegram_dispatch_enabled` flag remains the sole Support Chat opt-in. Disabled == no
  change at all.

## Exact exclusions

- No DEV or production deployment or test; no real Telegram message, webhook, bot, group, topic,
  destination, pairing, or credential change; no route switch, migration, release, tag, or
  database purge.
- No new REST route; no new authentication mechanism; no shared database; no direct cross-plugin
  SQL; no copied code; no direct Telegram API call from Support Chat.
- No visitor-facing or operator-facing priority selector; no new settings page; no removal of
  existing diagnostics or alerts.
