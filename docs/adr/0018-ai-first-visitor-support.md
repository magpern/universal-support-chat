# ADR-0018 — AI-first visitor support: grounded, read-only, human-escalating

## Status

**Proposed** — merged in the SC-M07 documentation freeze for
[SC-M07 — AI-First Visitor Support](../milestones/sc-m07-ai-first-visitor-support.md)
and its plan
[`sc-m07-ai-first-visitor-support-plan-v1.md`](../plans/sc-m07-ai-first-visitor-support-plan-v1.md).
This ADR is merged **Proposed** and remains Proposed. SC-M07 plan v1 is **not authorized
for implementation** by this freeze.

Per [`docs/governance.md`](../governance.md), implementation begins only after a **standalone,
separately merged** Product Owner implementation-acceptance record at
`docs/decisions/sc-adr-0018-ai-first-po-acceptance.md` — authored and merged by the Product
Owner in its own commit — which changes this Status from **Proposed** to **Accepted** and
authorizes work packages WP1–WP9 exactly within the frozen scope of this ADR and plan v1.
Until that record merges, no implementation branch, runtime code, schema change, credential,
provider call, or deployment is permitted. This mirrors the ADR-0015 / ADR-0016 / ADR-0017
sequence (freeze first; a separate later acceptance record flips the Status).

No plugin version tag, GitHub Release, DEV change, production change, live setting or data
change, live API key, Telegram / Universal Telegram change, or Contract v1 change is part of
this decision. Implementation (when later authorized) advances
`universal_support_chat_db_version` from `12` to `13` and the plugin version from `0.8.0` to
`0.9.0` for asset cache-busting only — neither is done by this freeze.

## Context

Master-plan vision item 5 is "future controlled chat AI (human-approved drafts first; then
direct AI)", and [`docs/master-plan.md`](../master-plan.md) §3 records the principle "Human
control before autonomy — SC-AI1 before SC-AI2". The roadmap encodes this as
[SC-AI1](../milestones/sc-ai1-operator-ai-drafts-approve-and-send.md) (operator AI drafts +
explicit approve-and-send as *Support team*) preceding
[SC-AI2](../milestones/sc-ai2-controlled-direct-ai-responses.md) (controlled direct AI as
*AI assistant*), with [SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md) (a
grounded vector/RAG knowledge base) deferred behind its own ADR, plan, and approval. Both
SC-AI1 and SC-AI2 are `Planned` stubs with product-boundary-only plans and no AI-specific
ADR.

The Product Owner has now decided that the next AI milestone should make **AI the first
responder** to a website visitor: when a visitor starts a conversation, an AI assistant
answers basic support and product questions from *explicitly approved* site content, and
escalates to a human whenever the visitor asks for one or the AI cannot answer safely. This
**inverts** the SC-AI1-before-SC-AI2 ordering — the human becomes the fallback, and an
operator-draft co-pilot is no longer a prerequisite. That is a milestone-boundary and
product-behaviour change with no prior precedent, and it introduces a persistence model, a
new outbound-network surface, a credential, and a safety boundary — so it requires an ADR
(`docs/adr/README.md` "When an ADR is required").

Relevant current runtime facts, verified against `origin/main` at
`2170a0fd6e0933e3320112ac3085d348530db895` (plugin `0.8.0`, `universal_support_chat_db_version`
`12`, PHP ≥ 8.1, PHPStan level 5, PHPCS `WordPress-Extra`):

