# SC-M07 — AI-First Visitor Support — Implementation Plan v1

> Committed code-free together with, and depends on,
> [ADR-0018](../adr/0018-ai-first-visitor-support.md) (**Proposed** in the same SC-M07
> documentation freeze). Per [`docs/governance.md`](../governance.md), this plan is **not
> authorized for implementation** by the freeze. Implementation begins only after a
> **standalone, separately merged** Product Owner implementation-acceptance record at
> `docs/decisions/sc-adr-0018-ai-first-po-acceptance.md` — authored and merged by the Product
> Owner in its own commit — changes ADR-0018's Status to **Accepted** and authorizes
> WP1–WP9 exactly within the frozen scope. Until then ADR-0018 stays **Proposed** and no
> implementation branch, runtime code, schema change, credential, provider call, or
> deployment is permitted. Implementation reports cite this plan's freeze commit SHA and the
> acceptance-record merge SHA.

## 1. References

- Charter: [`docs/milestones/sc-m07-ai-first-visitor-support.md`](../milestones/sc-m07-ai-first-visitor-support.md)
- Requirements: **R4** (site policy + disclosure), **R6** (AI answers routine questions
  before escalating), **R1** (no AI-only Telegram traffic) — [`docs/master-plan.md`](../master-plan.md).
- Introduces: [ADR-0018](../adr/0018-ai-first-visitor-support.md).
- Supersedes the milestones [SC-AI1](../milestones/sc-ai1-operator-ai-drafts-approve-and-send.md)
  and [SC-AI2](../milestones/sc-ai2-controlled-direct-ai-responses.md); relates to
  [SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md) (deferred vector/RAG).
