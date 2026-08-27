# ADR-0010 — Final Cutover: Handoff Contract, Cohort Activation, and Incident Ownership

## Status

Accepted

## Context

SC-M03 work packages 2 (quiescence, Universal Telegram ADR-0040, closed and Product Owner accepted — `docs/closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md`) and 5 (binding preparation, this repository's own ADR-0009 and Universal Telegram's ADR-0041, closed and Product Owner accepted — `docs/closure/sc-m03-work-package-5-legacy-binding-preparation-closure.md`) both name, and explicitly defer, the same future problem: how a legacy Universal Telegram Telegram-topic conversation is finally, safely moved to routing through its already-prepared Support Chat binding, including what happens to Telegram traffic that arrived while the system was quiesced. ADR-0040's own Consequences section states this precisely: *"A future cutover work package (undesigned, out of scope here) will need its own handoff design for buffered updates arriving during a cutover-adjacent quiescence window, applying them into Support Chat's already-migrated data rather than replaying into a Universal Telegram legacy store being retired."* ADR-0009 §"What WP5 explicitly is not" names the corresponding activation problem: *"Activation — the explicit, later transition from prepared to active — is not part of WP5... this requires ... its own dedicated, explicit operator action ... never an implicit side effect of binding existence."*

This ADR is that future work. It freezes the final-cutover design across both repositories: cohort-based, all-or-nothing binding activation under Universal Telegram's existing quiescence lock; a cohort-aware deferred-update replay barrier that never lets an activated topic's buffered traffic reach Universal Telegram's legacy pipeline; a narrow, additive extension to Contract v1's existing operations (never a new operation, never a new route) carrying provenance sufficient for Support Chat to own a durable, transactional handoff record; and a strict ownership split between Support Chat's own handoff-map rows (written only on a genuinely successful domain-level Contract call) and Universal Telegram's own incident record (written only when no Support Chat call was ever attempted, or when Support Chat explicitly refused one).

This ADR **freezes design only**. It authorizes no implementation, no schema, no version bump, no branch, no production quiescence, cutover, route switch, soak, rollback, or deletion. Universal Telegram's companion ADR (ADR-0042, pinning this ADR's post-merge commit SHA, per this repository's own established two-repository gate pattern — ADR-0007/ADR-0038, ADR-0008/ADR-0039, ADR-0009/ADR-0041) freezes the Universal Telegram-owned half of this same design: the cutover state machine, `activate_prepared()`/`revert_activation()`'s corrected CAS semantics, the cohort-aware replay loop, the UT-owned incident record, and the `maybe_mark_topic_unavailable()` live-webhook cross-talk fix.

### Required source verification performed for this ADR

Every code-level claim below was verified directly against `origin/main` at the confirmed baselines — Universal Telegram `a761550f9e4c8b4422cb48dc23b0a6e82fdccbc5`, this repository `661f506e74b4a5e383b9a4859efc32d80ada43b5` — not assumed from prior planning text, which this ADR treats as superseded wherever it conflicts with what follows.

