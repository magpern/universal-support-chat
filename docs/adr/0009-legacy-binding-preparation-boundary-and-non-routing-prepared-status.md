# ADR-0009 — Legacy Binding Preparation Boundary and Non-Routing `prepared` Status

## Status

Accepted

## Context

SC-M03 work packages 3–4 ([ADR-0008](0008-legacy-export-boundary-and-migration-authority-model.md), closure: `docs/closure/sc-m03-work-packages-3-4-legacy-migration-engine-closure.md`) and work package 2 ([closure](../closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md), Product Owner accepted) have shipped: the batch migration engine, the `legacy_migration_map` schema (preserving `legacy_bot_id`, `legacy_destination_id`, `legacy_telegram_topic_id`, `legacy_topic_creation_state`, `legacy_topic_lifecycle_state` per conversation specifically for this future work package), and a real, non-default-deny `QuiescenceStateProvider` implementation proven against Universal Telegram's actual ADR-0040 quiescence machinery.

Every legacy Universal Telegram conversation that WP3–4 has migrated to `status = 'migrated'` and that already has a real, created Telegram forum topic has no way for a future reply in that topic to reach its migrated Support Chat conversation: Universal Telegram's inbound routing (`InboundAdapterBridge::try_handle()`) looks a reply up by `(bot_id, telegram_topic_id)` in its own `universal_telegram_support_chat_bindings` table, and no binding row exists yet for a topic that predates Support Chat. Work package 5's purpose is to create those binding rows — safely, auditably, and without altering live routing.

**A binding write is a new data-flow direction this repository has not yet governed.** ADR-0008 fixed the read direction (Universal Telegram → Support Chat, via `LegacyExportServiceV1`) for the batch migration engine. Work package 5 requires the opposite: Support Chat identifying binding candidates from its own migration map, and a write landing in Universal Telegram's own `universal_telegram_support_chat_bindings` table. Per this repository's own rule (ADR-0002, ADR-0007 §1, restated in ADR-0008 §1) that no plugin reads or writes another plugin's database tables directly, this write may not originate as direct cross-plugin SQL from Support Chat, and per `docs/governance.md`'s freeze model no implementation of it may begin before this ADR exists.

**A binding write is not automatically inert, and this ADR exists specifically because a naive design is not safe.** Direct verification against Universal Telegram's own source (`Telegram\Inbound\WebhookController::process_update()`, `SupportChatAdapter\Inbound\InboundAdapterBridge::try_handle()`) during this ADR's drafting established that `try_handle()` is called unconditionally for every inbound Telegram webhook update, before any legacy-conversation routing is attempted, and that its sole gate is `ChannelBindingRepository::find_by_bot_topic()` returning a row whose `is_active()` is `true` — there is no check anywhere of whether a legacy conversation for that topic is still open, and no conflict detection between a legacy conversation and a binding sharing the same topic. This means a binding written with Universal Telegram Adapter M1's existing default status, `'active'`, immediately and silently reroutes that topic's future replies away from legacy handling — independent of quiescence, independent of any future SC-M03 cutover step, and independent of whether the legacy conversation is otherwise still open. This is true of already-shipped Universal Telegram Adapter M1 code today (`SupportChatAdapter\Cli\BindingImportCommand`, which already writes `status = 'active'` unconditionally); this ADR's design does not introduce the underlying gap, but a work package 5 built as a thin wrapper around that existing command would inherit it. This ADR exists to prevent that.

This ADR also authorizes the specific SC-M03 work package the WP3–4 closure record's own "Next task" section named as its successor, gated on work package 2's Product Owner acceptance ([closure](../closure/sc-m03-wp2-phase-b-recheck-implementation-closure.md), now accepted).

## Decision

### 1. Ownership split (unchanged in direction of authority, reversed in direction of data flow)

