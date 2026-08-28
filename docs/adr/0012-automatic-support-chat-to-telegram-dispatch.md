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
| `claimed_at`, `lease_expires_at` | worker crash-recovery lease (§3) |

**No column is content-bearing.** The message body is read live from the encrypted
`conversation_messages` table (decrypted in memory only) at delivery time, exactly like a
`deliver_message` call already does. `verify_step_12` fails the migration if a
`body`/`body_ciphertext`/`plaintext`/`content_hash`/`digest`/`text` column ever appears.

### 2. The outbox row is written in the same transaction as the message

`DispatchEnqueuer::persist_and_enqueue()` is the seam the visitor REST path and the Hub reply
path call to persist a message. When dispatch is **enabled** it opens one explicit
transaction, runs the caller's message-create closure, inserts the outbox row, and commits
both together — or rolls both back. There is no window in which a committed message exists
with no outbox row to drive its mirror and its retry. If the outbox write genuinely fails the
message write is rolled back and the caller returns its ordinary retryable error (the visitor
or operator simply retries), exactly as it already does when the message write itself fails.
An idempotent re-POST whose outbox row already exists is **not** a failure and is not rolled
back.

When dispatch is **disabled** (the default) no transaction is opened and the message write is
byte-for-byte what it was before this feature.

`ContractOperationDispatcher::ingest_operator_reply()` calls `mark_telegram_origin()` — a
permanent `origin=telegram`, `state=suppressed` row so a reply that arrived *from* Telegram
can never be mirrored back out (loop prevention). This marker stays best-effort and idempotent:
it is defence-in-depth, since no code path ever enqueues an ingested message.

The enqueuer is injected as an **optional** constructor argument on all call sites, so existing
tests and any external construction keep working with the mirror simply inert.

### 3. Delivery worker (WP-Cron only), with a crash-recovery lease

`TelegramDispatchService::dispatch_due()` runs only from `DispatchWorker` — a recurring 60s
WP-Cron sweep plus the one-off kicks — never inside a visitor or Hub request.

Each sweep first **reclaims stale claims**: any row left in `delivering` past its
`lease_expires_at` (default 300s — comfortably longer than one delivery) is moved back to
`failed`/due-now with reason `lease_expired`. A worker that crashes after claiming a row —
including after Universal Telegram accepted the idempotent delivery but before Support Chat
recorded it — can therefore never strand the message: the row is reclaimed and re-delivered,
and Universal Telegram's message-UUID idempotency returns `reused`, converging cleanly.
`reclaim_expired_leases()` also runs when the feature is toggled off, so disabling dispatch
never strands an in-flight row.

Per claimed row:

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

Row claiming is a guarded `UPDATE … WHERE state IN ('pending','failed')` that also stamps
`claimed_at`/`lease_expires_at`, so overlapping sweeps cannot double-process, and
`suppressed`/`delivered`/`abandoned` rows are structurally unreachable from the worker.

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
- **A best-effort post-commit enqueue hook** (the first draft). Rejected on review: a crash in
  the gap between the message commit and the hook, or a failed enqueue, leaves a message with
  no outbox row and no retry. Replaced with the single-transaction write in §2.
- **A polling reconciler** that back-fills missing outbox rows by scanning recent messages.
  Rejected as the primary mechanism: it is eventually-consistent, needs its own cursor/tuning,
  and the single-transaction write removes the gap it would paper over. May still be added
  later as a cheap belt-and-braces sweep.
- **No `delivering` lease.** Rejected on review: a worker crash after claiming a row (worst
  case: after Universal Telegram accepted the delivery) would strand it in `delivering`
  forever. Added `claimed_at`/`lease_expires_at` + a reclaim pass (§3).

## Consequences

- One additive table; `db_version` 11 → 12. No existing table changes. Uninstall drops it.
- When dispatch is enabled, `handle_post_message` / `handle_reply` now run their message write
  inside a short transaction. A transient DB failure there returns the same retryable error the
  message write already returned on failure — the visitor/operator retries.
- A committed Support Chat message destined for Telegram is retried indefinitely (capped
  backoff) until delivered or provably undeliverable; delivery state is inspectable via
  `DispatchOutboxRepository::count_by_state()` and a `telegram_dispatch.swept` audit line.
- Exactly one Telegram delivery per Support Chat message, guaranteed by the message-UUID
  idempotency key on both sides, the outbox `state` machine, and the crash-recovery lease.
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