1. **`SupportChatContractClient::call()` dispatches every operation via `rest_do_request()`, in-process, same PHP request** (`universal-telegram` `src/SupportChatAdapter/Inbound/SupportChatContractClient.php:230-300`) — both plugins run in the same WordPress install; this is not a network hop.
2. **None of the six adapter → Support Chat operations this design reuses carry `bot_id`/`update_id`, and none of their handlers persist a queryable `(bot_id, update_id)`-keyed record today** (`src/ChannelContract/Rest/ContractOperationDispatcher.php:90-355`, all six handler bodies read directly).
3. **`ingest_operator_reply` is already genuinely idempotent**, via `MessageRepository::create()`'s pre-insert `find_by_idempotency_key( $conversation_id, $idempotency_key )` check (`src/Conversations/MessageRepository.php:66-82`), backed by `UNIQUE KEY conversation_idempotency (conversation_id, idempotency_key)` (`src/Persistence/Migrator.php:269-273`).
4. **`claim`/`release` are already genuinely idempotent by state-check**, not by any stored key: both `ConversationRepository::claim()`/`release()` (`src/Conversations/ConversationRepository.php:539-603`) return success-with-current-state when the caller's own retry would otherwise collide with the assignment it already holds.
5. **`resolve`/`reopen` are already genuinely idempotent by state-check**: both handler methods short-circuit with an early success return when the conversation is already in the target status (`src/ChannelContract/Rest/ContractOperationDispatcher.php:222-266`).
6. **`report_channel_unavailable` is already genuinely idempotent**, via `ChannelStatusRepository::mark_degraded()` → a private `upsert()` performing a real `INSERT ... ON DUPLICATE KEY UPDATE`, keyed by `UNIQUE KEY conversation_id (conversation_id)` (`src/ChannelContract/ChannelStatusRepository.php:110-134`, `src/Persistence/Migrator.php:496-504`). **A repeated identical call legitimately advances `updated_at` on every call — this is expected UPSERT behavior, not a correctness defect** (confirmed by reading `upsert()`'s own SQL: `updated_at = VALUES(updated_at)` applies unconditionally).
7. **Universal Telegram's `InboundAdapterBridge::try_handle()`'s sole routing gate is `$binding->is_active()`** (`universal-telegram` `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:71`), checked in `WebhookController::process_update()` before command dispatch and before legacy conversation routing (`universal-telegram` `src/Telegram/Inbound/WebhookController.php:216-229`) — unchanged by this ADR.
8. **`WebhookController::maybe_mark_topic_unavailable()` runs before the adapter-bridge check** (`universal-telegram` `src/Telegram/Inbound/WebhookController.php:212` vs. `216-219`), and matches via `ConversationRepository::find_by_bot_chat_thread()`, whose `WHERE` clause checks only `topic_creation_state = 'created'` — **never the legacy conversation's own open/closed/resolved status** (`universal-telegram` `src/Conversations/ConversationRepository.php:438-464`). A legacy conversation row therefore continues to match this lookup indefinitely after its topic's binding is activated, since nothing in WP5 or this design ever mutates `topic_creation_state`. This is the cross-talk this ADR §6 resolves.
9. **`ChannelBindingRepository::set_status()` has no CAS/version guard and no current-status precondition, and has zero production callers today** (`universal-telegram` `src/SupportChatAdapter/ChannelBindingRepository.php:182-206`; confirmed by repository-wide grep). It cannot be reused for activation as-is.

## Decision

### 1. Scope and safety boundary

This ADR authorizes only the future implementation of the final-cutover *engine* it describes, in a later, separately-approved implementation task, exactly as ADR-0008/ADR-0009 each authorized only their own engine's future implementation. It does not authorize:

- Any production cutover, quiescence, route switch, soak, or rollback operation.
- Any promise that traffic delivered to Support Chat after activation can be sent back to Universal Telegram. **Recovery from a failure discovered after activation is forward-only**: Universal Telegram's legacy source data, the SC-M03 migration map, and every audit/incident record this design produces are retained until a separately approved future retirement decision — never deleted as part of any recovery path this ADR defines.
- Any deletion, release, tag, deployment, or work on availability, AI, tickets, launcher, or unrelated UI surfaces.

### 2. Cohort activation — atomic per approved cohort, never partial

**Charter amendment (this same documentation freeze, additive to `docs/milestones/sc-m03-controlled-migration-and-cutover.md`, §0d):** the charter's existing "Partial cutover — forbidden; switch is atomic" principle is clarified, not weakened: **"atomic" means atomic per an explicit, operator-approved cohort — every member of a named, reviewed cohort activates and hands off together, or none do.** A cohort may be sized smaller than the full migrated dataset, at the Product Owner's explicit discretion per run; there is no "activate everything migrated" mode.

**Preflight (read-only, whole cohort, before any write):** every cohort member must be confirmed, in one pass, before any binding is touched: currently `prepared` in Universal Telegram; its Support Chat migration-map row is `status = 'migrated'`; its ownership/ownerless disposition is resolved per the existing SC-M03 work packages 3–4 Product Owner decision record (`docs/decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md`, referenced unedited); and it carries no blocking Universal Telegram-side incident (ADR-0042 §5). **If even one member fails preflight, the whole cohort is refused — no binding is touched, no cohort state advances.**

**Commit phase (Universal Telegram-owned, one CAS-guarded, lock-scoped transaction per candidate, under Universal Telegram's existing quiescence lock — `QuiescenceGate::with_quiescence_lock()`, unchanged):** candidates commit one at a time. **If any candidate's commit-time re-check fails despite preflight passing** (a genuine race, or quiescence lost mid-run), the run halts immediately and **compensates**: every candidate already committed `active` in this same run is reverted back to `status = 'prepared'` via a new, saga-internal-only method (frozen in ADR-0042 §2, this repository cross-references it, does not own it). **`cas_version` is strictly monotonic** — a compensated candidate ends at exactly two increments above its pre-run value (`prepared → active` is one increment; the compensating `active → prepared` is a second increment), **never restored to its pre-run value**. Only if every single candidate's commit succeeds does the cohort reach `activated`.

**No external traffic may pass while the saga or its compensation runs.** This is a structural, provable property, not a policy statement: Universal Telegram's own cutover-aware replay dispatcher (ADR-0042 §3) never runs until the activation saga has already reached a terminal `activated`/`activation_failed` state, and quiescence remains non-`idle` (blocking/buffering every live arrival) for the saga's entire duration — so no buffered or live update can reach a binding this saga might still compensate away.

**Operator confirmation, run identity, durable audit, resume/recovery, fail-closed semantics** (all owned and frozen by Universal Telegram's ADR-0042, cross-referenced here, not duplicated): a mandatory `--assume-cutover-authority` flag; a per-invocation `cutover_run_id` correlating every activation and compensation audit row from the same run; idempotent resume on crash (an interrupted saga's already-committed candidates are unaffected, its never-attempted candidates are simply retried by the next invocation); and fail-closed refusal, never a force/abandon command, matching this whole programme's established governance.

### 3. Deferred Telegram updates and routing — cohort-aware replay, one authoritative barrier

Universal Telegram's existing replay loop (ADR-0040 §3/§6, unchanged trigger and CAS mechanics) is extended, per candidate row, with one additional check evaluated **live, at drain time**: does an **active** binding currently exist for this row's `(bot_id, telegram_topic_id)`? This is the identical predicate `try_handle()` itself already evaluates for live traffic, reused rather than reinvented. If yes, the row is dispatched through the cohort-aware handoff path (§4 below) instead of Universal Telegram's legacy `process_update()` branch; if no, it is replayed exactly as ADR-0040 already specifies. **There is no separate "final handoff scan" step performed before `quiescence exit`** — the replay loop itself is the single authoritative drain, closing the race a two-step design would otherwise leave open (a row arriving between a separate scan and `quiescence exit` would be invisible to that scan and wrongly fall to legacy replay).

Deterministic per-bot ordering by `(update_id, id)` is preserved unchanged — this design adds a dispatch-target decision per row, never a reordering.

**The final `replaying → idle` transition remains serialized with webhook buffering** on the same row lock ADR-0040 §3 already proves closes this race (`decide_webhook_disposition()`/the final CAS share one lock) — unchanged. **Its backlog predicate is widened**, from ADR-0040's original `replayed_at IS NULL`, to cover every row not yet resolved by any of the three paths this design introduces: `replayed_at IS NULL AND handed_off_at IS NULL AND incident_resolved_at IS NULL` (the third column is Universal Telegram's own incident-resolution marker, ADR-0042 §5) — so an unresolved incident correctly, structurally blocks `replaying → idle`, not merely `cutover confirm-complete`.

### 4. Support Chat handoff contract and idempotency — additive Contract v1 payload extension, transactional co-write, provenance-checked retry

**No new Contract v1 operation, public REST route, or shared secret is introduced.** This design extends exactly the six existing adapter → Support Chat operations `InboundAdapterBridge` already calls for live traffic: `ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`. Each gains two new, optional request-body fields, present only for cutover-replay-originated calls and absent (unchanged) for every live call site:

```
"source_bot_id":    int|null
"source_update_id": int|null
```

**Support Chat owns and writes its own handoff map, exclusively, transactionally, alongside the domain effect — never Universal Telegram.** New table, this repository's own schema:

```
universal_support_chat_legacy_handoff_map
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  bot_id               BIGINT UNSIGNED NOT NULL
  update_id            BIGINT NOT NULL
  kind                 VARCHAR(24) NOT NULL   -- 'message'|'claim'|'release'|'resolve'|'reopen'|'channel_unavailable', server-derived, never client-supplied
  channel_case_ref     CHAR(36) NOT NULL      -- the binding UUID this call resolved to; always populated
  target_message_uuid  CHAR(36) NULL          -- populated only for kind='message'
  created_at           DATETIME NOT NULL
  UNIQUE KEY bot_update (bot_id, update_id)
```

Each of the six extended handlers, when `source_bot_id`/`source_update_id` are both present, wraps its **entire success path** — including every already-in-target-state early-return branch — inside one explicit transaction: perform the domain write (already proven idempotent by source, §"Required source verification" items 3–6), then either insert the new handoff-map row (first-ever call for this `(bot_id, update_id)`) or, on finding an existing row, **verify its stored `kind` and `channel_case_ref` match this call's own newly-derived values** before treating the call as a successful idempotent retry. **A mismatch returns a durable `409 handoff_provenance_conflict`, rolls back, performs no domain write, and inserts nothing** — never silently accepted. This directly replaces a bare `INSERT IGNORE`, which would otherwise accept an inconsistent retry silently.

**Crash/retry convergence, exact:** Universal Telegram stamps its own `handed_off_at` only after receiving `{ok: true}` from this call. A crash between Support Chat's commit and Universal Telegram's stamp leaves Universal Telegram's row unmarked; the next replay-loop pass re-dispatches the identical call with the identical provenance fields; Support Chat's handler re-runs its full path, finds the existing, matching handoff-map row, treats it as a genuine retry (not a conflict), re-confirms the already-idempotent domain effect, and returns `{ok: true}` again — a second, harmless commit of "nothing changed," not a second side effect. Universal Telegram then stamps `handed_off_at` on the retry. **This is at-least-once delivery, with Support Chat committing first and Universal Telegram acknowledging second — not a distributed transaction, and no wording in this ADR or its companion describes it as atomic across the two plugins.**

**No plaintext or content-derived digest belongs in either the handoff map or any audit record it or the six handlers' own `AuditLogger` calls produce** — every column above is an id, a uuid, a fixed vocabulary string, or a timestamp, matching this whole programme's established discipline (ADR-0008 §3, ADR-0009 §4, ADR-0040 §3).

### 5. Universal Telegram-only incidents — never a Support Chat handoff-map row

**Frozen, exhaustive rule:** a pre-dispatch failure — decrypt failure, parse failure, an unsupported command classification, an unmapped sender — occurs entirely inside Universal Telegram's own replay dispatcher, strictly before any Contract call is attempted. **No Support Chat operation is ever invoked for these; Support Chat's handoff map gains zero rows as a result, structurally, not merely by convention.** A `409 handoff_provenance_conflict` refusal (§4) is likewise never accompanied by a Support Chat handoff-map write — Support Chat rolled back and wrote nothing for that call — and Universal Telegram records the refusal as its own incident.

**Closed, non-content reason vocabulary** (frozen by Universal Telegram's ADR-0042 §5, which owns the incident record; referenced here for completeness): `decrypt_failed`, `parse_failed`, `unsupported_command`, `unmapped_sender`, `handoff_provenance_conflict`. A transient transport/availability failure (Support Chat unreachable, a thrown exception mid-call) is **not** an incident — it sets no reason code and leaves the row simply unresolved for the next ordinary replay-loop retry, matching this whole programme's "resume is retry" pattern.

**An unresolved incident blocks completion and `replaying → idle`** (§3's widened predicate).

**Product Owner decision, recorded explicitly, this freeze — the exceptional terminal-acknowledgement path is retained:**

- **Default and recommended policy**: an incident must be remediated and successfully dispatched or replayed before cutover can complete. This remains the expected path for the overwhelming majority of incidents.
- **The exception, approved by the Product Owner in this documentation-freeze session** (2026-08-27), is retained for the genuinely unrecoverable case (e.g., a permanently corrupted ciphertext with no possible remediation): a new, authority-gated WP-CLI command (`wp universal-telegram cutover incident-acknowledge`, frozen in ADR-0042 §5) may stamp a row `incident_resolved_at`/`incident_resolution = 'po_acknowledged_terminal'`, **never** `replayed_at` or `handed_off_at`. It **must** reference an explicit Product Owner decision record (an opaque, pre-existing identifier — e.g. a decision-record filename/anchor — never free-form operator text); it **must not** accept arbitrary free-form content in any CLI argument or audit field it writes; it stores only that opaque reference plus the row's existing fixed, non-content metadata (`bot_id`, `update_id`, `incident_reason`, timestamps). The row's encrypted payload and full audit trail are **never deleted** by this action or by any retention sweep — this is a workflow resolution, not a content disposition.

### 6. Live lifecycle-event cross-talk — resolved and frozen

**Confirmed defect** (§"Required source verification" item 8): `maybe_mark_topic_unavailable()` runs before the adapter-bridge check and matches on `topic_creation_state = 'created'` alone, so an activated cohort topic's legacy conversation row continues to intercept `forum_topic_closed`/`forum_topic_deleted` service messages indefinitely.

**Frozen resolution** (implementation owned by Universal Telegram, specified here for completeness since it is this ADR's own explicit requirement): `WebhookController::process_update()`'s handling of a topic-lifecycle service message is reordered so that, **before** `maybe_mark_topic_unavailable()`'s legacy lookup runs, a check for an **active** binding on the update's `(bot_id, message_thread_id)` is performed (reusing `ChannelBindingRepository::find_by_bot_topic()`, the identical lookup `try_handle()` already performs). If an active binding exists, the event is dispatched via `sc_client->report_channel_unavailable( binding_uuid, reason_code )` — reusing the **same** fixed `reason_code` vocabulary legacy already emits (`'telegram_topic_closed'` / `'telegram_topic_deleted'`, confirmed identical strings, `WebhookController.php:354`) — and the update is considered handled, **never** reaching `maybe_mark_topic_unavailable()`'s legacy mutation. If no active binding exists, existing legacy behavior is retained unchanged.

**Fail-closed semantics, precise:** mirroring `try_handle()`'s own existing "claimed but fail-closed for channel only" pattern (`InboundAdapterBridge.php:75-77`) — if the Contract call itself fails (adapter unpaired, discovery incompatible, transport failure), the event is still considered **claimed** by the adapter path and does **not** fall through to legacy mutation, since falling through would mutate a legacy conversation row for a topic that is no longer legacy-owned. The failure is recorded via the adapter's existing audit discipline; it is a live-traffic outcome, distinct from and not tracked by the deferred-replay incident record (§5), which applies only to buffered rows processed during quiescence/replay.

**`ChannelStatusRepository::upsert()`'s retry/timestamp behavior, verified and frozen** (§"Required source verification" item 6): a real `INSERT ... ON DUPLICATE KEY UPDATE` keyed by `UNIQUE(conversation_id)`; repeated identical calls are safe, converge to the identical `status`/`reason_code`, and legitimately advance `updated_at` on every call — this is correct, expected behavior for both the live-webhook path (§6) and the deferred-replay path (§4), and requires no change.

## Alternatives

- **A new, dedicated Contract v1 operation for handoff provenance, called separately from the domain operation** — rejected: would relocate the exact commit/acknowledge race this design closes one level lower (a gap between two separate calls' two separate commits), rather than closing it, since the review that drove this ADR's design explicitly required the provenance write to share the *same* transaction as the domain write.
- **A bare `INSERT IGNORE` for the handoff-map row, with no identity verification on a duplicate key** — rejected: silently accepts an inconsistent retry (a different `kind`/`channel_case_ref` presented against an already-recorded `(bot_id, update_id)`), which is a genuine data-integrity signal that must fail closed, not be silently reconciled.
- **A new in-process WP-CLI-only push boundary (`LegacyHandoffImportServiceV1`), symmetric to `LegacyExportServiceV1`/`LegacyBindingImportServiceV1`** — rejected in favor of reusing the existing per-message/per-command Contract v1 dispatch: a buffered row is exactly the same class of traffic as a live operator reply/command, just time-shifted, so reusing the already-proven, already-authenticated, already-idempotent live channel is safer and smaller than inventing a second, parallel boundary for the same class of operation.
- **Requiring every incident to be remediated with no exception path** — considered and rejected by explicit Product Owner decision this freeze (§5): a single genuinely unrecoverable row would otherwise have no defined way to ever let cutover complete. The retained exception is narrowly scoped (opaque PO reference only, no free-form content, never marks the row replayed/handed-off, preserves ciphertext/audit forever) specifically to avoid becoming a general-purpose bypass.
- **Reusing `set_status()` for activation** — rejected: confirmed to have no CAS guard and no current-status precondition (§"Required source verification" item 9); a new, guarded, CAS-checked method is required and frozen in ADR-0042.

## Consequences

- This repository's own schema gains one new, additive table (`universal_support_chat_legacy_handoff_map`) and no changes to any existing table.
- The six existing adapter → Support Chat Contract operations gain two new optional request-body fields each; every existing live call site (`InboundAdapterBridge`) is unaffected, since it never populates them.
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` gains an additive `§0d` amendment (this same freeze) recording this ADR's authorization and the corrected "atomic per approved cohort" charter clarification.
- A future, separately-approved implementation task is required before any of this design's code, schema, or CLI surface exists. This ADR authorizes documentation only.
- Universal Telegram's companion ADR-0042 owns and freezes: the cutover state machine, `activate_prepared()`/`revert_activation()`, the cohort-aware replay loop's exact mechanics, the UT-owned incident record and its CLI, and the `maybe_mark_topic_unavailable()` reordering's exact implementation shape.

## Security and privacy impact

- No new REST route, no new authentication mechanism, no shared secret. The two new request-body fields ride the existing Ed25519-signed request envelope `SignatureSigner`/`SignatureVerifier` already covers in full.
- Every new persisted field, on both sides of this boundary, is non-content: ids, uuids, a fixed reason/kind vocabulary, timestamps. No plaintext, ciphertext, or content-derived digest is ever written to the handoff map, the incident record, or any audit row this design produces.
- The terminal-acknowledgement exception (§5) is deliberately narrow: an opaque PO-decision reference only, never free-form text, preventing it from becoming an uncontrolled content-injection or audit-poisoning surface.

## Affected Documents/Milestones

- `docs/adr/README.md` (index and reserved-number table updated for ADR-0010).
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (additive `§0d`).
- `docs/decisions/sc-m03-final-cutover-po-decisions.md` (new — the terminal-acknowledgement Product Owner decision, recorded formally).
- `docs/plans/sc-m03-final-cutover-plan-v1.md` (new — the implementation-ready plan this ADR authorizes future implementation against).
- `docs/plans/README.md`, `docs/decisions/README.md`, `docs/closure/README.md` (registry updates).
- ADR-0004, ADR-0007, ADR-0008, ADR-0009 (referenced, unedited).
- Universal Telegram repository: ADR-0042 (new, companion, owns the Universal Telegram-side half of this same design) — not performed in this task, coordinated as part of this same documentation-freeze session.

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, release, tag, or deployment change in this freeze — this ADR is documentation only.
- Future implementation may not begin until **both** this ADR and Universal Telegram's ADR-0042 (pinning this ADR's post-merge commit SHA) are merged to their respective `main` branches, mirroring the identical two-repository gate every prior SC-M03 work package already established.
- This ADR does not authorize, schedule, or execute any production quiescence, cutover, route switch, soak, rollback, or deletion.