- **Support Chat owns candidate identification, entirely from data it already holds.** Every field needed to identify a work package 5 candidate already exists on `universal_support_chat_legacy_migration_map` (`legacy_bot_id`, `legacy_destination_id`, `legacy_telegram_topic_id`, `legacy_topic_creation_state`, `legacy_topic_lifecycle_state`, `target_conversation_uuid`, `status`). Support Chat needs no new read from Universal Telegram to enumerate candidates.
- **Universal Telegram owns the binding table exclusively — every write to `universal_telegram_support_chat_bindings`, without exception, is Universal Telegram's own code.** This is the same ownership rule ADR-0002/ADR-0008 §1 already establish for reads, applied here to writes: Support Chat's migration engine has never opened, and under this ADR will never open, a `$wpdb` query against a `universal_telegram_*`-prefixed table. Universal Telegram's own service (§2) performs its own authoritative re-validation of every candidate before writing anything — Support Chat's migration-map data is treated as *input*, never as sufficient proof by itself, exactly as `LegacyExportServiceV1` never assumed Support Chat's target-side re-encryption was safe without Support Chat's own discipline.

### 2. The boundary is a narrow, versioned, in-process PHP interface in Universal Telegram — never REST, never a Contract v1 operation, never direct SQL

Symmetric to `LegacyExportServiceV1` (ADR-0008 §2), for the write direction:

- Universal Telegram exposes a new, explicitly versioned service, **`LegacyBindingImportServiceV1`**, in its own codebase — a plain PHP class, not a WordPress REST route, not a hook, not an addition to Contract v1's operation allow-list (ADR-0007 §4, unmodified).
- Called **in-process**, within the same PHP request running Support Chat's WP-CLI command, because both plugins already run in the same WordPress install (ADR-0002 non-goals, unchanged). No HTTP round trip, no new listening port.
- **No public REST route, in either plugin, under any circumstance.** No shared secret, application password, or bearer token.
- **Method contract:**

  ```php
  interface LegacyBindingImportServiceV1 {
      /** @param array $candidates Max 100 per call, enforced server-side regardless of caller request. */
      public function import_batch( array $candidates, bool $dry_run = false ): array; // BindingImportResult[]
  }
  ```

  Each candidate carries exactly: `source_conversation_id`, `bot_id`, `destination_id`, `telegram_topic_id`, `support_conversation_uuid` — the same minimal, non-content field set already in the migration map. Each result is a typed outcome (§4) plus the resulting `binding_uuid` when one is created — never a thrown exception that aborts the whole batch, mirroring ADR-0008 §5's per-item error-entry pattern.
- **Rejected alternatives** (§ Alternatives): extending Contract v1's allow-list (superseding the framing in `docs/plans/sc-m03-controlled-migration-and-cutover-plan-v2.md` §8 item 5, which predates ADR-0008 and is superseded by its reasoning, per this repository's existing precedent that a later ADR supersedes earlier plan text without editing the plan file); direct cross-plugin SQL.

### 3. `prepared` — a new, non-routing binding status; Universal Telegram never writes `active` for this boundary

Universal Telegram's `universal_telegram_support_chat_bindings.status` gains a fourth value, **`prepared`**, additive to the existing `active`/`unavailable`/`closed` enumeration. `LegacyBindingImportServiceV1::import_batch()` **only ever writes `status = 'prepared'`, never `'active'`.** `ChannelBinding::is_active()` (`is_active(): bool { return self::STATUS_ACTIVE === $this->status; }`) is unchanged and, by that construction alone, `false` for a `prepared` row.

Because `InboundAdapterBridge::try_handle()`'s sole gate is `$binding->is_active()`, a `prepared` binding is **structurally unreachable from live inbound Telegram routing** — a testable code property, not a claim that depends on when some other future component (a cutover work package, a legacy webhook retirement) changes. `DeliverMessageService`'s outbound routing (`find_by_uuid()` + `is_active()`) is gated identically and is equally unaffected.

**Activation — the future, explicit transition from `prepared` to `active` — is not part of this ADR or work package 5.** It is named here only so this decision is forward-consistent with the eventual cutover work package, which requires its own dedicated ADR, its own explicit operator action, and is never an implicit side effect of a binding's mere existence.

