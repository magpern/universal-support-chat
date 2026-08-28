# ADR-0012: Automatic Support Chat → Telegram message dispatch (SC-owned outbox)

## Status

**Accepted** — implemented on branch `feature/sc-telegram-adapter-dispatch`. Narrowly scoped
realisation of the Universal Telegram side of ADR-0044 (UT is transport/adapter only; Support
Chat is the sole owner of website chat). No change to Contract v1 (ADR-0005), to the
authentication profile (ADR-0007), or to `channel_case_ref` semantics (ADR-0011). Schema
advances `universal_support_chat_db_version` 11 → 12 (one additive table, no change to any
existing table). Plugin version unchanged in this ADR.

## Context

Before this change Support Chat stored conversations and messages but never delivered anything
to Telegram on its own:

- `AdapterContractClient` (the outbound Contract v1 client — `ensure_channel_case`,
  `notify_operators`, `deliver_transcript_backfill`, `deliver_message`) was wired in
  `Core\Plugin` "for future escalation/delivery call sites" and had **zero** callers.
- `HubActions::handle_reply()` wrote the operator message to Support Chat's own encrypted store
  and stopped there.
- The visitor REST path (`ConversationsController::handle_post_message`) likewise only stored
  the message.
- Replies arriving *from* Telegram already flow inbound through the real Contract v1
  `ingest_operator_reply` operation and Universal Telegram's `InboundAdapterBridge` /
  `OperatorIdentityMap`.

So a linked Telegram forum topic saw operator replies typed in Telegram, but never saw the
visitor's messages or replies an operator typed in the Support Chat Hub. This ADR closes that
gap with the minimum durable machinery.

## Decision

### 1. A Support-Chat-owned dispatch outbox

New table `universal_support_chat_telegram_dispatch` (migration step 12). One row per committed
conversation message that is a candidate for mirroring to Telegram:

| column | purpose |
|---|---|
| `message_uuid` (UNIQUE) | the Support Chat message being mirrored — the idempotency anchor |
| `conversation_id`, `conversation_uuid` | parent conversation; `conversation_uuid` is the adapter `channel_case_ref` (ADR-0011) |
| `direction` | `visitor` \| `operator` — drives the channel-facing attribution label |
| `origin` | `support_chat` \| `telegram` — loop-prevention marker |
| `state` | `pending` → `delivering` → `delivered` \| `failed` \| `abandoned` \| `suppressed` |
| `attempts`, `last_reason`, `next_attempt_at` | retry accounting |
| `channel_case_ref` | resolved adapter case ref, once known |

**No column is content-bearing.** The message body is read live from the encrypted
`conversation_messages` table (decrypted in memory only) at delivery time, exactly like a
`deliver_message` call already does. `verify_step_12` fails the migration if a
`body`/`body_ciphertext`/`plaintext`/`content_hash`/`digest`/`text` column ever appears.

Persisting the row **before** any transport attempt is what makes delivery survivable: a
committed Support Chat message is never lost because Universal Telegram is unpaired, disabled,
or unreachable — the row simply stays `pending`/`failed` and retryable.

### 2. Post-commit enqueue seam

`DispatchEnqueuer` is the single, deliberately non-throwing seam that the write paths call
**after** the message row is committed and the conversation status transition is done:

- `ConversationsController::handle_post_message()` → `enqueue_message()` for the visitor message.
- `HubActions::handle_reply()` → `enqueue_message()` for the operator reply.
- `ContractOperationDispatcher::ingest_operator_reply()` → `mark_telegram_origin()` — writes a
  permanent `origin=telegram`, `state=suppressed` row so a reply that arrived *from* Telegram
  can never be mirrored back out (loop prevention, requirement 5). This marker is written
  regardless of the feature flag.

The enqueuer is injected as an **optional** constructor argument on all three call sites, so
existing tests and any external construction keep working with the mirror simply inert.

`enqueue_message()` is a no-op when the feature flag is off, when a row for the message already
exists, or for a direction that is never mirrored. It also schedules an immediate one-off
WP-Cron kick so latency stays low.

### 3. Delivery worker (WP-Cron only)

`TelegramDispatchService::dispatch_due()` runs only from `DispatchWorker` — a recurring 60s
WP-Cron sweep plus the one-off kicks — never inside a visitor or Hub request. Per claimed row:

