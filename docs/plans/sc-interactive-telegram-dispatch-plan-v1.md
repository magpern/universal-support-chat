# Plan: Low-latency interactive Support Chat → Telegram dispatch (v1)

## 1. Charter and ADRs

Realises [ADR-0014](../adr/0014-interactive-chat-delivery-class-and-immediate-dispatch.md):
a fixed, server-derived `interactive_chat` delivery class on ADR-0012 mirror sends, a narrow
compatible extension of Contract v1 `deliver_message`, and one bounded immediate dispatch
attempt after the atomic outbox commit. Counterpart: Universal Telegram ADR-0045 /
`ut-interactive-chat-delivery-priority-plan-v1.md` (queue priority). Freeze-first: implementation
begins only from the merged freeze commit of this plan + ADR-0014.

## 2. Repository findings at drafting time

- `origin/main` @ `5d40966944599b95fd3efb8d24c3f74ec33a2a80`.
- `DispatchEnqueuer::persist_and_enqueue()` — atomic message + outbox commit when
  `telegram_dispatch_enabled`; then `kick()` = `wp_schedule_single_event( time(), DispatchWorker::HOOK )`.
- `TelegramDispatchService::dispatch_due()` / `deliver_one()` — the worker delivery routine:
  `ensure_channel_case` → optional `notify_operators` on `created` → `deliver_message`
  (`IdempotencyKeys::for_message_delivery( $message_uuid )`); capped backoff `{60,120,300,900,1800,3600}`;
  `invalid_input` ⇒ abandoned, else retryable `failed`.
- `DispatchOutboxRepository` — `enqueue`, `claim_due(limit)`, `reclaim_expired_leases()`,
  `find`, `find_by_id`, `mark_delivered/failed/abandoned`, `record_channel_case_ref`,
  `count_by_state`. Lease = 300 s. **No single-message claim method today.**
- `AdapterContractClient::deliver_message( peer, ref, message_uuid, body, attribution )` — builds
  the signed request body `{channel_case_ref, idempotency_key, body, attribution}`.
- No existing post-response / shutdown background-execution pattern in Support Chat.
- `DispatchWorker` — 60 s recurring `cron_schedules` sweep + `HOOK` action; batch 25.

## 3. Assumptions and open questions

| # | Assumption | Handling |
|---|---|---|
| A1 | Universal Telegram's `deliver_message` handler stays asynchronous (row + Action Scheduler enqueue, no synchronous Telegram send). | Verified against UT `1af1cf3`. The bounded immediate attempt relies on this for its latency bound; documented in ADR-0014 §3–4. |
| A2 | `InProcessContractTransport` (`rest_do_request()`) is the only transport today; it is in-process and fast. | Verified. If a genuinely-remote transport is added later, ADR-0014 §4's bound becomes "one attempt with that transport's own timeout" — still one attempt, still caught. |
| A3 | Every ADR-0012 outbox row is a mirror candidate that is `interactive_chat`. | True by construction (`is_mirrored_direction` = visitor/operator only). |

## 4. Architectural decisions

1. **`TelegramDispatchService::DELIVERY_CLASS_INTERACTIVE = 'interactive_chat'`** — a class
   constant, applied to every `deliver_message` call the service makes. `DELIVERY_CLASS_STANDARD
   = 'standard'` documents the default.
2. **`AdapterContractClient::deliver_message()` gains a trailing optional
   `string $delivery_class = 'standard'`.** Always sends `delivery_class` in the request body
   (default `standard` ⇒ wire-compatible: an ADR-0045-less Universal Telegram ignores it). Not
   part of the idempotency key.
3. **`DispatchOutboxRepository::claim_one( string $message_uuid ): ?DispatchRecord`** — the
   single-row analogue of `claim_due`: `reclaim_expired_leases()` first, then a guarded
   `UPDATE ... SET state='delivering', attempts=attempts+1, claimed_at=NOW(), lease_expires_at=...
   WHERE message_uuid=%s AND state IN ('pending','failed') AND next_attempt_at <= NOW()`; returns
   the hydrated record on `rows_affected > 0`, else `null`.
4. **`TelegramDispatchService::attempt_now( string $message_uuid ): void`** — `is_enabled()`
   guard; `claim_one()`; on a claimed row run the existing `deliver_one()` logic threaded with
   `DELIVERY_CLASS_INTERACTIVE`; wrap the whole body in `try { ... } catch ( \Throwable ) {
   $outbox->mark_failed( $id, 'immediate_attempt_error', $shortBackoff ); }`; never rethrow.
   Emits one fixed audit code `telegram_dispatch.immediate` with a non-content outcome
   (`delivered` | `deferred`), `Classification::PUBLIC` fields only.
   - `deliver_one()` is refactored to accept the delivery class and to be callable for a single
     record from both `dispatch_due()` and `attempt_now()`.