### 4. Status-specific idempotency and conflict model

A migration-map row's binding attempt (`source_conversation_id`) produces exactly one of these outcome classes. **Terminal** outcomes permanently mark the row done (write a non-`NULL` `binding_status`, §6); **retryable** outcomes never do, so the row is automatically reselected by the very next ordinary run with no special flag or mode.

| Outcome | Class | Trigger |
|---|---|---|
| `binding_skip_no_topic` / `binding_skip_missing_bot_or_destination` / `binding_skip_topic_not_created` / `binding_skip_topic_lifecycle_terminal` / `binding_skip_no_target_conversation` | Terminal | Structural map-row ineligibility (§ Plan §2 items 2–6) |
| `binding_skip_topic_state_changed_since_migration` | Terminal | Universal Telegram's live re-check conclusively invalidates the topic |
| `binding_retry_ut_unavailable_or_indeterminate` | **Retryable** | Universal Telegram's live re-check is inconclusive (unavailable, inactive, transient error) |
| `binding_skip_already_bound` | Terminal (idempotent success) | Matching identity, existing binding's `status = 'prepared'` |
| `binding_conflict_existing_mismatched` | Terminal (conflict, manual review) | An existing binding for the same `(bot_id, telegram_topic_id)` or the same `support_conversation_uuid` points at a **different** counterpart, in any status |
| `binding_conflict_existing_active` | Terminal (conflict, manual review, elevated priority) | Matching identity, existing binding's `status = 'active'` — never treated as idempotent success; evidence live routing may already be enabled outside this boundary's own knowledge |
| `binding_conflict_existing_status_unresolved` | Terminal (conflict, manual review) | Matching identity, existing binding's `status` is `unavailable` or `closed` — no approved reuse/reactivation policy exists |
| `binding_retry_not_quiescent` | **Retryable** | The atomic per-candidate quiescence assertion (§5) fails |
| `binding_retry_transient_error` | **Retryable** | Any other caught, typed, non-aborting exception inside `import_batch()` |
| *(none — row stays unattempted)* | **Retryable** (implicit) | Run interrupted before the candidate's transaction committed |

**A matching-identity existing binding is idempotent success only when its status is `prepared`.** This is the single most load-bearing correctness rule this ADR fixes: `LegacyBindingImportServiceV1` must never silently bless an `active`, `unavailable`, or `closed` binding as "already prepared" — each is a distinct, terminal, fail-closed outcome requiring manual resolution, never an automatic reinterpretation. No conflict outcome (`binding_conflict_*`) is ever automatically retried by an ordinary rerun; a future, separately-approved `--retry-conflicts`-style mode requeuing these only after out-of-band human resolution is an open Product Owner decision (§ Consequences), not required by this ADR's minimum scope.

**No binding is ever invented from incomplete or ambiguous evidence.** A retryable (indeterminate) condition is never optimistically resolved to a terminal `created`/`skipped` outcome.

### 5. Quiescence is enforced atomically, inside Universal Telegram, per candidate — not merely pre-checked by Support Chat

Support Chat's own early `is_quiescent()` pre-check (reusing `UniversalTelegramQuiescenceStateProvider`, the same provider Phase B consumes) is real and useful as a cheap, early refusal before any Universal Telegram call — but it is **not the authoritative guard**, because Universal Telegram's quiescence state can change in the window between that check and Universal Telegram's actual write.

**The authoritative guard is a new capability Universal Telegram must expose**, mirroring the exact row-lock discipline `Migration\QuiescenceGate` already uses to serialize its webhook buffer-vs-process decision against the final `replaying → idle` transition (`docs/adr/0040 §3` in the Universal Telegram repository): inside `LegacyBindingImportServiceV1::import_batch()`, each candidate's own transaction must `SELECT state, token FROM {quiescence_state} WHERE id = 1 FOR UPDATE`, and — still holding that lock, inside the same transaction — verify `state === 'quiescent'` **and** the deferred-update backlog is empty, then perform its own live topic-validity re-check, and only then, still in the same transaction, call `ChannelBindingRepository::create()` with `status = 'prepared'`. Any failure at any step rolls back the entire candidate, writing nothing. Because the check and the write share one lock and one transaction, there is no window in which a binding can be created while quiescence is not actually, atomically, held at that instant.