1. Load the message; if it is gone or its body was retention-nulled → `abandoned` (a retry
   cannot recover it).
2. `ensure_channel_case(peer, conversation_uuid, 'support_chat_dispatch')` — resolve or create
   the Telegram forum-topic binding. On failure → `failed` with capped exponential backoff
   (60s … 1h), never abandoned: transient/unavailable is always retried.
3. If ensure reported `created` (a brand-new topic), best-effort `notify_operators` — a notify
   failure never blocks the delivery.
4. `deliver_message(peer, ref, message_uuid, body, attribution)` using the SC-owned stable
   `IdempotencyKeys::for_message_delivery($message_uuid)`. Universal Telegram's own
   `DeliveryIdempotencyRepository` dedupes, so a retry can **never** duplicate a Telegram
   delivery. `invalid_input` → `abandoned`; any other failure → retryable `failed`.

Row claiming is a guarded `UPDATE … WHERE state IN ('pending','failed')` so overlapping sweeps
cannot double-process, and `suppressed`/`delivered`/`abandoned` rows are structurally
unreachable from the worker.

### 4. Opt-in flag

`Settings` gains `telegram_dispatch_enabled` (default **false**). With it off — the state for
any site that has not deliberately turned this on — nothing is enqueued and behaviour is
byte-for-byte unchanged.

## Alternatives

- **Deliver synchronously from the write path.** Rejected: a slow or down Telegram would slow
  or fail a visitor's message send, and Support Chat must stay the system of record
  independent of the transport (ADR-0006).
- **Reuse the SC-M03 `legacy_handoff_map` table.** Rejected: that table is provenance for
  cutover replay (bot_id/update_id keyed), a different lifecycle, and ADR-0044 retired that
  track.
- **Mark loop-origin on the `conversation_messages` row itself** (a new column). Rejected:
  keeps the messages table free of channel concerns (its `verify_step_3` forbids exactly this)
  and the outbox row is the natural place for delivery state anyway.
- **Action Scheduler for the worker.** Rejected: Support Chat has no Action Scheduler
  dependency; `RetentionCleanupHandler` / `NonceCleanupHandler` already establish the WP-Cron
  pattern used here.

## Consequences

- One additive table; `db_version` 11 → 12. No existing table changes. Uninstall drops it.
- A committed Support Chat message destined for Telegram is retried indefinitely (capped
  backoff) until delivered or provably undeliverable; delivery state is inspectable via
  `DispatchOutboxRepository::count_by_state()` and a `telegram_dispatch.swept` audit line.
- Exactly one Telegram delivery per Support Chat message, guaranteed by the message-UUID
  idempotency key on both sides plus the outbox `state` machine.
- Telegram-originated replies are structurally prevented from looping back out.
- Retention purge also clears the conversation's outbox rows (no orphans).
- No new REST route; no direct Universal Telegram SQL; no shared database; no copied UT code —
  every cross-plugin call is the existing signed Contract v1 path.

## Security and privacy impact

- The outbox table stores only ids, uuids, fixed-vocabulary strings, counts and timestamps —
  never message content or any secret. Enforced by `verify_step_12`.
- Message plaintext exists only in memory for the duration of a `deliver_message` call, as it
  already did for the inbound contract path; it is never written to audit context.
- The bot token, webhook secret, pairing keys and ciphertext are untouched — delivery goes
  through Universal Telegram's existing encrypted transport queue.
- Feature is opt-in; a site that does not enable it is unaffected.

## Affected Documents/Milestones

- `docs/plans/sc-telegram-adapter-dispatch-plan-v1.md` (this feature's plan).
- `docs/milestones/sc-m04-telegram-optional-acceptance.md` — realises the SC-owned-delivery
  half of the ADR-0044 end state.
- Universal Telegram ADR-0044 (transport/adapter-only) — the dependency this builds on;
  merged UT `main` `1af1cf3d9011060cb9244adfd93cfa916acfbdc6`.

## Compatibility/Migration Impact

- Forward-only additive migration (step 12); safe to run on any site at `db_version` ≥ 1.
- No Contract v1, ADR-0007, or ADR-0011 change — Universal Telegram needs no change to accept
  this traffic (it is ordinary `ensure_channel_case` + `deliver_message`).
- Downgrade leaves an unused table; re-upgrade is a no-op.