5. **`DispatchEnqueuer::persist_and_enqueue()`** — after `COMMIT`, when the message is a mirrored
   direction: call `$this->immediate?->attempt_now( $message->uuid() )` **then** `kick()`
   (unchanged). `$immediate` is an optional constructor dependency
   (`?TelegramDispatchService`), so existing construction/tests keep working with the immediate
   path simply inert.
6. **No schema change, no `db_version` change, no version bump.** No new setting, route, menu, or
   dependency.

## 5. Directory / namespace / schema / API impact

- Edited: `src/ChannelContract/Outbound/AdapterContractClient.php`,
  `src/TelegramDispatch/{TelegramDispatchService,DispatchEnqueuer,DispatchOutboxRepository}.php`,
  `src/Core/Plugin.php` (inject the service into the enqueuer).
- Schema: none.
- API: `deliver_message` request body gains optional `delivery_class` (ADR-0014 §2). No route
  change.

## 6. Security and privacy impact

Per ADR-0014 §"Security and privacy impact": fixed-vocabulary server-derived class; no plaintext
added to outbox / audit / logs / diagnostics / queue metadata; same signed paired transport; no
unauthenticated trigger; loop-prevention (`suppressed`) unreachable from the immediate path.

## 7. Test and CI impact

New / extended (SC):

- `DispatchOutboxRepositoryTest` — `claim_one` claims only a `pending`/`failed` due row keyed on
  `message_uuid`, stamps the lease, is a no-op on `delivering`/`delivered`/`suppressed`; an
  expired lease is reclaimed then claimable.
- `TelegramDispatchServiceTest` — `attempt_now` delivers a claimed row with
  `delivery_class=interactive_chat`; a thrown transport error leaves the row `failed`/retryable
  and never propagates; a timed-out / unavailable adapter leaves the row retryable; a second
  `attempt_now` after `delivered` is a no-op; the worker (`dispatch_due`) still converges a row
  the immediate attempt failed, with no duplicate `deliver_message`.
- `DispatchWiringTest` — message + outbox still commit atomically; the immediate attempt runs
  **only after** commit and **only** for an enabled `interactive_chat` mirror direction; the
  visitor/Hub REST response is still `ok` when the immediate attempt throws/fails;
  Telegram-originated `ingest_operator_reply` stays `suppressed` and no immediate attempt runs.
- A recording `AdapterContractClient` test double asserts `deliver_message` received
  `delivery_class = interactive_chat` on the immediate and worker paths, `standard` (or absent)
  for backfill.
- No-plaintext assertions extended to the new audit code and `claim_one` SQL.

Interop (dual-plugin, both WP/PHP variants) — extends `TelegramDispatchInteropTest`:
real signed pairing; a visitor message and a Hub reply reach Universal Telegram as
`interactive_chat` via the immediate path, creating exactly one Universal Telegram delivery each;
a forced immediate-path failure converges through the worker with no duplicate; a
Telegram-originated reply is never sent back; an ordinary `standard` delivery is not promoted.

Full gate: PHPCS, PHPStan, unit, integration (WP 7.1/PHP 8.3 + WP 6.9/PHP 8.1), full interop
suite, `check-doc-links`, GitHub Actions.

## 8. Work packages (execution order, from the merged freeze)

1. `DispatchOutboxRepository::claim_one()` + tests.
2. `AdapterContractClient::deliver_message()` optional `delivery_class` + wire-body change + tests.
3. `TelegramDispatchService`: class constants, `deliver_one()` refactor to thread the class,
   `attempt_now()` + audit code + tests.
4. `DispatchEnqueuer` optional `?TelegramDispatchService` + post-commit `attempt_now()`; `Plugin`
   wiring + `DispatchWiringTest`.
5. Interop test extension; full gate both variants; PR (no merge).

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Immediate attempt throws and breaks the visitor response | Whole body in `try/catch \Throwable`; row left `failed`/retryable; response unaffected. Test `DispatchWiringTest`. |
| Immediate attempt + worker race → double `deliver_message` | Single-row lease claim (`claim_one`); message-UUID idempotency key on both sides; UT dedupes on accept. Test convergence. |
| A future remote transport makes the immediate attempt slow | Still one attempt, still caught; ADR-0014 §4 documents the bound follows the transport's own timeout. |
| `delivery_class` misread as content | Fixed 2-value vocabulary, server-derived constant, not in idempotency key, no plaintext. |

## 10. Out of scope

- Universal Telegram queue-priority mechanics (its ADR-0045 / plan).
- Any SC schema/`db_version`/version change; any settings UI; any new route or auth.
- Priority for `standard` traffic; removing or reclassifying diagnostics / alerts / backfill.
- DEV/production deployment or test; any real Telegram resource change.
- A post-response/shutdown background executor for Support Chat (explicitly considered and
  rejected in ADR-0014 Alternatives).

## 11. Definition of done

ADR-0014 + this plan merged as a code-free freeze; then, from that baseline: all §7 tests green;
full gate green both variants; real dual-plugin interop green both variants against the pinned
Universal Telegram implementation branch; implementation PR open (not merged); no excluded
change made.