### 6. Terminal-outcome persistence — additive columns on the existing migration-map row, no new table

`universal_support_chat_legacy_migration_map` gains `binding_status` (`NULL` = not yet attempted terminally — also the rescan predicate: `status = 'migrated' AND binding_status IS NULL`), `binding_error_reason`, `binding_uuid`, `binding_attempted_at` (all written only by terminal outcomes), and `binding_last_attempt_at` / `binding_last_attempt_reason` (written by every attempt, terminal or retryable, and never gating the rescan predicate). No separate batch/run-log table: per-candidate state is already fully reconstructible from these columns after every attempt, unlike WP3–4's Phase A backfill, which needed `legacy_migration_batch_log` because it streams through a large, multi-invocation run with no other persisted per-row state until each row's transaction commits.

### 7. Authority model for WP-CLI invocation — identical framing to ADR-0008 §4

A new Support Chat WP-CLI command family, `wp universal-support-chat legacy-bind {status,validate,run}`, mirrors `LegacyMigrateCommand`'s existing shape. `run` requires an explicit, mandatory `--assume-binding-authority` flag before any real write (named distinctly from `--assume-migration-authority`, a separate operator confirmation for a separate, later operation) — **a command-level operator-confirmation guard, not a security control**, identical in kind and limitation to ADR-0008 §4's treatment of `--assume-migration-authority`. `LegacyBindingImportServiceV1` itself must fail closed outside WP-CLI (`defined('WP_CLI') && WP_CLI`), identical to `LegacyExportServiceV1`. **The actual security boundary remains operating-system authority to execute WP-CLI against this install** — nothing in this ADR is a substitute for that boundary, exactly as ADR-0008 §4 states of its own authority model, including its identical stated limitation that no in-process check can distinguish this command's own invocation from any other code already executing inside the same authorized WP-CLI process.

## Alternatives

- **Extend Contract v1's operation allow-list for bulk binding writes** — rejected, for the identical reason ADR-0008 already rejected it for bulk reads: a bulk/administrative operation is a different security shape than Contract v1's real-time, per-conversation, signed mutation calls. This supersedes `docs/plans/sc-m03-controlled-migration-and-cutover-plan-v2.md` §8 item 5's earlier, pre-ADR-0008 framing.
- **Direct cross-plugin SQL** (Support Chat inserting into `universal_telegram_support_chat_bindings` itself) — rejected outright, identical ADR-0002/ADR-0007 §1 violation ADR-0008 already rejected for the read direction.
- **Write `status = 'active'` directly, relying on a future cutover step to be the actual point of no return** — rejected. Direct source verification during this ADR's drafting disproved the premise: `try_handle()`'s routing gate has no dependency on cutover state, quiescence, or any future work package; an `active` binding is live the instant it exists. The `prepared` status (§3) replaces this disproven assumption with a testable code property.
- **Build work package 5 as a thin wrapper around Universal Telegram's existing `BindingImportCommand`** — rejected. That command hardcodes `status = 'active'` and performs no live re-validation of the source conversation's state at import time; wrapping it would inherit both the immediate-live-routing defect above and the staleness the reviewed plan's eligibility model (§4) is designed to catch.
- **Treat any matching-identity existing binding as idempotent success regardless of status** — rejected during this ADR's own review process: an `active` match is not evidence of this boundary's own prior success and must never be silently accepted as such (§4).
- **A single "quiescence pre-check, then write" sequence, without an atomic in-process lock** — rejected: leaves a TOCTOU window between Support Chat's check and Universal Telegram's write; §5's atomic, lock-scoped, per-candidate design closes it.

## Consequences