- **No AI code exists in this repository, and none ever has.** `git log --all` for `src/AI/*`
  returns only documentation. The retired combined chatbot lived in the separate
  `universal-telegram` repository (operator-only drafts, an OpenAI provider abstraction, and
  a *bounded keyword-overlap* grounding layer — **not** vectors or embeddings; that repo's
  ADR-0028 explicitly rejected a vector database). Its entire `src/AI/**` was removed when
  Universal Telegram became transport-only (that repo's ADR-0044). SC-M07 must **not**
  silently restore that code; it re-derives the small amount of still-relevant *product
  intent* under this ADR.
- `Conversations\ConversationMessage` `direction` is a free-form `VARCHAR(16) NOT NULL`
  column (no `CHECK`, no enum) with three values in use today — `visitor`, `operator`,
  `system` (`ConversationMessage::DIRECTION_VISITOR` / `DIRECTION_OPERATOR` /
  `DIRECTION_SYSTEM`). Messages carry **no author id**; attribution *is* the direction value
  (notes carry `operator_user_id`). Bodies are AES-256-GCM encrypted at rest through
  `MessageRepository` + `Core\Security\CredentialVault` (AAD context
  `conversation_message:<uuid>`). `MessageRepository::create()` enforces
  `UNIQUE (conversation_id, idempotency_key)`.
- `Conversations\ConversationStatus` defines `new`, `open`, `waiting_for_visitor`,
  `waiting_for_operator`, `resolved`, `archived` with a transition `map()`.
  [ADR-0017](0017-support-availability-authority-and-honest-offline-behaviour.md) established
  that adding a map edge is a pure code-constant change (no schema, no `db_version`) and
  added `new → waiting_for_operator`. `waiting_for_visitor → waiting_for_operator` is **not**
  currently a legal edge.
- `Conversations\ConversationRepository` has `claim()` / `release()` / `assign()` (atomic
  `assigned_operator_id`) — the operator-takeover primitives. No Hub takeover UI exists.
- The visitor REST surface (`Conversations\Rest\ConversationsController`, namespace
  `universal-support-chat/v1`) is **authenticated-only** — every handler requires
  `is_user_logged_in()` plus a valid `wp_rest` nonce; there is no capability check (any
  logged-in user), ownership is enforced per conversation, and unknown/again-owned resources
  return a uniform 404. Responses already carry `availability: available|unavailable`
  (ADR-0017). `MAX_TEXT_CHARS` is 4096.
- Telegram delivery is fully off the request critical path
  ([ADR-0012](0012-automatic-support-chat-to-telegram-dispatch.md) /
  [ADR-0014](0014-interactive-chat-delivery-class-and-immediate-dispatch.md)):
  `TelegramDispatch\DispatchEnqueuer::persist_and_enqueue()` writes the message row plus a
  **content-free** outbox row (`universal_support_chat_telegram_dispatch`, migration step 12;
  `verify_step_12` forbids any body/content/digest column) in one DB transaction, then fires
  a non-blocking `DispatchWorker::request_immediate_run()` (`wp_schedule_single_event(now)` +
  `spawn_cron()`); a WP-Cron worker (recurring 60 s sweep + one-off immediate hook,
  `BATCH_LIMIT` 25, `BACKOFF = [60,120,300,900,1800,3600]`) does all Telegram I/O.
  `DispatchEnqueuer::is_mirrored_direction()` returns true **only** for `visitor` and
  `operator`.
- `ChannelContract\Rest\ContractOperationDispatcher::dispatch()` is a `switch` over a
  hard-coded allow-list (`ChannelContract\Auth\ContractOperations` fixed `const` arrays,
  "never invented or extended at runtime"), uniform `ok()` / `error()` envelopes, fails
  closed on an unknown operation, and never leaks whether a resource exists — the model for
  any future constrained read-only tool boundary.
- `Core\Security\CredentialVault` is an encrypt/decrypt primitive, not a store:
  `encrypt($plaintext, $context)` (throws `CredentialUnavailableException` when no key is
  resolvable), `decrypt($stored, $context): CredentialResult` (state `AVAILABLE` /
  `INVALIDATED` / `UNAVAILABLE`; never throws), `reencrypt()`; AES-256-GCM; `$context` is
  the AAD; 3-tier fail-closed key resolution. `ChannelContract\Auth\OwnKeyManager` is the
  precedent for storing a secret: an Ed25519 private key encrypted via the vault (context
  `contract.own_signing_key`) into option
  `universal_support_chat_contract_own_key_secret` (`autoload = false`), with
  `ensure_key_pair()` / `rotate()` / `secret_key_raw()` / `delete()`.
- `Core\Configuration\Settings` is the sole owner of one fixed-shape option array
  `universal_support_chat_settings` (13 keys today). `defaults()` / `sanitize()` are
  fixed-shape; unknown keys are dropped.
  [ADR-0016](0016-support-chat-widget-presentation-settings.md) established that later
  milestones may add keys additively, and ADR-0017 added a section with atomic
  all-or-nothing validation. `Administration\Settings\SupportChatSettingsPage` uses the WP
  Settings API with a hidden `0` companion per control and an
  `option_page_capability_*` filter → `Core\Capabilities\CapabilityRegistrar::MANAGE` (the
  single capability `universal_support_chat_manage`, administrator only).
  `Availability\Admin\OverrideAction` is the precedent for a runtime toggle that is **not**
  in the Settings API: a dedicated autoloaded option written only through an `admin_post`
  action with its own `check_admin_referer()` + `current_user_can( MANAGE )`, an audit
  event, and a redirect-with-notice.
- `Administration\Diagnostics\DiagnosticsPage` is read-only — no `<form>` / `<input>`; it
  renders only booleans, fixed enum labels, integer counts, and the version string
  ([ADR-0015](0015-operator-settings-page-and-diagnostics-separation.md) §3 redaction
  boundary — never credentials, keys, routes, tokens, content, identifiers, timestamps, or
  raw errors).
- `Audit\AuditLogger::record()` never throws and redacts internally fail-closed (any unmapped
  path dropped, `SECRET` dropped, `SENSITIVE` → `***`); `actor_type` vocabulary is `system` /
  `operator` / `adapter`; action naming is `<area>.<event>`; message and note bodies are
  never audited. `Privacy\Classification` is `PUBLIC` / `INTERNAL` / `SENSITIVE` / `SECRET`.
- `Availability\AvailabilityService` (ADR-0017) is the sole availability authority —
  `resolve_state()`, `is_unavailable()`, `offline_message()`, `online_indicator_enabled()`;
  there is no public availability REST endpoint. ADR-0017 §5 is the durable "visitor copy
  must be honest — no untrue online, no response-time claim" boundary.
- `assets/js/chat-widget.js` renders all dynamic text with `.textContent`, never `innerHTML`
  (a static test enforces this; ADR-0016 §2); it dedupes on `seenMessageIds`; the panel is a
  non-modal `role="dialog"` with SC-M05 focus handling, RTL, mobile sheet, and
  `prefers-reduced-motion`.
- `Persistence\Migrator` uses numbered `[run, verify]` steps with raw `$wpdb->query()` DDL
  (never `dbDelta`); `db_version` advances only after both succeed; `target_version()` is
  `12`; a failed step calls `Persistence\SchemaHealth::mark_unavailable()` and the whole
  plugin degrades. `Conversations\RetentionCleanupHandler` (daily WP-Cron) runs
  inactive → resolved → archived → body-null → purge and purges the dispatch outbox for a
  purged conversation. `Core\Lifecycle\Uninstaller` drops tables and deletes options **only**
  under `remove_data_on_uninstall`.
- There is **no** `wp_remote_*` call anywhere in `src/` today (`grep` returns zero); the
  only outbound transport is in-process signed Contract v1. There is no rate limiter and no
  model-configuration pattern. `tests/unit/Core/StructuralBoundariesTest.php` asserts
  `src/AI` does **not** exist; `tests/unit/Core/NoTelegramCouplingTest.php` fails on the
  literal strings `WooCommerce`, `ActionScheduler`, `as_schedule_`, `UniversalTelegram\`,
  `universal_telegram_` in `src/`.

Without an ADR, several durable questions would be settled only as implementation detail:
whether AI belongs to Support Chat or an adapter; whether the provider is ever called
synchronously in a visitor request; how AI messages are represented and attributed; what the
knowledge boundary is and whether it may be called "RAG"; whether the AI may take any action;
how a human takes over; how the credential is stored; and how prompt-injection and abuse are
contained. Each is an ownership, persistence, or safety boundary that
[SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md) and later AI work will build
on.

## Decision

### 1. Support Chat owns AI-first visitor support; Telegram is never involved

Support Chat owns and operates, in-process, the AI-first visitor experience: enablement
policy, provider calls, the knowledge boundary, escalation, operator takeover, and all AI
persistence. This realises ADR-0002's "future chat AI" as Support-Chat-owned.

- **No AI request depends on Telegram.** No AI request, retrieval, or escalation touches an
  adapter, and Support Chat's AI path operates identically whether Universal Telegram is
  absent, disabled, mismatched, or failing.
- **No Universal Telegram runtime change.** SC-M07 changes nothing in the Universal Telegram
  repository and adds no Telegram mechanism. Where an operator has already opted into
  ADR-0012 dispatch, only `visitor` and `operator` messages are mirrored by the existing
  worker — see §3.
- A new `src/AI/` boundary is authorized for SC-M07 (`docs/ARCHITECTURE.md` AI row).

### 2. AI-first lifecycle — the provider is never called in the visitor request

When the operator has enabled AI (§8), the AI is the first responder to a visitor message in
a conversation that has not been claimed by an operator and has not already handed off.

- The visitor request itself **never calls the provider.** On an accepted visitor message,
  the server commits **in one DB transaction** — the shape of
  `DispatchEnqueuer::persist_and_enqueue()` — (a) the visitor `ConversationMessage`; (b) its
  existing content-free ADR-0012 dispatch-outbox row, when Telegram dispatch is enabled; and
  (c) a row in the new `universal_support_chat_ai_turns` table. If any part fails, the whole
  unit rolls back.
- It then fires a **non-blocking** cron kick (`wp_schedule_single_event(now)` +
  `spawn_cron()`, wrapped non-throwing), exactly like `DispatchWorker::request_immediate_run()`.
- **All provider I/O runs in a WP-Cron worker** modelled on `TelegramDispatch\DispatchWorker`
  — a recurring safety sweep plus a one-off immediate hook, a bounded batch, lease columns
  for crash recovery, and bounded retry/backoff. The worker resolves knowledge (§9), calls
  the provider (§7), and writes the AI answer as a new `ai`-direction `ConversationMessage`
  (§3). This keeps the visitor path fast and Telegram-independent and means a message flood
  cannot amplify into synchronous outbound load.
- The visitor poll response gains an additive `ai_pending` boolean so the widget can show an
  honest "the assistant is replying" state without any new endpoint.

### 3. AI messages are a new `ai` direction value

An AI answer is a `ConversationMessage` with `direction = 'ai'` — a new value of the existing
free-form `VARCHAR(16)` column. **No column, table, or `db_version` change is needed for the
direction itself** (the `ai_turns` table in §9 is separate metadata).

- `ai` is attributed as **"AI assistant"** in the widget, the visitor poll, and the Hub —
  distinct from `visitor` ("You"), `operator` ("Support team"), and `system`.
- `ai` message bodies are encrypted at rest exactly like every other message
  (`MessageRepository` + `CredentialVault`, AAD `conversation_message:<uuid>`).
- `DispatchEnqueuer::is_mirrored_direction()` is **not** extended — it stays `visitor` +
  `operator` only — so an `ai` message is **structurally never** sent to Telegram. This is
  how master-plan **R1** ("ordinary AI-only chat never opens a channel case") is enforced,
  not by policy but by the mirror predicate.

### 4. Explicit human-handoff triggers

Each trigger below, when it fires, does all of: moves the conversation to
`waiting_for_operator`; writes a plain-text visitor-visible `system` message (no machine
reason in the visitor copy); records a bounded `handoff_reason` enum value on the `ai_turns`
row; emits an `ai.handoff` audit event (`ai.escalation` for the safety-sensitive case); and
**stops all further AI turns for that conversation** for the life of the conversation (§6).

Triggers:

1. **Visitor asks for a human** — a cheap server-side keyword pre-check on the visitor
   message and/or the model's structured `needs_human` output.
2. **Model refusal** — the provider or model declines to answer.
3. **Model uncertainty** — the model signals low confidence / "I don't know" via its
   structured output (the exact signal is a Product Owner decision, see plan §22).
4. **Safety-sensitive content** — the visitor message matches a fixed server-side category
   list (the Product Owner ratifies the exact list in this ADR before implementation, plan
   §22).
5. **Repeated provider failure** — `ai_max_retries` exhausted with transient errors, or a
   non-retryable provider error.
6. **Unsupported request** — refund, coupon, discount, order edit, order creation, account
   change, or any order/account-specific question. SC-M07 ships **no tool**, so the model
   cannot act on these; they always hand off (§5, §10).
7. **Rate-limit or cap breach** — any per-user, per-conversation, or daily cap is reached
   (§8, §11). A breach degrades to an honest handoff, never a hard error.

If the resolved availability state (§/ADR-0017) is `unavailable`, the handoff `system`
message uses honest offline wording (no untrue "online", no response-time estimate); ADR-0017
§5 governs that copy.

### 5. AI must never cause side effects — zero tools in v1

SC-M07 ships **zero tools / function-calling actions.** The `AiProvider` abstraction (§7) is
shaped to allow a *future* fixed-allow-list, read-only tool dispatcher modelled on
`ContractOperationDispatcher`, but SC-M07 registers none and the worker exposes none.

The AI must never — directly or via any tool — create or modify coupons, rebates, refunds,
discounts, orders, order state, carts, accounts, user profiles, roles, options, posts,
comments, or any other WordPress or WooCommerce state. Its only output is an inert-text
`ConversationMessage`. Any request that would require an action is a handoff (§4.6).

### 6. Operator takeover

- A new Hub **"Take over from AI"** action — an `admin_post` handler with its own
  `check_admin_referer()` + `current_user_can( MANAGE )`, modelled on
  `Availability\Admin\OverrideAction` — calls `ConversationRepository::claim()` to set
  `assigned_operator_id`.
- The worker **re-checks eligibility immediately before every provider call**: if
  `assigned_operator_id` is set, or a handoff marker exists on the conversation, the queued
  turn is marked `skipped` and no provider call is made.
- Once a conversation is claimed or has handed off, **every queued and future AI turn for
  that conversation is `skipped`** — takeover and handoff are one-way for the life of the
  conversation in v1 (re-enabling AI per conversation is out of scope, plan §22).
- Operators see the **complete conversation transcript** (all directions, decrypted
  server-side) and the **AI handoff reason** (the `handoff_reason` enum label) in a Hub AI
  panel (§ / plan WP7). The panel shows only enum labels, counts, token totals, provider
  error classes, and knowledge-source labels — never a prompt, an answer body, a key, an
  identifier, a timestamp, or a raw provider error.

### 7. Provider abstraction and credential storage

- **`AI\Provider\AiProvider`** — a provider-neutral interface, `generate( AiRequest ): AiResult`,
  with typed request/result value objects and fixed enums (finish reason, error class). It
  **never throws for a provider-side failure** — a failure is an `AiResult` in an error
  state. A deterministic **`AI\Provider\FakeProvider`** implements it for all tests.
- **`AI\Provider\OpenAiChatProvider`** — one concrete adapter calling the OpenAI API via
  **`wp_safe_remote_post`**. This is the **first `wp_remote_*` surface in `src/`**; it is
  confined to `src/AI/Provider/` and a structural test asserts no `wp_*remote_*` call exists
  elsewhere in `src/`.
- OpenAI is the chosen provider (Product Owner decision). The interface keeps a second
  provider a later, additive change; multi-provider and failover are out of scope for SC-M07.
- **Credential:** the OpenAI API token is encrypted via `CredentialVault` with AAD context
  **`ai.provider_api_key`** into a new `autoload = false` option
  `universal_support_chat_ai_provider_secret`, managed by **`AI\Provider\ProviderKeyManager`**
  (the `OwnKeyManager` pattern — `set()` / `rotate()` / `clear()` / a decrypt accessor /
  fail-closed behaviour when the vault key is unavailable). It is **never** a key in
  `universal_support_chat_settings`, is **never** rendered back to any admin screen, and
  Diagnostics shows only "configured: yes/no" plus a fail-closed encrypt/decrypt probe.
- The token is written only through a dedicated `admin_post` action with its own nonce +
  `MANAGE` check (the `OverrideAction` pattern), which emits an `ai.token_rotated` audit
  event carrying no secret material.

### 8. Model and spending controls — additive settings keys, disabled by default

The following keys are added additively to the fixed-shape `universal_support_chat_settings`
option; `Settings::defaults()` / `Settings::sanitize()` clamp malformed values to the safe
default and never persist an out-of-range value:

| Key | Meaning | Default |
|---|---|---|
| `ai_enabled` | Master switch for the AI-first experience | **`false`** |
| `ai_model` | Selected model, validated against a fixed allowed-model list constant | the cheapest capable model on that list (plan §22) |
| `ai_max_output_tokens` | Hard cap on generated tokens per turn | a conservative value |
| `ai_request_timeout_seconds` | Per-request timeout passed to `wp_safe_remote_post` | a conservative value |
| `ai_max_context_chars` | Character budget for transcript + retrieved knowledge in the prompt | a conservative value |
| `ai_max_retries` | Transient-failure retry budget before terminal handoff | a small integer |
| `ai_daily_request_cap` | Global provider-request ceiling per rolling day | a conservative value |
| `ai_per_conversation_turn_cap` | Lifetime AI-turn ceiling per conversation | a small integer |
| `ai_disclosure_text` | Operator-authored one-time visitor disclosure (plain text) | a non-committal default |

- **Disabled by default.** On upgrade, with `ai_enabled` absent → `false`, there is **no
  behaviour change**: no `ai_turns` rows, no provider calls, byte-identical visitor
  behaviour. The feature turns on only when an authorized operator sets the key **and** a
  provider key is configured.
- `temperature` is a fixed low value inside the adapter, not an operator setting (plan §22).
- The spend safeguard is request-count caps plus surfaced token totals in Diagnostics and
  the Hub AI panel — there is no billing feed.
- Capability is the existing `CapabilityRegistrar::MANAGE`. No new capability.

### 9. Knowledge boundary — bounded keyword retrieval, not "RAG"

v1 knowledge retrieval is an **in-PHP keyword-overlap match** over an **explicit
administrator-approved allow-list**. It is **not** a vector store, embeddings, semantic
search, chunking pipeline, or "RAG", and the documentation must not call it RAG. Genuine
vector/embedding retrieval and any ingestion pipeline stay **deferred to
[SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md)** (its own ADR, plan, and
approval).

- **What may be approved:** published, non-password-protected posts/pages, and
  operator-authored support snippets. The query is the conversation's last visitor message.
  The retriever returns a bounded set within `ai_max_context_chars`.
- **Hard exclusions — never retrievable:** credentials, options, drafts / pending / private /
  password-protected / trashed content, operator notes, other conversations, any customer /
  order / comment / user-profile data, and arbitrary database content.
- **Retrieved content is data, never instructions.** It is inserted into the prompt as a
  clearly fenced data block (or `user`-role content), never as system/developer text (§11).

#### Persistence model (frozen in this ADR)

- New table **`universal_support_chat_knowledge_sources`** (migration step 13, the same step
  as `ai_turns`). Columns:
  - `id` BIGINT UNSIGNED PK AUTO_INCREMENT
  - `source_uuid` CHAR(36) UNIQUE — stable across label/text edits; used as the vault AAD
    context
  - `source_type` VARCHAR(16) — `post` | `snippet`
  - `post_id` BIGINT UNSIGNED NULL — set for `source_type = 'post'`
  - `label` VARCHAR(191) — plain-text display label (post-title snapshot or operator-given
    snippet name); shown only in the admin-only Hub provenance panel, `esc_html()` on output
  - `indexed_text_ciphertext` MEDIUMTEXT NULL — a `CredentialVault::encrypt()` envelope of
    the canonical extracted plain text; NULL only transiently before the first index and
    after a revoke
  - `content_checksum` CHAR(64) — SHA-256 of the canonical *plaintext* extracted text,
    computed **before** encryption; drives staleness
  - `status` VARCHAR(16) — `approved` | `stale` | `revoked`
  - `approved_by` BIGINT UNSIGNED, `approved_at` DATETIME, `last_indexed_at` DATETIME NULL,
    `created_at` DATETIME, `updated_at` DATETIME
  - **No plaintext content column, ever.** No visitor / PII column (no `owner_user_id`, no
    email, no `conversation_id`, no `message_uuid`) — a `verify()` assertion.
- **Encryption at rest.** `indexed_text_ciphertext` is always a `CredentialVault::encrypt()`
  envelope, AAD context **`knowledge_source:<source_uuid>`** — the same house pattern as
  `conversation_message:<uuid>` and `contract.own_signing_key`. The retriever decrypts the
  bounded set of `approved` rows **into memory only** at prompt-assembly time, ranks them,
  and discards; nothing plaintext is written back.
- **Copied, not read live.** `AI\Knowledge\KnowledgeIndexer` extracts a canonical plain-text
  snapshot (`strip_shortcodes()` + `do_blocks()`-then-`wp_strip_all_tags()` + whitespace
  normalisation) at approval and on every reindex. The retriever and prompt assembly use
  **only** the stored snapshot and **never** call `get_post()` content live. Rationale:
  approval is of a specific reviewed content state; a live read would surface unreviewed
  edits and raw markup and would defeat the checksum-based staleness gate.
- **Deletion / removal semantics:**
  - *Operator removes a source* (Hub action) → row **hard-deleted**; the ciphertext is gone;
    the `id` survives only as an opaque integer inside any historical `ai_turns.source_ids`.
  - *Post unpublished / trashed / made private / password-protected / deleted*
    (`save_post` / `wp_trash_post` / `deleted_post` hooks) → `status = 'revoked'`,
    `indexed_text_ciphertext` set to NULL **immediately** (derived text purged); the row is
    kept as a labelled tombstone so historical provenance ids still resolve to a name. A
    revoked tombstone is excluded from all retrieval.
  - *Content edited* (checksum mismatch on `save_post` or the daily re-checksum sweep) →
    `status = 'stale'`; excluded from retrieval until an admin explicitly re-approves (which
    triggers a reindex).
  - *Plugin uninstall* → the table is dropped **only** under the existing
    `remove_data_on_uninstall` opt-in (`Uninstaller` extended).
  - Knowledge sources are **config-like admin data, not conversation data** — the
    conversation retention sweep (`RetentionCleanupHandler`) never touches them.
- **Provenance across edits / reindex:**
  - Every AI turn records the integer `knowledge_sources.id`s it actually placed in the
    prompt into **`ai_turns.source_ids`** (immutable once written) **and** a parallel
    **`ai_turns.source_checksums`** (comma-joined SHA-256 hex prefixes of the exact text
    used — a checksum is metadata, not content).
  - Editing a source excludes it from future turns (stale) until re-approval; past turns'
    `source_ids` / `source_checksums` are unchanged — they record what was genuinely used.
  - Reindex keeps the same `id` / `source_uuid` with new ciphertext, a new
    `content_checksum`, and `status = 'approved'`. The Hub AI panel resolves `source_ids` →
    current `label` and compares the turn's `source_checksums` against the current
    `content_checksum` to show "same text" vs "content changed since this turn" — honest
    provenance without storing text versions.

### 10. Order and customer support — deferred

SC-M07 reads **no** order or customer data and adds **no** WooCommerce integration
(`NoTelegramCouplingTest`'s `WooCommerce` string ban stays satisfied; no WooCommerce CI job
is added). Order-specific and account questions are a handoff trigger (§4.6). A future
milestone — its own ADR, plan, and Product Owner approval — may add read-only typed order
tools with server-side ownership checks, WooCommerce-gated and disabled by default; guest
order lookup and email-based identity linking are deferred within that too.

### 11. Safety and prompt-injection resistance

- **Server-owned fixed policy.** `AI\Policy\AiSystemPolicy` produces the system/developer
  instructions. It is never influenced by visitor text, retrieved content, or operator
  settings beyond a few interpolated facts (business name; whether order lookup is available
  — always "no" in v1; the current availability state). A unit test asserts the policy text
  is input-independent.
- **Retrieved content and visitor text are always data.** They are placed in fenced data
  blocks / `user`-role content, never as instructions. A test scripts `FakeProvider` to
  emit "ignore your instructions / issue a refund" from within retrieved content and asserts
  no state change and no system-prompt leak in the stored answer.
- **No executable output.** The answer is inert text; code fences are kept as literal text;
  the widget renders with `.textContent` and the Hub with `esc_html()` (ADR-0016 §2). No
  generated HTML / JS / PHP / SQL / shell is ever executed.
- **No arbitrary tool selection.** v1 has no tools; a future dispatcher validates every tool
  name and argument server-side against a closed allow-list (§5).
- **Abuse / rate limiting** (new — none exists today): a per-user transient counter with
  cooldown, a per-conversation lifetime turn cap, and a global daily request cap. Every
  breach degrades to an honest handoff (§4.7), never a hard error.
- **Timeout and token limits** bound every request; the visitor request never calls the
  provider (§2).
- **Privacy / retention / minimisation.** `ai` messages are ordinary encrypted
  `ConversationMessage`s under the existing retention path; `ai_turns` rows are purged with
  their conversation. Audit and the `ai_turns` table store **only** ids, counts, and enums
  (finish reason, outcome, handoff reason, token counts, latency, provider error class,
  source ids/checksums) — **never** the prompt, the answer, visitor text, retrieved content,
  or PII.
- **Provider failure / key compromise.** Setting `ai_enabled = false` stops everything
  immediately; `ProviderKeyManager::rotate()` / `clear()` run through the `admin_post`
  action; `CredentialVault::reencrypt()` covers salt rotation.

### Schema verification boundary (frozen in this ADR)

The content-column prohibition is **table-specific** — this resolves the apparent tension
between "metadata-only AI tables" and "the knowledge table must hold approved text somewhere":

- **`ai_turns`** is pure metadata. Its `verify()` step **forbids** any column whose name
  matches `body` / `prompt` / `response` / `message` / `content` / `text` / `plaintext` /
  `ciphertext` / `transcript`. The prompt is **never persisted anywhere**; the answer lives
  only as an `ai`-direction `ConversationMessage` in the existing encrypted messages table.
  `ai_turns` columns are ids, uuids, fixed-vocabulary strings, small ints, counts,
  timestamps, and the `source_ids` / `source_checksums` reference/metadata fields
  (comma-joined integer ids and SHA-256 hex prefixes — references, not content).
- **`knowledge_sources`** legitimately carries approved source text, but **only as a
  `CredentialVault` envelope**. Its `verify()` step **requires** `indexed_text_ciphertext`
  and **forbids** any *plaintext* content column (`indexed_text` without the `_ciphertext`
  suffix, `body`, `raw_content`, `plaintext`, …) and any visitor / PII column.

## Alternatives

- **Reuse the `system` direction for AI messages.** Rejected: `system` is unattributed
  service text; AI answers need distinct "AI assistant" attribution and their own audit and
  provenance, and `system` is already used for handoff notices.
- **Add an author / attribution column to `conversation_messages`.** Rejected: attribution is
  the direction value by existing design; a new column is a schema change for no benefit.
- **Call the provider synchronously in the visitor request.** Rejected: it couples request
  latency to a third party, makes a message flood a synchronous outbound-load amplifier, and
  breaks the "no request-path external I/O" posture ADR-0012 / ADR-0014 established.
- **Embeddings / a vector store now.** Rejected: out of scope for a first version and
  already owned by SC-AI3; v1 is a bounded keyword match the Product Owner explicitly chose.
- **A generic tool / function bus.** Rejected: v1 ships zero tools; a general bus is a
  standing side-effect surface. A future closed-allow-list dispatcher (the
  `ContractOperationDispatcher` model) is the only sanctioned path.
- **Anthropic instead of OpenAI.** Rejected: the Product Owner chose OpenAI; the interface
  keeps a second provider additive.
- **Store the provider key in `universal_support_chat_settings`.** Rejected: that option is
  sanitised fixed-shape config written through `options.php` and is rendered back to the
  admin; a secret belongs in a vault-encrypted `autoload = false` option written only through
  a nonce + `MANAGE` action (the `OwnKeyManager` precedent).
- **Redefine SC-AI1 in place instead of a new milestone.** Rejected: SC-AI1's charter is
  frozen and its scope (operator drafts) is genuinely different; a new milestone that
  supersedes SC-AI1 + SC-AI2 is cleaner and keeps the frozen charters immutable.
- **A per-message visitor "use AI" opt-in checkbox.** Rejected: master-plan **R4** requires
  administrator site policy + disclosure, *not* a visitor checkbox.
- **An AI-generated greeting on conversation open.** Rejected: a static greeting plus the
  one-time disclosure line is honest and cheaper; an AI opener spends a provider call before
  the visitor has said anything.
- **A public unauthenticated availability or AI endpoint.** Rejected: the visitor surface is
  authenticated-only (ADR-0003 / ADR-0017); AI turns ride the existing authenticated
  conversation endpoints and the async worker.
- **Restore the retired Universal Telegram `src/AI/**` code.** Rejected by governance: that
  code was removed, predates this ADR's disclosure/policy/handoff model and R1 mirror
  predicate, and must not be reintroduced without this ADR and PO approval. Only the product
  intent is carried forward.

## Consequences

- A new `src/AI/` boundary: `AI\Provider\*` (interface, value objects, `FakeProvider`,
  `OpenAiChatProvider`, `ProviderKeyManager`), `AI\Knowledge\*`
  (`KnowledgeSourceRepository`, `KnowledgeIndexer`, `KnowledgeRetriever`), `AI\Policy\*`
  (`AiSystemPolicy`, prompt assembly), `AI\Turn\*` (`AiTurnRepository`, `AiTurnEnqueuer`,
  `AiTurnWorker`, `AiTurnRateLimiter`, escalation state machine), and `AI\Admin\*`
  (settings section, provider-key action, "AI Knowledge" Hub submenu, takeover action, Hub
  AI panel, Diagnostics block).
- The **first `wp_remote_*` surface** in the plugin, confined to `src/AI/Provider/` and
  structurally test-fenced; the **first rate limiter**.
- `db_version` **12 → 13** — two new tables: `ai_turns` (metadata-only) and
  `knowledge_sources` (encrypted-content-only), per the verification boundary above.
- One new conversation-status transition-map edge if the escalation path needs
  `waiting_for_visitor → waiting_for_operator` (a code constant; WP6 confirms and adds it
  only if required).
- Additive `universal_support_chat_settings` keys (§8); Settings page section; Diagnostics
  block; Hub AI panel, takeover action, and "AI Knowledge" submenu.
- Retention extended to purge `ai_turns` with the conversation; `Uninstaller` extended to
  drop the two tables and delete the provider-secret option only under
  `remove_data_on_uninstall`.
- The widget gains an `ai` bubble style, the one-time disclosure line, and an `ai_pending`
  state — all `.textContent`, all SC-M05 accessibility behaviour preserved.
- SC-AI3 inherits the explicit "keyword now, vectors later" split and the knowledge
  persistence/provenance model.
- **Downgrade** to a pre-SC-M07 build: AI stops (the worker hook is unscheduled); existing
  `ai`-direction messages remain valid rows and render as generic non-visitor messages;
  `waiting_for_operator` conversations stay handled; the two new tables become inert.
- **SC-AI1 and SC-AI2 are superseded by SC-M07** (status lines only; the frozen charters and
  plans are not edited beyond a superseded banner).

## Security and privacy impact

- **Authenticated-only visitor surface unchanged.** AI turns ride the existing
  authenticated conversation/message endpoints; there is no new public route
  ([ADR-0003](0003-security-privacy-and-visitor-isolation.md),
  [ADR-0017](0017-support-availability-authority-and-honest-offline-behaviour.md)). An
  unauthenticated or non-owning caller cannot cause or read an AI turn (uniform 404).
- **No new capability, no capability relaxation.** `MANAGE` gates the settings section, the
  provider-key action, the "AI Knowledge" submenu, the takeover action, and the Hub AI
  panel; each admin action does its own nonce + `MANAGE` check.
- **The AI performs no mutation.** Zero tools in v1; inert-text output only (§5, §11).
- **Prompt-injection containment.** Server-owned input-independent policy; retrieved content
  and visitor text are fenced data; no executable output; no arbitrary tool selection
  (§11).
- **Credential.** The OpenAI token is `CredentialVault`-encrypted (AAD `ai.provider_api_key`)
  in an `autoload = false` option, never rendered back, never audited, fail-closed when the
  vault key is unavailable.
- **Encrypted bodies.** `ai` messages are encrypted exactly like all messages; retrieved
  knowledge text is encrypted at rest (AAD `knowledge_source:<source_uuid>`).
- **Minimised audit and metadata.** `ai.*` audit rows and the `ai_turns` table hold only
  ids, counts, and enums — never prompts, answers, visitor text, retrieved content, or PII
  ([ADR-0015](0015-operator-settings-page-and-diagnostics-separation.md) §3 redaction
  boundary; `Audit\AuditLogger` fail-closed redaction).
- **DoS resistance.** The visitor request never calls the provider; the async worker plus
  per-user / per-conversation / daily caps and per-request timeout/token limits bound
  outbound load; every breach is an honest handoff.
- **Availability honesty.** Handoff and pending copy never claim a human is online unless
  `AvailabilityService` resolves `available`; ADR-0017 §5 governs the wording.
- **Zero AI-only Telegram traffic** — proven structurally by the unchanged
  `is_mirrored_direction()` predicate and an interop assertion, satisfying **R1**.
- **Disabled by default** — no behaviour change until an operator sets `ai_enabled` and
  configures a provider key.

## Affected Documents/Milestones

- [SC-M07 — AI-First Visitor Support](../milestones/sc-m07-ai-first-visitor-support.md) —
  new charter introduced with this ADR; **supersedes SC-AI1 and SC-AI2**.
- [`sc-m07-ai-first-visitor-support-plan-v1.md`](../plans/sc-m07-ai-first-visitor-support-plan-v1.md)
  — realises this ADR (WP1–WP9).
- [SC-AI1](../milestones/sc-ai1-operator-ai-drafts-approve-and-send.md) and
  [SC-AI2](../milestones/sc-ai2-controlled-direct-ai-responses.md) — Status lines change to
  "Superseded by SC-M07"; charters otherwise unchanged and immutable. Their plans
  ([SC-AI1 plan](../plans/sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md),
  [SC-AI2 plan](../plans/sc-ai2-controlled-direct-ai-responses-plan-v1.md)) gain a
  superseded banner only.
- [SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md) — unchanged; remains the home
  of genuine vector/RAG retrieval, now depending on SC-M07 instead of SC-AI1/SC-AI2 for the
  knowledge and provider seams it will extend.
- [`docs/master-plan.md`](../master-plan.md) — §3 principle bullet and §5 roadmap rows 9–11
  updated (row 9 → SC-M07; rows 10–11 → superseded; SC-AI3 retained); R4/R6 mapping to
  SC-M07.
- [`docs/milestones/README.md`](../milestones/README.md) — new SC-M07 registry row; SC-AI1 /
  SC-AI2 rows → "Superseded by SC-M07"; locked execution order item 4 rewritten.
- [`docs/adr/README.md`](README.md) — ADR-0018 row and index bullet; next available number
  → 0019.
- [`docs/plans/README.md`](../plans/README.md) — SC-M07 plan row; superseded notes on the two
  AI plans.
- [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md) — **AI** boundary row becomes authorized for
  SC-M07 with `src/AI/` as its home; **Conversations** row notes the `ai` direction value and
  any transition edge; versioning section notes `db_version` 13 / plugin `0.9.0`; a note that
  the first outbound `wp_safe_remote_post` surface is confined to `src/AI/Provider/`.
- [`docs/testing/test-strategy.md`](../testing/test-strategy.md) — new `tests/unit/AI/` and
  `tests/integration/AI/` suites and the structural "no real provider calls in CI" rule.
- Builds on [ADR-0012](0012-automatic-support-chat-to-telegram-dispatch.md) /
  [ADR-0014](0014-interactive-chat-delivery-class-and-immediate-dispatch.md) (async
  worker + atomic content-free outbox — the shape the AI turn engine mirrors; the mirror
  predicate that keeps `ai` off Telegram), and respects
  [ADR-0004](0004-migration-and-retention-principles.md) (retention / uninstall) and
  [ADR-0006](0006-optional-channel-and-adapter-failure-model.md) (adapter-independent
  operation).
- `docs/decisions/sc-adr-0018-ai-first-po-acceptance.md` — a later, standalone Product Owner
  implementation-acceptance record (not part of this freeze) flips this Status to Accepted.

## Compatibility/Migration Impact

- **Schema:** `universal_support_chat_db_version` advances `12 → 13` when implementation is
  later authorized — one migration step 13 with `[run, verify]` creating `ai_turns`
  (metadata-only `verify()`) and `knowledge_sources` (encrypted-content-only `verify()`).
  Raw `$wpdb` DDL, consistent with `Migrator`. A failed step degrades the plugin via
  `SchemaHealth` exactly as existing steps do. This freeze changes no schema.
- **`direction = 'ai'`** is a new value of an existing free-form `VARCHAR(16)` column — no
  column or table change for the direction itself.
- **Additive settings keys** (§8) resolve to their documented defaults through
  `Settings::sanitize()` when absent; `ai_enabled` absent → `false`.
- **Transition map:** at most one added edge (`waiting_for_visitor → waiting_for_operator`),
  a pure code constant; no stored row is touched and every existing row stays valid.
- **No behaviour change until an operator enables AI** and configures a provider key.
- **Fully compatible with Universal Telegram absent, disabled, mismatched, or failing** —
  the AI path has no adapter dependency, and no Universal Telegram code changes.
- **Retention / uninstall:** `ai_turns` rows are purged with their conversation by the
  existing retention sweep; the two new tables and the provider-secret option are removed
  only under the existing opted-in `remove_data_on_uninstall` path.
- **Downgrade** to a pre-SC-M07 build: the worker hook is unscheduled, no provider calls
  occur, `ai`-direction messages remain valid rows, `waiting_for_operator` conversations
  stay handled, and the two new tables become inert historical data.
- **Plugin version** `0.8.0 → 0.9.0` on implementation for asset cache-busting only — no
  GitHub Release, no tag. This freeze changes no version constant.