- Relies on / respects:
  [ADR-0012](../adr/0012-automatic-support-chat-to-telegram-dispatch.md) /
  [ADR-0014](../adr/0014-interactive-chat-delivery-class-and-immediate-dispatch.md)
  (async worker + atomic content-free outbox; the `is_mirrored_direction()` predicate),
  [ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
  (availability authority + honest copy),
  [ADR-0016](../adr/0016-support-chat-widget-presentation-settings.md) (plain-text operator
  copy; `.textContent` / `esc_html()`),
  [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) (Settings /
  Diagnostics separation and the §3 redaction boundary),
  [ADR-0003](../adr/0003-security-privacy-and-visitor-isolation.md) (visitor isolation /
  classification),
  [ADR-0004](../adr/0004-migration-and-retention-principles.md) (retention / uninstall
  ownership).
- Test strategy: [`docs/testing/test-strategy.md`](../testing/test-strategy.md).

## 2. Repository findings at plan-drafting time

Verified against `origin/main` at `2170a0fd6e0933e3320112ac3085d348530db895` (plugin `0.8.0`,
`universal_support_chat_db_version` `12`, PHP ≥ 8.1, PHPStan level 5, PHPCS `WordPress-Extra`).

| Area | Finding |
|---|---|
| AI code | **None exists and none ever has** — `git log --all` for `src/AI/*` returns only documentation. `tests/unit/Core/StructuralBoundariesTest.php` asserts `src/AI` does not exist. The retired combined chatbot lived in the separate `universal-telegram` repo (`src/AI/**`, removed there in that repo's ADR-0044); it must not be restored. |
| Conversation messages | `Conversations\ConversationMessage` `direction` is a free-form `VARCHAR(16) NOT NULL` column — no `CHECK`, no enum. Values today: `visitor` / `operator` / `system` (`DIRECTION_*` consts). No author id; attribution is the direction. Bodies AES-256-GCM encrypted via `MessageRepository` + `Core\Security\CredentialVault` (AAD `conversation_message:<uuid>`). `MessageRepository::create()` enforces `UNIQUE (conversation_id, idempotency_key)`. |
| Status map | `Conversations\ConversationStatus::map()` — `new → waiting_for_operator` is legal (ADR-0017). `waiting_for_visitor → waiting_for_operator` is **not** currently legal. Adding an edge is a pure code constant (no schema, no `db_version`). |
| Takeover primitives | `Conversations\ConversationRepository::claim()` / `release()` / `assign()` set `assigned_operator_id` atomically. No Hub takeover UI exists. |
| Visitor REST | `Conversations\Rest\ConversationsController` (`universal-support-chat/v1`): `POST /conversations`, `GET /conversations/mine`, `POST /conversations/{uuid}/messages`, `GET /conversations/{uuid}`. Every handler calls `authenticate_session()` (`is_user_logged_in()` + `wp_rest` nonce); no capability check; per-conversation ownership; uniform 404. Responses carry `availability`. `MAX_TEXT_CHARS` 4096. |
| Async worker | `TelegramDispatch\DispatchEnqueuer::persist_and_enqueue()` writes message + content-free outbox row in one transaction, then `DispatchWorker::request_immediate_run()` (`wp_schedule_single_event(now)` + `spawn_cron()`, non-throwing). `DispatchWorker`: recurring 60 s sweep + one-off immediate hook, `BATCH_LIMIT` 25, `BACKOFF = [60,120,300,900,1800,3600]`, lease columns. `DispatchEnqueuer::is_mirrored_direction()` returns true only for `visitor` + `operator`. |
| Constrained dispatcher | `ChannelContract\Rest\ContractOperationDispatcher::dispatch()` — `switch` over a hard-coded allow-list (`ChannelContract\Auth\ContractOperations` fixed `const` arrays), fails closed, never leaks existence. |
| Vault | `Core\Security\CredentialVault` — `encrypt($plaintext, $context)` (throws when no key), `decrypt(): CredentialResult` (never throws), `reencrypt()`. `ChannelContract\Auth\OwnKeyManager` stores an Ed25519 key vault-encrypted (context `contract.own_signing_key`) in option `universal_support_chat_contract_own_key_secret` (`autoload = false`) with `ensure_key_pair()` / `rotate()` / `secret_key_raw()` / `delete()`. |
| Settings | `Core\Configuration\Settings` — sole owner of fixed-shape `universal_support_chat_settings` (13 keys). `defaults()` / `sanitize()` fixed-shape, unknown keys dropped. ADR-0016/0017: later milestones add keys additively; ADR-0017 added an atomically-validated section. `Administration\Settings\SupportChatSettingsPage` — WP Settings API, hidden `0` companion per control, `option_page_capability_*` → `Core\Capabilities\CapabilityRegistrar::MANAGE` (`universal_support_chat_manage`, admin only). |
| Runtime toggle not in Settings API | `Availability\Admin\OverrideAction` — dedicated autoloaded option, `admin_post` action with its own `check_admin_referer()` + `current_user_can( MANAGE )`, audit event, redirect-with-notice. |
| Diagnostics | `Administration\Diagnostics\DiagnosticsPage` — read-only, no form/input; renders only booleans, fixed enum labels, integer counts, version string (ADR-0015 §3). |
| Audit | `Audit\AuditLogger::record()` — never throws; fail-closed redaction (unmapped dropped, `SECRET` dropped, `SENSITIVE` → `***`); `actor_type` ∈ `system` / `operator` / `adapter`; `<area>.<event>` naming; bodies never audited. `Privacy\Classification` = PUBLIC / INTERNAL / SENSITIVE / SECRET. |
| Availability | `Availability\AvailabilityService` (ADR-0017) — `resolve_state()`, `is_unavailable()`, `offline_message()`, `online_indicator_enabled()`. No public availability endpoint. ADR-0017 §5 honesty boundary. |
| Widget | `assets/js/chat-widget.js` — all dynamic text via `.textContent`, never `innerHTML` (static test; ADR-0016 §2); dedupes on `seenMessageIds`; non-modal `role="dialog"` with SC-M05 focus / RTL / mobile sheet / reduced-motion. `ChatWidget\WidgetAssets` server-renders the shell + `wp_localize_script('uscChatWidget', …)`. |
| Persistence | `Persistence\Migrator` — numbered `[run, verify]` steps, raw `$wpdb->query()` DDL (never `dbDelta`); `target_version()` is `12`; a failed step → `Persistence\SchemaHealth::mark_unavailable()` degrades the whole plugin. `Conversations\RetentionCleanupHandler` (daily WP-Cron) — inactive → resolved → archived → body-null → purge; purges the dispatch outbox for purged conversations. `Core\Lifecycle\Uninstaller` — drops tables / deletes options only under `remove_data_on_uninstall`. |
| Outbound HTTP | **No `wp_remote_*` anywhere in `src/`** (grep → zero). The AI provider adapter is the first. No rate limiter and no model-configuration pattern exist. |
| Structural guards | `tests/unit/Core/StructuralBoundariesTest.php` — `AI` in the unauthorized-boundary list. `tests/unit/Core/NoTelegramCouplingTest.php` — fails on literal `WooCommerce`, `ActionScheduler`, `as_schedule_`, `UniversalTelegram\`, `universal_telegram_` in `src/`. |
| CI | `.github/workflows/ci.yml`: `phpcs`, `static-analysis`, `unit` (PHP 8.1/8.3/8.4), `integration-wp-only-floor` (WP 6.9/PHP 8.1), `integration-wp-only-current` (WP 7.1/PHP 8.3), `docs` (`check-doc-links`), `interop` ×2. `tests/bin/install-wp.sh <wp> [wc-version]` can install WooCommerce (unused; **not needed** — order lookup deferred). |

## 3. Assumptions and open questions (not decisions)

- **Assumption:** the escalation path may need `waiting_for_visitor → waiting_for_operator`;
  WP6 confirms the exact source statuses in play and adds that single map edge only if
  required (pure code constant).
- **Assumption:** the OpenAI Chat Completions / Responses request shape is stable enough that
  one adapter with a pinned API path is sufficient for v1; the interface isolates any later
  change.
- **Assumption:** `wp_safe_remote_post` honouring `ai_request_timeout_seconds` plus the async
  worker's lease is sufficient DoS containment; no queue backpressure beyond the caps is
  planned for v1.
- **Open (non-blocking) Product Owner questions** — §22; every one has a recommended default
  and none blocks the work-package structure.

## 4. Architectural decisions (with alternatives — cite ADR-0018)

All ownership, lifecycle, direction-value, tools-forbidden, knowledge-boundary,
credential-storage, persistence, provenance, verification-boundary, and safety decisions are
frozen in [ADR-0018 §§1–11](../adr/0018-ai-first-visitor-support.md) and its Alternatives
section. This plan realises them. Plan-level design choices:

- **D1 — `src/AI/` layout.** `AI\Provider\` (`AiProvider`, `AiRequest`, `AiResult`,
  `AiFinishReason`, `AiErrorClass`, `FakeProvider`, `OpenAiChatProvider`,
  `ProviderKeyManager`), `AI\Knowledge\` (`KnowledgeSource`, `KnowledgeSourceRepository`,
  `KnowledgeIndexer`, `KnowledgeRetriever`), `AI\Policy\` (`AiSystemPolicy`, `PromptAssembler`),
  `AI\Turn\` (`AiTurn`, `AiTurnRepository`, `AiTurnEnqueuer`, `AiTurnWorker`,
  `AiTurnRateLimiter`, `HandoffReason`, `EscalationDecider`), `AI\Admin\`
  (`AiSettingsSection`, `ProviderKeyAction`, `KnowledgeAdminPage`, `TakeoverAction`,
  `HubAiPanel`, `AiDiagnosticsBlock`). Wired in `src/Core/Plugin.php`; each class exposes a
  `register()` where it owns hooks, matching the existing composition style.
- **D2 — Provider interface never throws for provider-side failure.**
  `AiProvider::generate( AiRequest ): AiResult`; a transport error, timeout, HTTP error, or
  malformed body is an `AiResult` with `error_class` set. Only a programming error throws.
- **D3 — `FakeProvider` is the only provider in CI.** It is deterministic and scriptable
  (answer / refusal / uncertainty / `needs_human` / timeout / malformed / injection attempt).
  A separate env-var-guarded, non-CI script may hit the real OpenAI endpoint for manual
  verification.
- **D4 — Provider HTTP is confined.** `OpenAiChatProvider` is the only file that calls
  `wp_safe_remote_post`; a structural test asserts `wp_*remote_*` appears in `src/` only
  under `src/AI/Provider/`.
- **D5 — Credential.** `ProviderKeyManager` mirrors `OwnKeyManager`: `set( string $token )`,
  `rotate( string $token )`, `clear()`, `token(): ?string` (decrypt; returns `null`
  fail-closed), `is_configured(): bool`. Vault AAD context `ai.provider_api_key`; opaque
  envelope in `autoload = false` option `universal_support_chat_ai_provider_secret`. Written
  only by `ProviderKeyAction` (`admin_post`, own nonce + `MANAGE`), which emits
  `ai.token_rotated` (no secret material).
- **D6 — Additive settings, clamped.** The nine keys of ADR-0018 §8 added to `defaults()` /
  `sanitize()` with fixed-shape docblocks and an `AI_ALLOWED_MODELS` constant.
  `sanitize()` clamps every numeric key to `[min, max]` and every enum to the allow-list;
  an out-of-range or unknown value becomes the default and is never persisted verbatim.
  `ai_disclosure_text` is `sanitize_textarea_field` + length cap. Each control has a hidden
  companion.
- **D7 — `ai` direction value.** `ConversationMessage::DIRECTION_AI = 'ai'`. Poll
  serialisation maps `ai → author_label 'AI assistant'`. `is_mirrored_direction()` is **not**
  touched. Widget `appendMessage` gains an `ai` branch (distinct bubble class + "AI
  assistant" label), still `.textContent`. Hub transcript rendering maps `ai` to "AI
  assistant".
- **D8 — Atomic visitor-message + AI-turn write.** `AiTurnEnqueuer::persist_and_enqueue()`
  reuses the `DispatchEnqueuer` transaction shape: message row + (dispatch outbox row when
  enabled) + `ai_turns` row in one `START TRANSACTION … COMMIT/ROLLBACK`, then a non-blocking
  `AiTurnWorker::request_immediate_run()`. The controller calls the AI enqueuer instead of
  the plain message persist **only** when `ai_enabled` and `ProviderKeyManager::is_configured()`
  and the conversation is unclaimed and not handed off; otherwise the existing path is
  byte-identical.
- **D9 — Async worker.** `AiTurnWorker` mirrors `DispatchWorker`: recurring sweep hook +
  one-off immediate hook, `ensure_scheduled()` on `init`, `Uninstaller` unschedules, bounded
  batch, lease columns on `ai_turns` for crash recovery, `ai_max_retries` +
  `BACKOFF`-style delays for transient `AiErrorClass` values, terminal handoff on exhaustion.
- **D10 — Escalation state machine.** `EscalationDecider` maps a turn outcome
  (`answered` / `refused` / `uncertain` / `needs_human` / `safety` / `provider_failed` /
  `unsupported` / `rate_limited`) to either "post the `ai` answer" or "hand off with
  `HandoffReason::X`". A handoff: writes a plain `system` message (honest, availability-aware
  via `AvailabilityService`), transitions to `waiting_for_operator`, records
  `handoff_reason` on the turn, emits `ai.handoff` (`ai.escalation` for `safety`), and sets a
  conversation-level "AI stopped" marker that `AiTurnEnqueuer` and `AiTurnWorker` check.
- **D11 — Knowledge persistence.** Exactly ADR-0018 §9: `knowledge_sources` table (step 13);
  `KnowledgeSourceRepository` encrypts the canonical snapshot via `CredentialVault` (AAD
  `knowledge_source:<source_uuid>`) and decrypts the bounded `approved` set into memory only;
  `KnowledgeIndexer` snapshots via `strip_shortcodes()` + `do_blocks()` +
  `wp_strip_all_tags()` + whitespace norm on `save_post` / `wp_trash_post` / `deleted_post` +
  a daily re-checksum sweep + a manual "Reindex" `admin_post`; revoke NULLs the ciphertext
  and sets `revoked`; operator remove hard-deletes; `Uninstaller` drops the table under the
  opt-in.
- **D12 — Retriever.** `KnowledgeRetriever` — pure keyword-overlap ranking (tokenise the
  last visitor message, score approved snapshots by normalised overlap, take the top-N within
  `ai_max_context_chars`). No vectors, embeddings, chunking, or semantic search. Returns the
  ordered `KnowledgeSource` set plus the exact text used per source (for `source_checksums`).
- **D13 — Prompt assembly.** `AiSystemPolicy` text is a constant plus a few interpolated
  facts (business name; "order lookup available: no"; current availability state) — a unit
  test asserts it does not vary with visitor text, retrieved content, or other settings.
  `PromptAssembler` places the transcript (trimmed to budget) and the retrieved knowledge as
  clearly fenced `user`-role data blocks, never as system/developer instructions.
- **D14 — Hub AI panel + takeover.** `TakeoverAction` (`admin_post`, own nonce + `MANAGE`) →
  `ConversationRepository::claim()`, emits `ai.takeover`. `HubAiPanel` on the conversation
  detail page renders only: AI status (enabled/stopped/handed-off), last `finish_reason` /
  `outcome` / `handoff_reason` label, `tool_calls_count` (0 in v1), token totals, last
  `provider_error_class`, and `source_ids` resolved to the current `label` with a "same
  text / content changed since this turn" flag from `source_checksums` vs `content_checksum`.
- **D15 — Diagnostics block.** `AiDiagnosticsBlock` — enabled/disabled; provider configured
  yes/no + fail-closed probe; `ai_model` label; knowledge sources by status count; AI turns
  today vs cap; handoffs today; last outcome / last provider error class. Booleans, enum
  labels, and counts only (ADR-0015 §3).

## 5. Directory, namespace, schema, and API impact (scoped)

- **New namespace `UniversalSupportChat\AI`** — the classes in D1. `docs/ARCHITECTURE.md` AI
  row becomes authorized; `StructuralBoundariesTest` removes `AI` from the unauthorized list
  and adds the provider-HTTP-confinement and `is_mirrored_direction('ai') === false`
  assertions.
- **`src/Conversations/ConversationMessage.php`** — `DIRECTION_AI = 'ai'` const; label
  mapping.
- **`src/Conversations/ConversationStatus.php`** — at most one added edge
  (`waiting_for_visitor → waiting_for_operator`), only if WP6 needs it.
- **`src/Conversations/Rest/ConversationsController.php`** — inject the AI enqueuer + gate;
  additive `ai_pending` field on the poll response.
- **`src/Core/Configuration/Settings.php`** — nine additive keys + docblocks +
  `AI_ALLOWED_MODELS` + clamping helpers.
- **`src/Core/Lifecycle/Uninstaller.php`** — drop `ai_turns` + `knowledge_sources`, delete
  `universal_support_chat_ai_provider_secret`, unschedule `AiTurnWorker` — all under
  `remove_data_on_uninstall` (the worker unschedule always).
- **`src/Conversations/RetentionCleanupHandler.php`** — purge `ai_turns` rows for a purged
  conversation (never `knowledge_sources`).
- **`src/Persistence/Migrator.php`** — step 13 `[run, verify]`; `target_version()` `12 → 13`.
- **`src/ChatWidget/WidgetAssets.php`** + **`assets/js/chat-widget.js`** +
  **`assets/css/chat-widget.css`** — `ai` bubble, one-time disclosure line, `ai_pending`
  state.
- **`src/Administration/Conversations/ConversationDetailPage.php`** (+ Hub actions) — AI
  panel + "Take over from AI".
- **`src/Administration/Settings/SupportChatSettingsPage.php`** — "AI Assistant" section.
- **`src/Administration/Diagnostics/DiagnosticsPage.php`** — AI block.
- **New "AI Knowledge" Hub submenu** — `AI\Admin\KnowledgeAdminPage`.
- **`src/Core/Plugin.php`** — wire all of the above.
- **Schema:** `db_version` `12 → 13`; two new tables (§6). **REST:** no new route; only the
  additive `ai_pending` field. **Capability:** none new (`MANAGE`). **Version constant:**
  `UNIVERSAL_SUPPORT_CHAT_VERSION` `0.8.0 → 0.9.0` (asset cache-bust; no release/tag).

## 6. Schema — migration step 13

Two tables, raw `$wpdb` DDL, `[run, verify]`:

### `universal_support_chat_ai_turns` — metadata only

Columns (illustrative; the frozen rule is the `verify()` contract below): `id` BIGINT
UNSIGNED PK AUTO_INCREMENT; `turn_uuid` CHAR(36) UNIQUE; `conversation_id` BIGINT UNSIGNED
(FK-style, indexed); `visitor_message_id` BIGINT UNSIGNED NULL; `ai_message_id` BIGINT
UNSIGNED NULL; `status` VARCHAR(16) (`queued` | `running` | `answered` | `handed_off` |
`skipped` | `failed`); `outcome` VARCHAR(24) NULL; `finish_reason` VARCHAR(24) NULL;
`handoff_reason` VARCHAR(32) NULL; `provider_error_class` VARCHAR(32) NULL; `attempts`
SMALLINT UNSIGNED; `prompt_tokens` INT UNSIGNED NULL; `completion_tokens` INT UNSIGNED NULL;
`latency_ms` INT UNSIGNED NULL; `source_ids` VARCHAR(255) NULL (comma-joined integer ids);
`source_checksums` VARCHAR(255) NULL (comma-joined SHA-256 hex prefixes); `claimed_at`
DATETIME NULL; `lease_expires_at` DATETIME NULL; `available_at` DATETIME NULL; `created_at`
DATETIME; `updated_at` DATETIME.

**`verify_step_13` — `ai_turns` half (frozen):** the table exists; and **no** column name
matches `/(body|prompt|response|message_text|content|plaintext|ciphertext|transcript)/`
(note `visitor_message_id` / `ai_message_id` are id references and pass — the regex targets
free-text/content names, not `*_id`). The prompt is never persisted; the answer is only an
`ai` `ConversationMessage`.

### `universal_support_chat_knowledge_sources` — encrypted content only

Columns exactly as ADR-0018 §9: `id`; `source_uuid` CHAR(36) UNIQUE; `source_type`
VARCHAR(16); `post_id` BIGINT UNSIGNED NULL; `label` VARCHAR(191); `indexed_text_ciphertext`
MEDIUMTEXT NULL; `content_checksum` CHAR(64); `status` VARCHAR(16); `approved_by` BIGINT
UNSIGNED; `approved_at` DATETIME; `last_indexed_at` DATETIME NULL; `created_at` DATETIME;
`updated_at` DATETIME.

**`verify_step_13` — `knowledge_sources` half (frozen):** the table exists; column
`indexed_text_ciphertext` **is present**; and **no** column name matches a *plaintext*
content pattern
(`/^(indexed_text|body|raw_content|plaintext|content|snippet_text)$/`) and **no** visitor/PII
column is present (`owner_user_id`, `user_email`, `conversation_id`, `message_uuid`).

`db_version` advances to `13` only after both halves of `run` and both halves of `verify`
succeed; a failure calls `SchemaHealth::mark_unavailable()` as every existing step does.
Downgrade: the tables become inert; existing conversation rows, messages (including any
`ai`), and statuses stay valid.

## 7. Security and privacy impact

Per [ADR-0018 "Security and privacy impact"](../adr/0018-ai-first-visitor-support.md).
Summary: authenticated-only visitor surface unchanged, no new route; `MANAGE` gates every
admin action, each with its own nonce; the AI performs no mutation (zero tools);
server-owned input-independent policy with retrieved content and visitor text fenced as
data; inert-text output only (`.textContent` / `esc_html()`); the OpenAI token is
vault-encrypted (`ai.provider_api_key`) in an `autoload = false` option, never rendered,
never audited, fail-closed; `ai` message bodies and knowledge snapshots encrypted at rest;
`ai.*` audit rows and `ai_turns` hold only ids / counts / enums; DoS bounded by the async
worker plus per-user / per-conversation / daily caps and per-request timeout/token limits;
handoff copy never claims a human is online unless `AvailabilityService` says so; zero
AI-only Telegram traffic proven by the unchanged `is_mirrored_direction()` predicate and an
interop assertion; disabled by default.

## 8. Test and CI impact

- New `tests/unit/AI/` (provider contract vs `FakeProvider`; `AiSystemPolicy`
  input-independence; `PromptAssembler` fencing + transcript trim; `KnowledgeRetriever`
  ranking / budget / stale + revoked exclusion; `KnowledgeSource` encrypt→decrypt round-trip
  + canonical-text extraction; `AiTurnRateLimiter` → handoff not error; `HandoffReason` /
  `AiFinishReason` mapping; redaction of every `ai.*` audit context;
  `is_mirrored_direction('ai') === false`).
- New `tests/integration/AI/` (migration step 13 both halves; encrypted knowledge
  persistence; settings clamp + round-trip; `ProviderKeyAction` set/rotate/clear + bad
  nonce / missing `MANAGE`; atomic visitor-message + turn write + forced-failure rollback;
  worker retry / backoff → terminal handoff; every escalation branch; takeover skips
  subsequent turns; duplicate visitor-message delivery → one turn; retention purges
  `ai_turns`; uninstall removes tables + secret under the opt-in; Diagnostics + Hub panel
  safe aggregates).
- Security tests (unauthenticated / non-owner cannot cause or read an AI turn — uniform 404;
  prompt injection in visitor text and in retrieved content — assert no state change, no
  system-prompt leak in the stored answer; mutation attempts have no effect; key never
  leaks to any screen; duplicate turns; provider timeout).
- Browser tests on the existing SC-M05 harness (disclosure line present + announced via
  `aria-live`; `ai` bubble distinct; `ai_pending` state; handoff state; axe 0 violations in
  widget scope; `.textContent` rendering; no "online" claim when unavailable; RTL; mobile;
  reduced-motion).
- Structural: `StructuralBoundariesTest` (authorize `src/AI/`; assert
  `is_mirrored_direction('ai') === false`; assert `wp_*remote_*` only under
  `src/AI/Provider/`); `NoTelegramCouplingTest` stays green (no Telegram symbol, no
  `ActionScheduler`, no `WooCommerce` in the AI path).
- `docs/testing/test-strategy.md` gains the AI suites and the structural "no real provider
  calls in CI" rule (unit has no HTTP; integration wires `FakeProvider`; the boundary test
  confines `wp_*remote_*`).
- All existing CI gates run and must be green: `phpcs`, `static-analysis`, `unit`
  (PHP 8.1/8.3/8.4), `integration-wp-only-floor` (WP 6.9/PHP 8.1),
  `integration-wp-only-current` (WP 7.1/PHP 8.3), `docs`, `interop` ×2. **No WooCommerce CI
  job is added** (order lookup deferred). Interop must stay green with Universal Telegram
  unchanged.

## 9. Work packages (execution order, with stop points)

| WP | Scope | Stop point |
|---|---|---|
| **WP1** | Migration step 13: `ai_turns` (metadata-only) + `knowledge_sources` (encrypted-content-only), `[run, verify]`; `db_version` `12 → 13`; `SchemaHealth` failure path. `AiTurnRepository` + `KnowledgeSourceRepository` with safe count helpers. Extend `Uninstaller` (under `remove_data_on_uninstall`) and `RetentionCleanupHandler` (`ai_turns` only). | Migration unit + integration green; **both** `verify_step_13` halves pass (metadata-only regex on `ai_turns`; `indexed_text_ciphertext` required + no plaintext/PII column on `knowledge_sources`); downgrade leaves conversation rows / messages / statuses valid. Commit. |
| **WP2** | `AI\Provider\AiProvider` + `AiRequest` / `AiResult` + `AiFinishReason` / `AiErrorClass` + `FakeProvider`; `OpenAiChatProvider` via `wp_safe_remote_post`; `ProviderKeyManager` (vault AAD `ai.provider_api_key`, `autoload = false` secret option). | Unit: provider contract (answer / refusal / uncertainty / timeout / malformed / usage); key round-trip + fail-closed when the vault key is unavailable; structural test — `wp_*remote_*` only under `src/AI/Provider/`. Commit. |
| **WP3** | Additive `Settings` keys + docblocks + `AI_ALLOWED_MODELS`; "AI Assistant" section on `SupportChatSettingsPage` (hidden companions); `ProviderKeyAction` (`admin_post`, `MANAGE`, dedicated nonce, set/rotate/clear); `ai.config_changed` / `ai.token_rotated` audit. | Integration: key round-trip; malformed values → clamped defaults, never persisted verbatim; section renders; bad nonce / missing `MANAGE` rejected; key never rendered back. Commit. |
| **WP4** | `ConversationMessage::DIRECTION_AI`; poll `author_label` mapping + additive `ai_pending`; widget `ai` bubble + one-time disclosure line (server-localised `ai_disclosure_text`), `.textContent` only; Hub `ai` transcript rendering. | Unit: `is_mirrored_direction('ai') === false`; label mapping. Widget static test (no `innerHTML`). Browser: `ai` bubble distinct + disclosure announced via `aria-live`; SC-M05 focus / RTL / mobile / reduced-motion unaffected. Commit. |
| **WP5** | Knowledge (ADR-0018 §9): `KnowledgeSourceRepository` (encrypt via `CredentialVault` AAD `knowledge_source:<source_uuid>`; decrypt bounded `approved` set into memory only); `KnowledgeIndexer` (canonical snapshot; `save_post` / `wp_trash_post` / `deleted_post` hooks + daily re-checksum sweep + manual "Reindex" `admin_post`); `KnowledgeRetriever` (keyword-overlap ranking + char budget); "AI Knowledge" Hub submenu (approve published non-password posts/pages; snippet CRUD; per-row remove); revoke → NULL ciphertext + `revoked` tombstone; operator remove → hard-delete; `ai.knowledge_source_changed` audit (ids/op only). | Unit: ranking / budget / stale + revoked excluded; encrypt→decrypt round-trip; canonical-text extraction strips shortcodes/blocks/HTML. Integration: draft / private / password-protected / trashed cannot be approved or retrieved; checksum change → `stale`; revoke NULLs the ciphertext; operator remove hard-deletes; nothing plaintext written back; uninstall drops the table only under the opt-in. Commit. |
| **WP6** | `AiTurnEnqueuer` (atomic visitor-message + turn row, `DispatchEnqueuer` shape); `AiTurnWorker` (WP-Cron recurring + immediate hook + `request_immediate_run`, lease-based recovery); `AiTurnRateLimiter` (per-user transient + cooldown, per-conversation lifetime cap, daily cap); `AiSystemPolicy` + `PromptAssembler` (fenced knowledge data block, transcript trim); `EscalationDecider` + the full handoff state machine incl. the transition-map edge if needed; `ai` message write with idempotency key `"ai-turn:<turn_uuid>"`; worker records `source_ids` + `source_checksums` from the exact retrieved set; controller gate (`ai_enabled` + configured + unclaimed + not handed off). | Integration: every escalation branch; atomic rollback on illegal transition; takeover skips subsequent turns; duplicate visitor-message delivery → one turn; provider timeout → retry / backoff → terminal `provider_failed` handoff; `ai_enabled = false` → zero `ai_turns`, byte-identical legacy behaviour; retention purges `ai_turns`; interop suite still green (no adapter change; `is_mirrored_direction` unchanged). Commit. |
| **WP7** | `TakeoverAction` (`admin_post`, `MANAGE`, nonce → `ConversationRepository::claim()`, `ai.takeover`); `HubAiPanel` on the conversation detail page (enums / counts / token totals / provider error class / `source_ids` → current `label` + "same text / content changed since this turn"); Waiting-view handoff badge. | Integration: takeover sets `assigned_operator_id` + audit; panel renders enums / counts / source labels only — redaction test asserts no prompt, answer, key, id, timestamp, or raw provider error. Commit. |
| **WP8** | `AiDiagnosticsBlock` (safe aggregates only — enabled/disabled; configured yes/no + fail-closed probe; model label; sources by status; turns today vs cap; handoffs today; last outcome / last provider error class); `ai.*` audit wiring review + redaction tests for every `ai.*` path. | Integration: block renders booleans / enum labels / counts only; no credential, prompt, response, timestamp, identifier, or raw error. Commit. |
| **WP9** | Wire everything in `src/Core/Plugin.php`; `UNIVERSAL_SUPPORT_CHAT_VERSION` `0.8.0 → 0.9.0` (asset cache-bust; no release/tag); structural-test updates (`StructuralBoundariesTest`, `NoTelegramCouplingTest`); `docs/testing/test-strategy.md`; implementation-doc / milestone updates permitted by this plan; full local gate run; open the implementation PR (left open, unmarked for merge; no closure record). | All CI green (PHPCS, PHPStan L5, unit ×3, integration ×2, interop ×2, doc-links); PR opened citing the freeze SHA and the acceptance-record SHA. |

WP1–WP5 are inert without WP6 **and** an operator setting `ai_enabled` **and** a configured
provider key. Only WP6 changes visitor-visible runtime behaviour, and only when the feature
is fully enabled. Each WP is independently revertible; commit each separately.

## 10. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A regression calls the provider synchronously in the visitor request | The controller only ever calls `AiTurnEnqueuer` (transaction + cron kick); an integration test asserts no `wp_remote_*` call occurs during `POST /messages`; the structural test confines provider HTTP to `src/AI/Provider/` (called only by the worker). |
| Prompt injection via retrieved content or visitor text | Server-owned input-independent `AiSystemPolicy`; fenced `user`-role data blocks; `FakeProvider`-scripted injection tests assert no state change and no policy leak; zero tools so nothing is actionable. |
| An `ai` message reaches Telegram | `is_mirrored_direction()` is not changed; a unit test and the interop suite assert `ai` is never mirrored. |
| Runaway spend | `ai_daily_request_cap` + `ai_per_conversation_turn_cap` + per-user cooldown + `ai_max_output_tokens` + `ai_request_timeout_seconds`; every breach is an honest handoff; token totals surfaced in Diagnostics + Hub panel. |
| Knowledge source leaks unapproved or private content | Only `approved` rows are decrypted; `KnowledgeIndexer` refuses draft / private / password-protected / trashed; content is copied at approval, not read live; hard exclusions enforced in the repository. |
| Credential exposure | Vault-encrypted, `autoload = false`, never rendered, never audited, fail-closed; rotate/clear via the `admin_post` action. |
| Silent restoration of retired Universal Telegram AI code | New namespace, new tests, code review against ADR-0018; `NoTelegramCouplingTest` stays green; no `UniversalTelegram\` reference. |
| Migration verification conflict between "metadata-only" and "knowledge needs content" | Table-specific `verify_step_13` (ADR-0018 "Schema verification boundary") — `ai_turns` forbids content columns; `knowledge_sources` requires `indexed_text_ciphertext` and forbids plaintext/PII. |
| DEV checkout is bind-mounted | All implementation work happens in a separate clone; no deploy is part of this milestone. |

## 11. Out of scope

- Embeddings, vector store, semantic search, chunking, ingestion pipeline — SC-AI3. The v1
  knowledge system is **not** "RAG".
- Any tool / function-calling action or side effect: coupons, rebates, refunds, discounts,
  order changes, order creation, account changes, profile/role/option/post/comment writes.
- Any WooCommerce / order / customer-data read; guest order lookup; email-based identity
  linking — a follow-up milestone with its own ADR + plan + PO approval.
- Multi-provider, provider failover, a non-OpenAI adapter.
- Operator-draft "approve and send" co-pilot UX (former SC-AI1).
- A per-message visitor AI opt-in checkbox (R4 forbids); an AI-generated greeting on
  conversation open.
- Promised response-time / SLA / ETA copy.
- Any new public / unauthenticated REST route; a public availability endpoint.
- Re-enabling AI on a conversation after handoff or takeover (one-way for v1 — §22).
- Any Universal Telegram or Contract v1 change; any Telegram notify/dispatch mechanism.
- DEV or production deployment; a live API key; enabling AI on a live site; a plugin release
  or tag; a closure record as part of the implementation PR.

## 12. Definition of done (matches charter acceptance criteria)

- **R4 / charter:** AI is enabled only by an authorized operator setting `ai_enabled` and
  configuring a provider key; the visitor sees a one-time disclosure; there is no visitor
  opt-in checkbox.
- **R6 / charter:** with AI enabled, a routine question is answered from approved content
  without an operator; every handoff trigger produces a `waiting_for_operator` conversation
  with an honest visitor-visible message and a recorded `handoff_reason`.
- **R1 / charter:** with Telegram dispatch enabled and an adapter paired, AI-only turns
  produce zero channel ensure/deliver calls (interop suite green; `is_mirrored_direction()`
  unchanged).
- **Charter:** an operator can take over from the Hub; once claimed, all queued and future
  AI turns are skipped and the operator sees the full transcript and the AI handoff reason.
- **Charter:** the AI performs no mutation; order/account questions always hand off;
  Diagnostics and the Hub AI panel expose only safe aggregates.
- With `ai_enabled` off, visitor behaviour is byte-identical to pre-SC-M07 and no `ai_turns`
  rows or provider calls occur.
- `universal_support_chat_db_version` is `13`; plugin version `0.9.0` (no release/tag); no
  new PHP warnings/fatals; all CI gates green; interop green with Universal Telegram
  unchanged.

## 13. DEV vs production acceptance (kept separate — not part of implementation)

### DEV acceptance (after the implementation PR, on a deliberate operator decision)

1. Deploy via the existing bind-mounted checkout; confirm `db_version` `13`, plugin `0.9.0`,
   no new web-container PHP warnings/fatals, homepage + wp-admin health checks pass.
2. Operator sets the OpenAI key, approves a few knowledge sources, and enables `ai_enabled`.
3. Run scripted conversations covering every escalation branch; confirm the AI answers
   routine questions from approved content, and that handoff wording is honest and
   availability-aware (ADR-0017 §5).
4. Confirm operator takeover from the Hub stops the AI and shows the full transcript + handoff
   reason.
5. **Telegram:** with dispatch enabled and the `universal-telegram` peer paired, confirm
   AI-only turns produce zero Telegram traffic (no topic ensure, no deliver); confirm the
   Telegram dispatch setting and the active peer are unchanged.
6. Confirm knowledge deletion semantics on DEV (unpublish → revoked tombstone; edit → stale;
   operator remove → hard-delete).
7. Cost / rate-limit monitoring = Diagnostics counters + `ai.*` audit events.

### Production acceptance

Explicitly out of scope for SC-M07. A separate, later Product Owner decision; no release or
tag is created by this milestone's documentation or implementation.

## 14. Product Owner decisions

Four decisions are **already taken** (recorded here for the acceptance record):

| # | Question | Decision (taken) |
|---|---|---|
| A | Roadmap slot | **New milestone SC-M07**, superseding SC-AI1 + SC-AI2 (status lines only). SC-AI3 (vector RAG) stays deferred. |
| B | Provider | **OpenAI** — one concrete adapter behind a provider-neutral interface. |
| C | Knowledge retrieval | **Bounded keyword retrieval** over an administrator-approved allow-list. Not embeddings. Not called "RAG". Genuine vector retrieval stays deferred to SC-AI3. |
| D | Order lookup | **Deferred entirely** to a follow-up milestone. SC-M07 has zero WooCommerce integration and always hands off order/account questions. |

Open (non-blocking) decisions — each with a recommendation; none blocks the work-package
structure:

| # | Question | Recommendation |
|---|---|---|
| 1 | Default `ai_model` and the `AI_ALLOWED_MODELS` list | The cheapest capable OpenAI model with a large context window as default; one stronger model also on the list. PO names the exact model ids in the acceptance record. |
| 2 | Spend-cap unit | Request-count `ai_daily_request_cap` + surfaced token totals (no billing feed available). |
| 3 | `temperature` | Fixed low value in the adapter; not an operator setting. |
| 4 | AI greeting turn on conversation open | No — static greeting + one-time disclosure line; no AI-generated opener. |
| 5 | Per-conversation turn cap semantics | Lifetime cap → then always hand off (not a rolling window). |
| 6 | Re-enabling AI after handoff / takeover | Out of scope for v1 — one-way for the conversation's life; no "resume AI" action. |
| 7 | In-flight turn on takeover | Accept one trailing AI message (the worker re-checks before the provider call; a turn already past that check completes). |
| 8 | Snippet store | Reuse the `knowledge_sources` table for operator snippets (`source_type = 'snippet'`); no dedicated CPT. |
| 9 | Visitor-facing source citations | Operator-visible provenance only for v1; sources are not shown to the visitor. |
| 10 | Knowledge admin location | A dedicated "AI Knowledge" Hub submenu (not a Settings-page section). |
| 11 | Plugin version | `0.9.0` (asset cache-bust; no release/tag). |
| 12 | Safety-sensitive category list | PO ratifies the exact fixed server-side list in the acceptance record (e.g. self-harm, threats/violence, legal, medical, payment disputes / chargebacks / fraud, account-security). |
| 13 | `needs_human` / uncertainty signal | Whether v1 relies on a structured model output field, a confidence heuristic, or both — PO confirms in the acceptance record. |
| 14 | Revoked knowledge source | Keep as a labelled `revoked` tombstone (ciphertext NULLed) so historical `ai_turns.source_ids` still resolve to a name — rather than hard-deleting on revoke and showing old provenance as "(removed)". |
| 15 | Per-turn provenance fidelity | Store `ai_turns.source_checksums` alongside `source_ids` so the Hub can distinguish "same text" from "changed since this turn" — rather than id-only provenance. |

No open decision blocks the freeze or the work-package structure.