- Universal Telegram's repository gains a new, narrow obligation: implement `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion, per §2–§5 of this ADR, pinned to this ADR's post-merge commit SHA (mirroring the existing ADR-0008/ADR-0039 cross-repository pinning rule).
- Support Chat's migration-map schema gains six additive columns (§6); no new table.
- `docs/plans/sc-m03-controlled-migration-and-cutover-plan-v2.md` §8 item 5's original Contract-v1-server framing is superseded by this ADR's reasoning; the plan file itself is not edited, per this repository's precedent of ADRs superseding earlier plan text.
- **Open Product Owner decision, not fixed by this ADR:** whether a future `--retry-conflicts`-style mode (requeuing `binding_conflict_*` rows after out-of-band human resolution) is in work package 5's initial implementation scope. Resolved for this implementation cycle: **deferred** — ordinary reruns never revisit terminal conflict rows in this cycle's implementation.
- **Open, explicitly out-of-scope note, not resolved by this ADR:** `BindingImportCommand`'s own pre-existing gap (it already writes `status = 'active'` unconditionally with no liveness check, independent of this ADR) is a standing Universal Telegram-owned hardening candidate. This ADR's `binding_conflict_existing_active` outcome (§4) is a *detection* mechanism for the case where it collides with a later work-package-5 run — it does not fix the underlying command. Not addressed by this ADR or this work package.
- `prepared → active` activation is a real, forthcoming design need this ADR deliberately does not solve — flagged so it is not mistaken for a decided problem.

## Security and privacy impact

- No plaintext, message content, or per-message delivery correlation (`telegram_message_id`, `outbound_message_uuid`) is read, stored, or transmitted anywhere in this boundary — every field moved is already non-content (ids, states, timestamps), consistent with WP3–4's own "no per-message delivery correlation" scoping note.
- No new network-reachable endpoint, shared secret, or WordPress capability is introduced. The real security boundary is unchanged from ADR-0008 §4: operating-system authority to execute WP-CLI against this install.
- The `prepared` status is a genuine new safety mechanism, not merely a naming convention: it is the concrete, code-enforced reason a binding written by this boundary cannot participate in live inbound or outbound routing, closing the gap direct source verification found in the naive "write `active`, rely on future cutover" design.
- The atomic, lock-scoped quiescence assertion (§5) is a stronger guarantee than Phase B's own re-check amendment (WP2): the check and the write are one atomic operation here, not two closely-spaced statements.

## Affected Documents/Milestones

- `docs/adr/README.md` (index and reserved-number table updated for ADR-0009).
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (additive amendment recording this ADR and work package 5's authorization, mirroring §0b's existing pattern for work packages 3–4).
- `docs/plans/sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md` (new — the implementation-ready plan for work package 5, referencing this ADR).
- `docs/decisions/sc-m03-wp5-legacy-binding-po-decisions.md` (new — records the Product Owner decisions this ADR defers: conflict-retry-mode scope, `BindingImportCommand` hardening deferral, `prepared → active` activation out-of-scope framing).
- ADR-0002, ADR-0003, ADR-0004, ADR-0007, ADR-0008 (referenced, unedited).
- Universal Telegram repository: a future documentation amendment implementing `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion per §2–§5, pinned to this ADR's post-merge commit SHA — a precondition for this work package's implementation to begin (see Compatibility/Migration Impact), mirroring the identical two-repository gate ADR-0008 §110/§115 already established.

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, release, tag, or deployment change in this freeze — this ADR is documentation only.
- Work package 5 implementation may not begin until **both** (a) this ADR and (b) the Universal Telegram documentation amendment pinning it (implementing `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion) are merged to their respective `main` branches — the identical two-repository gate ADR-0008 already established for the export boundary.
- This ADR does not authorize, schedule, or execute any production binding creation, cutover, route switch, or activation. It authorizes only the future implementation of the binding-*preparation* engine, which may not claim cutover readiness, route-switch execution, or `prepared → active` activation in any case (§3).
