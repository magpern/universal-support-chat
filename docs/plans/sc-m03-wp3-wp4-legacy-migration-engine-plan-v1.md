# SC-M03 Work Packages 3–4: Legacy Migration Engine — Implementation Plan v1

Detailed implementation plan for [SC-M03 plan v2](sc-m03-controlled-migration-and-cutover-plan-v2.md) §8 work packages 3 ("Batch migrator + checkpoints") and 4 ("Validators"). Does not supersede plan v2, which remains the authoritative work-package sequence for all of SC-M03; this document is the required plans/README.md-conforming detail for the two work packages it names, analogous to how SC-AI1/SC-AI2 carry their own dedicated plan files within the same overall roadmap.

## 1. References

- Charter: `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (§0 sequencing amendment, extended by this plan's own charter amendment)
- Parent plan: [sc-m03-controlled-migration-and-cutover-plan-v2.md](sc-m03-controlled-migration-and-cutover-plan-v2.md) §8 items 3–4
- ADRs: ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0007, **ADR-0008** (new — legacy export boundary and migration authority model)
- Product Owner decisions: [SC-M03 work packages 3–4 legacy migration PO decision record](../decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md)

## 2. Findings

Work packages 0–1 (authenticated Contract server; Universal Telegram signed client + joint interoperability gate) are merged (Support Chat PR #7, Universal Telegram PR #35). Work package 2 (quiescence switches/drains) and work package 5 (binding creator) remain separate, unstarted, later units of work. Universal Telegram's legacy conversation data model (`conversations`, `conversation_messages`, `conversation_notes`, plus the AI-drafts and operator-identity tables, which are out of scope here) is fully inventoried; every physical column has an explicit disposition (§4). Contract v1's operation allow-list (ADR-0007 §4) is closed and does not, and per ADR-0008 will not, cover bulk legacy-data export — a separate in-process boundary is required and is fixed by ADR-0008.

## 3. Assumptions and open questions

**Assumptions:**
- Both plugins continue to run in the same WordPress install for the duration of this milestone (unchanged from plan v2 §3).
- Universal Telegram implements `LegacyExportServiceV1` per ADR-0008 as its own coordinated follow-up slice, pinned to ADR-0008's post-merge commit SHA, before this plan's implementation begins.
- AI tables remain in Universal Telegram (historical); not migrated here (unchanged from plan v2 §3).

**Open questions — none remaining as unresolved architecture.** The field-mapping and product-scope questions the architecture review raised are closed by ADR-0008 (architecture) and the PO decision record (product/scope) referenced in §1. No question in this plan is deferred to implementation-time invention.

## 4. Architectural decisions

### 4.1 Field mapping (complete, CI-enforced)

Every column of `universal_telegram_conversations`, `universal_telegram_conversation_messages`, and `universal_telegram_conversation_notes` has an explicit disposition — copy, remap, transform-to-constant, or exclude with a stated reason. The full table:

**`conversations`:**

| Source column | Disposition |
|---|---|
| `id` | Excluded from the target row; preserved as `source_conversation_id` in `legacy_migration_map` (deterministic-ordering cursor). |
| `conversation_uuid` | Not copied (fresh Support Chat UUID generated); preserved as `source_conversation_uuid` in the map. |
| `secret_hash` | Excluded — Telegram-topic transport secret, no Support Chat equivalent, never carried into a second system. Not present in the ADR-0008 export shape at all. |
| `bot_id` | Excluded from the target row; preserved as `legacy_bot_id` in the map for work package 5. |
| `destination_id` | Excluded from the target row; preserved as `legacy_destination_id` in the map for work package 5. |
| `chat_profile` | Excluded — Universal Telegram-internal multi-bot routing selector; no Support Chat concept; bot identity already preserved via `legacy_bot_id`. Not present in the ADR-0008 export shape. |
| `status` | Copied 1:1 — the status enum is identical in both plugins. |
| `assigned_operator_id` | Copied, per PO decision record item 2 — preserves historical assignment data; not surfaced as a new UI feature this milestone. |
| `topic_creation_state`, `telegram_topic_id`, `topic_lifecycle_state` | Excluded from the target row; preserved verbatim in the map (`legacy_topic_creation_state`/`legacy_telegram_topic_id`/`legacy_topic_lifecycle_state`) for work package 5's existing-topic binding. |
| `ai_participation_state`, `ai_ack_policy_version` | Excluded — AI-scoped, out of scope per the SC-M03 charter's "no AI migration" exclusion. Not present in the ADR-0008 export shape. |
| `consent_state` | Not migrated, per PO decision record item 5. Not present in the ADR-0008 export shape. |
| `session_ref` | Excluded — verified never written by any Universal Telegram code path (dormant column, always `NULL` in practice). Not present in the ADR-0008 export shape. |
| `created_at`, `updated_at`, `resolved_at`, `expires_at` | Copied 1:1, verbatim. |
| `start_idempotency_key` | Not copied verbatim (Support Chat enforces live uniqueness on this column). Regenerated as `hash('sha256', 'legacy-migration:start:' . ($source_key ?: 'conv:' . $source_conversation_uuid))` — falls back to the always-unique source `conversation_uuid` when the source key is `NULL`, which is a verified, real legacy state. |
| `topic_claim_expires_at`, `display_name_ciphertext`, `owner_active_slot`, `topic_lifecycle_code`, `topic_delete_claim_expires_at` | Excluded — transport/UI/generated-index-specific, no Support Chat target column exists. Not present in the ADR-0008 export shape. |
| `owner_user_id` | Copied, conditionally, per PO decision record item 1 — only if implementation-time verification confirms equivalent visitor-identity semantics; implementation stops and returns to review otherwise. Anonymous/`NULL` handling per PO decision record item 3. |
| `assignee_last_seen_message_id` | Not a copy — remapped through `legacy_migration_message_map` (§4.3) to the corresponding target message's `id`, or set `NULL` if that message was not migrated. |

**`conversation_messages`:** `id` (excluded, preserved via the message map), `message_uuid` (not copied, fresh target UUID, source retained via the message map), `direction`/`created_at` (copied 1:1), `body_ciphertext` (decrypted by Universal Telegram's own repository behind the ADR-0008 boundary, re-encrypted through Support Chat's own vault), `idempotency_key` (not copied — regenerated deterministically from the source `message_uuid`), `telegram_sender_user_id` (excluded, SENSITIVE-classified, not present in the export shape).

`delivery_state` — **transform to constant, not copied.** Universal Telegram's column carries four live, Telegram-transport-meaningful values (`stored`/`routed`/`sent`/`failed`); Support Chat's identically-shaped column has no transition mechanism and only ever holds `stored`. Every migrated message's `delivery_state` is set to Support Chat's own `'stored'` default, regardless of the source value.

`outbound_message_uuid` / `telegram_message_id` — **excluded entirely, by explicit decision.** Verified purely Universal Telegram-internal outbound-delivery bookkeeping with no cross-plugin correlation requirement anywhere in either repository's code or documentation, including work package 5's own topic-level (not per-message) binding scope. Not present in the ADR-0008 export shape (ADR-0008 §5). Work package 5 must not depend on per-message Telegram delivery correlation.

**`conversation_notes`:** `id` (excluded, preserved via the message map with `kind='note'`), `operator_user_id`/`body_ciphertext`/`created_at` (copied 1:1, same decrypt/re-encrypt treatment as messages).

Drift is CI-enforced: a `LegacyFieldMap::REGISTRY` constant lists every column above with its disposition, and a `SchemaInventoryTest` introspects Universal Telegram's real schema at test time, failing if any real column lacks a registry entry.

### 4.2 Two-phase migration, live-source-safe

- **Phase A (preparatory backfill)** may run repeatedly while Universal Telegram remains live. It is not a one-shot terminal operation: its cursor is a resumable high-water mark (`last_processed_source_id`), and re-invoking it after a prior "completion" resumes scanning source IDs beyond the last-processed one, safely picking up conversations created since the last pass. Every row it produces is marked `backfilled`, an explicitly provisional state.
- **Phase B (final reconciliation + validation)** may only run after `QuiescenceStateProvider::is_quiescent()` (§4.4) returns `true`. Its preflight asserts no source rows exist beyond the map's current high-water mark (requiring one final Phase A pass immediately before Phase B is invoked) — Phase B never silently reconciles an incomplete backfill. For every `backfilled` row, it re-reads current Universal Telegram state via the ADR-0008 export boundary, diffs it against what Phase A copied, applies the delta, and only then promotes the row to `migrated`.
- "Ready for cutover" requires Phase B completion with zero unreconciled/failed rows, all validation passing, and a real (non-default-deny) `QuiescenceStateProvider` result — not merely zero `pending`/`failed` rows from Phase A alone.

### 4.3 Source-to-target correspondence

- `universal_support_chat_legacy_migration_map` (conversation-level): `source_conversation_id`, `source_conversation_uuid` (unique, the idempotency key), `target_conversation_id`/`target_conversation_uuid`, `status` (`pending`/`backfilled`/`migrated`/`skipped`/`failed`), `legacy_bot_id`, `legacy_destination_id`, `legacy_telegram_topic_id`, `legacy_topic_creation_state`, `legacy_topic_lifecycle_state`, `message_count_source/target`, `note_count_source/target`, `validation_passed`, `validated_at`, `error_reason`, `migrated_at`, `created_at`, `updated_at`.
- `universal_support_chat_legacy_migration_message_map` (message/note-level, new): `conversation_map_id`, `kind` (`message`/`note`), `source_id`, `source_uuid`, `target_id`, `target_uuid`, `idempotency_key`, `created_at` — the authoritative, queryable correspondence used to remap `assignee_last_seen_message_id` and to drive Phase B's per-message reconciliation diff.
- `universal_support_chat_legacy_migration_runs`: `run_uuid`, `phase` (`backfill`/`reconcile`), `status`, `dry_run`, `batch_size`, `checkpoint_cursor`, `started_at`/`completed_at`, `created_by_user_id`.
- `universal_support_chat_legacy_migration_batch_log`: `run_id`, `batch_number`, `cursor_start/end`, `rows_processed/migrated/skipped/failed`, `started_at/completed_at`, `error_summary`.
- **Transaction boundary is per-conversation, not per-batch**: each conversation's full transform (its `conversations` row, all its `conversation_messages`/`conversation_notes` rows, its map row, its message-map rows) commits as one database transaction. The run's checkpoint cursor advances only after that commit, so an interruption loses at most one in-flight conversation, which resume simply retries.

### 4.4 `QuiescenceStateProvider` — frozen by ADR-0008, implemented here only as a stub

This plan implements exactly two things against the interface ADR-0008 §6 freezes: a production-registered default-deny stub (`is_quiescent()` always `false`), and an injectable fake used only by this plan's own tests. It does not implement, and this milestone's closure may not claim, a real quiescence signal. Work package 2 is solely responsible for a real implementation, which per ADR-0008 must satisfy this exact interface.

### 4.5 Privacy

No plaintext, and no unkeyed content digest, is persisted anywhere outside the existing encrypted target body fields (`conversation_messages.body_ciphertext`, `conversation_notes.body_ciphertext`) that are already part of Support Chat's normal, vault-protected schema. All `legacy_migration_*` metadata and every WP-CLI log line retain only non-content operational evidence — counts, IDs, timestamps, and boolean `validation_passed`. Content-integrity comparison during validation is transient (fresh decrypt on both sides, compared in memory, only the pass/fail result persisted) — never a bulk concatenated hash, which would be ambiguous across field boundaries, and never stored as a searchable/dictionary-attackable value.

## 5. Directory, namespace, schema, and API impact

- New Support Chat tables (next `db_version`, current target `8`): `legacy_migration_runs`, `legacy_migration_map`, `legacy_migration_message_map`, `legacy_migration_batch_log` (§4.3).
- New Support Chat WP-CLI command namespace: `wp universal-support-chat legacy-migrate {run,status,validate}` (backfill/reconcile phases, dry-run, resume, `--assume-migration-authority` as a mandatory operator-confirmation guard against accidental invocation per ADR-0008 §4 — not a security control).
- New Support Chat interface: `QuiescenceStateProvider` (§4.4), plus its default-deny stub and test fake.
- New Support Chat field-mapping registry: `LegacyFieldMap::REGISTRY` (§4.1) plus `SchemaInventoryTest`.
- No new Contract v1 operation; no new public REST route in either plugin (ADR-0008 §2). No change to `ChannelContract`'s existing surface.
- Universal Telegram: `LegacyExportServiceV1` (ADR-0008 §2–§5) — implemented in Universal Telegram's own repository, its own coordinated follow-up slice, not by this plan.

## 6. Security and privacy impact

Per ADR-0008 in full (export boundary ownership, redaction-at-source, no shared secret, no permanent cross-plugin SQL) and §4.5 above. The actual security boundary is operating-system authority to execute WP-CLI against this install (ADR-0008 §4); `LegacyExportServiceV1` fails closed outside a WP-CLI context, closing every externally reachable path (web, Ajax, REST, cron), and `--assume-migration-authority` is a command-level operator-confirmation guard against accidental invocation, not an independent authentication mechanism — neither claims to restrict what already-authorized WP-CLI-context code can do. No Universal Telegram vault key material ever reaches Support Chat. No Support Chat vault key material ever reaches Universal Telegram.

## 7. Test and CI

- `SchemaInventoryTest` (§4.1) — fails on any Universal Telegram schema column lacking a registered disposition.
- Message-map correctness: `assignee_last_seen_message_id` resolves to the correct target message ID, or `NULL` when the source message was excluded/failed.
- Per-conversation atomicity: a forced mid-conversation failure rolls back that conversation's entire transaction, never a partial write.
- Repeatable Phase A: a second backfill pass picks up conversations created after the first pass's high-water mark.
- Phase B preflight: refuses to start when un-backfilled source rows exist beyond the map's high-water mark.
- `QuiescenceStateProvider` seam: Phase B refuses to run against the default-deny stub; proceeds only against the injected test fake returning `true`.
- Privacy: every `legacy_migration_*` non-ciphertext column contains only IDs/timestamps/booleans/counts in every test fixture, never a content-derived string.
- Field-mapping unit tests: `delivery_state` always `'stored'` regardless of source value; no `outbound_message_uuid`/`telegram_message_id` value appears anywhere in target tables or metadata; `start_idempotency_key` derivation produces no collision across multiple `NULL`-source-key fixtures.
- Dry-run: zero writes to any Support Chat table.
- Idempotency/resume: an interrupted run resumes with zero duplicate messages/notes; re-running a completed phase is a safe no-op.
- Telegram-optionality: migration succeeds identically for a conversation with `topic_creation_state = 'none'` (no completed topic) as for one with a completed topic.
- Scope-exclusion: `ai_drafts`, `ai_config`, `operator_identities`, `operator_availability` are never read by the migration engine.
- Full CI matrix (unit, integration, phpcs, phpstan) per this repository's existing `.github/workflows/ci.yml` conventions; a new `tests/integration/Migration/` suite modeled on the existing dual-plugin `tests/integration/Interop/` harness pattern (Universal Telegram repository), mounting both plugins' real schemas in one disposable WordPress install.

## 8. Work packages

3. **Batch migrator + checkpoints** — Support Chat: `legacy_migration_map`/`legacy_migration_message_map`/`legacy_migration_runs`/`legacy_migration_batch_log` schema; `LegacyFieldMap::REGISTRY`; Phase A backfill engine (resumable, per-conversation-transactional, dry-run, locked against concurrent runs); the `QuiescenceStateProvider` interface plus default-deny stub and test fake; the WP-CLI command shell, gated by the mandatory `--assume-migration-authority` operator-confirmation flag. Universal Telegram: `LegacyExportServiceV1` per ADR-0008 (separate, coordinated repository work, gated on ADR-0008 merging — not performed by this plan). *Gate: ADR-0008 and its Universal Telegram pinning amendment both merged before this work package's implementation begins.*
4. **Validators** — Phase B reconciliation-and-diff engine; count/completeness/ordering/content-integrity validation (§4.5); the "ready for cutover" gate definition (structurally unreachable without a real `QuiescenceStateProvider`, work package 2's future scope); `legacy-migrate status`/`validate` WP-CLI subcommands; the full test suite in §7. *Gate: work package 3 complete.*

## 9. Risks

- Implementing against an unmerged ADR-0008 or an unpinned Universal Telegram export boundary — mitigated by the explicit two-repository merge gate (§8, mirroring ADR-0007 §6's identical pattern).
- A future work package 2 attempting to redefine or bypass `QuiescenceStateProvider` — mitigated by ADR-0008 §6 explicitly freezing and binding the interface; any change requires a new ADR, not an implementation-time reinterpretation.
- Field-mapping drift as Universal Telegram's schema evolves after this plan is written — mitigated by `SchemaInventoryTest` (§4.1, §7) failing CI on any unmapped column, rather than relying on this document staying manually accurate.
- Migration engine mistaken for cutover-ready — mitigated by ADR-0008 §6 and this plan's own closure constraint (§11) explicitly barring that claim.

## 10. Out of scope

Quiescence switches/drains (work package 2, a separate future unit of work — this plan only consumes its future output via the frozen `QuiescenceStateProvider` contract); binding creation for existing Telegram topics (work package 5); atomic route switch (work package 6); soak/rollback runbook execution (work package 7); any production migration execution; any change to Universal Telegram's retention behavior or schedule (per PO decision record item 4); AI drafts/config migration; operator identity/availability migration; Universal Telegram legacy UI/tab decommission; any new visitor-ownership model (per PO decision record item 3); any Contract v1 operation-allow-list change; any public REST route.

## 11. Definition of done

- ADR-0008 and its Universal Telegram pinning amendment both merged (§8 gate).
- Work packages 3–4 implemented and passing the full test suite (§7) in Support Chat's CI.
- Closure record explicitly states, per ADR-0008 §6: Phase B proven only against the controlled test seam; no conversation has been validated as cutover-ready; no real quiescence signal was ever consumed; no production migration was executed.
- Product Owner accepts closure per `docs/governance.md`'s closure-statuses table — the Implementation Agent cannot self-certify.
