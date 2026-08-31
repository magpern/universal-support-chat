# Product Owner Decision Record — ADR-0018 / SC-M07 AI-First Visitor Support: implementation acceptance

## Status

Approved

## Decision owner

Magnus (Product Owner, per [`docs/governance.md`](../governance.md) role table).

## Context

[ADR-0018 — AI-first visitor support: grounded, read-only, human-escalating](../adr/0018-ai-first-visitor-support.md)
and its companion plan
[`sc-m07-ai-first-visitor-support-plan-v1.md`](../plans/sc-m07-ai-first-visitor-support-plan-v1.md),
together with the SC-M07 charter and the index/roadmap edits, were frozen as
documentation-only on `main` at commit
**`537d3b050040e68f8bc227b9fd104b0fe9ab82ad`** (PR #57, "docs(sc-m07): freeze ADR-0018
(Proposed) + AI-first visitor support plan v1").

Both the ADR and the plan state that ADR-0018 is merged **Proposed** in the freeze and that
implementation is authorized only from the merged freeze baseline, after a separate Product
Owner acceptance act recorded distinctly from the design freeze (per `docs/governance.md` —
"No role approves its own work product as final"). This record captures that act.

This record is documentation-only. It changes no architecture — ADR-0018 remains the
authoritative design — and it authorizes no work beyond the frozen scope of ADR-0018 and
plan v1.

## Decision

The Product Owner records the following acceptance verbatim:

> Product Owner acceptance — ADR-0018 / SC-M07 AI-first visitor support implementation
>
> I accept [ADR-0018](../adr/0018-ai-first-visitor-support.md) and
> [`docs/plans/sc-m07-ai-first-visitor-support-plan-v1.md`](../plans/sc-m07-ai-first-visitor-support-plan-v1.md)
> for implementation exactly as merged in the freeze at
> `537d3b050040e68f8bc227b9fd104b0fe9ab82ad`, and exactly within their frozen scope.
>
> This authorizes implementation of the frozen SC-M07 plan v1 work packages **WP1–WP9**
> only, with these decisions fixed:
>
> - **Support Chat owns the AI-first visitor experience.** The AI assistant is the first
>   responder to a visitor message in an unclaimed, not-handed-off conversation when an
>   authorized operator has set `ai_enabled` **and** configured a provider key. **No AI
>   request depends on Telegram; no Universal Telegram runtime change; no Telegram
>   notification/dispatch mechanism is added.**
> - **The provider is never called in the visitor request.** The visitor request commits the
>   visitor `ConversationMessage`, its existing content-free ADR-0012 dispatch-outbox row
>   when Telegram dispatch is enabled, and an `ai_turns` row **in one DB transaction**, then
>   fires a non-blocking cron kick. **All** provider I/O runs in a WP-Cron worker modelled on
>   `TelegramDispatch\DispatchWorker` (recurring sweep + one-off immediate hook, bounded
>   batch, lease-based crash recovery, bounded retry/backoff).
> - **AI messages are a new `ConversationMessage` direction value `ai`** (free-form
>   `VARCHAR(16)`; no schema or `db_version` change for the direction). Attributed
>   "AI assistant". `TelegramDispatch\DispatchEnqueuer::is_mirrored_direction()` is **not**
>   extended — `ai` is structurally never mirrored to Telegram (**R1**).
> - **The AI ships zero tools and causes no side effects.** No coupons, rebates, refunds,
>   discounts, orders, order state, carts, accounts, profiles, roles, options, posts,
>   comments, or any other WordPress/WooCommerce mutation — directly or via any tool. The
>   only output is an inert-text `ConversationMessage`. A future read-only allow-list tool
>   dispatcher is explicitly **not** part of SC-M07.
> - **Knowledge is bounded in-PHP keyword-overlap retrieval** over an explicit administrator
>   allow-list of published, non-password-protected posts/pages plus operator-authored
>   snippets; the query is the conversation's last visitor message; results are bounded by a
>   character budget. It is **not** embeddings, a vector store, semantic search, chunking, or
>   an ingestion pipeline, and it is **not** called "RAG". Genuine vector/RAG retrieval stays
>   deferred to SC-AI3.
> - **Knowledge persistence** is exactly ADR-0018 §9: a new table
>   `universal_support_chat_knowledge_sources` (migration step 13). The canonical extracted
>   plain-text snapshot is **encrypted at rest** as a `Core\Security\CredentialVault`
>   envelope in `indexed_text_ciphertext`, AAD context `knowledge_source:<source_uuid>`;
>   there is **no plaintext content column and no visitor/PII column**. Content is **copied
>   at approval and on reindex** (`strip_shortcodes()` + `do_blocks()` +
>   `wp_strip_all_tags()` + whitespace normalisation), **never read live**. Deletion:
>   operator remove = **hard-delete** the row; post unpublish/trash/private/password-protected/delete
>   = `status = 'revoked'` + `indexed_text_ciphertext` NULLed immediately + labelled
>   tombstone; content edit (checksum mismatch) = `status = 'stale'`, excluded until explicit
>   re-approval; uninstall drops the table only under `remove_data_on_uninstall`; the
>   conversation retention sweep never touches these rows. Per-turn provenance is
>   `ai_turns.source_ids` (immutable) plus `ai_turns.source_checksums` (SHA-256 hex
>   prefixes — metadata, not content).
> - **The metadata table `universal_support_chat_ai_turns` (migration step 13) is
>   metadata-only.** Its `verify()` forbids any column named for a body / prompt / response /
>   message text / content / plaintext / ciphertext / transcript. The prompt is never
>   persisted anywhere; the answer lives only as an `ai`-direction `ConversationMessage` in
>   the existing encrypted messages table. This table-specific verification boundary
>   (`ai_turns` metadata-only; `knowledge_sources` encrypted-content-only) is frozen.
> - **Provider and credential.** A provider-neutral `AI\Provider\AiProvider` interface with
>   typed request/result objects and fixed enums; a deterministic `AI\Provider\FakeProvider`
>   for all tests; **one OpenAI adapter** (`AI\Provider\OpenAiChatProvider`) using
>   `wp_safe_remote_post`, with **all** outbound provider HTTP confined to `src/AI/Provider/`
>   (a structural test enforces this) and never invoked from a visitor or Hub request. The
>   API token is stored through `CredentialVault` with AAD context `ai.provider_api_key`, as
>   an opaque envelope in the `autoload = false` option
>   `universal_support_chat_ai_provider_secret`, managed by `AI\Provider\ProviderKeyManager`
>   (set / rotate / clear / decrypt / fail-closed), written only through a dedicated
>   nonce-protected, `CapabilityRegistrar::MANAGE`-gated `admin_post` action. The token is
>   **never** rendered in any admin page or in Diagnostics, and **never** appears in the raw
>   provider response surfaced to any screen.
> - **Model and spending controls** are additive keys on the existing fixed-shape
>   `universal_support_chat_settings` option, clamped to safe defaults by
>   `Settings::sanitize()`: `ai_enabled` (default **false**); `ai_model` validated against
>   the fixed allow-list **`['gpt-4o-mini', 'gpt-4o']`** with default **`gpt-4o-mini`**;
>   `ai_max_output_tokens`; `ai_request_timeout_seconds`; `ai_max_context_chars`;
>   `ai_max_retries`; `ai_daily_request_cap`; `ai_per_conversation_turn_cap`;
>   `ai_disclosure_text`. `temperature` is a fixed low value in the adapter, **not** an
>   operator setting. The spend safeguard is request-count caps plus surfaced token totals —
>   there is no billing feed. Capability is the existing `MANAGE`; **no new capability.**
> - **Disabled by default.** With `ai_enabled` absent/false, visitor behaviour is
>   byte-identical to pre-SC-M07 and no `ai_turns` rows or provider calls occur.
> - **Conversation and widget.** `ConversationMessage::DIRECTION_AI = 'ai'`, rendered as a
>   distinct "AI assistant" bubble in the widget and Hub via `.textContent` / escaped
>   server output only (never `innerHTML`; ADR-0016 §2). An additive `ai_pending` field on
>   the poll response drives an honest "assistant is replying" state — **no new REST route**.
>   A **one-time visitor disclosure** that the visitor is chatting with an AI assistant is
>   shown from the operator-authored `ai_disclosure_text` (site policy + disclosure, **not**
>   a visitor checkbox — **R4**). SC-M05 accessibility, non-modal dialog behaviour, focus
>   handling, RTL, mobile layout, and reduced-motion support are preserved. Availability copy
>   stays honest and uses `Availability\AvailabilityService` (SC-M06 / ADR-0017) as the
>   authority; **no public availability endpoint** is added.
> - **Human handoff.** Each trigger — visitor asks for a human (a **server-side keyword
>   pre-check** on the visitor message **and** the model's structured `needs_human` output),
>   model refusal, model uncertainty (structured `needs_human` / low confidence), a
>   **safety-sensitive** visitor message matching the fixed server-side category list
>   **{ self-harm / suicide, threats / violence, legal advice, medical advice, payment
>   disputes / chargebacks / fraud, account-security / compromised account }**, repeated
>   provider failure (`ai_max_retries` exhausted or a non-retryable error), an unsupported
>   request (refund / coupon / discount / order edit / order creation / account change / any
>   order- or account-specific question), or a rate-limit / cap breach — transitions the
>   conversation to `waiting_for_operator`, writes a plain visitor-visible `system` message
>   (honest and availability-aware; no machine reason in the visitor copy), records a bounded
>   `handoff_reason` enum on the `ai_turns` row, emits `ai.handoff` (`ai.escalation` for the
>   safety case), and **stops all further AI turns for that conversation** for the life of the
>   conversation. Re-enabling AI on a conversation after handoff or takeover is **out of
>   scope for v1** (one-way).
> - **Operator takeover.** A new Hub "Take over from AI" action —`admin_post`, own nonce +
>   `MANAGE` — calls `Conversations\ConversationRepository::claim()`. The worker re-checks
>   eligibility immediately before every provider call; once `assigned_operator_id` is set or
>   a handoff marker exists, every queued/future turn is `skipped`. One trailing AI message
>   already past that check may still be posted. Operators see the complete transcript and
>   the AI handoff reason in a Hub AI panel that shows **only** enum labels, counts, token
>   totals, provider error classes, and knowledge-source labels with a "same text / content
>   changed since this turn" flag — never a prompt, an answer body, a key, an identifier, a
>   timestamp, or a raw provider error.
> - **Rate limiting** (new — none exists today): a per-user transient counter with cooldown,
>   a per-conversation lifetime turn cap, and a global daily request cap. Every breach
>   degrades to an honest handoff, never a hard error.
> - **Diagnostics** gains a read-only AI block with safe aggregates only: enabled/disabled;
>   provider configured yes/no plus a fail-closed encrypt/decrypt probe; `ai_model` label;
>   knowledge sources by status; AI turns today vs cap; handoffs today; last outcome / last
>   provider error class. No credentials, prompts, responses, timestamps, identifiers, or raw
>   errors (ADR-0015 §3).
> - **Audit and minimisation.** `ai.*` audit rows and the `ai_turns` table hold **only** ids,
>   counts, and enums (finish reason, outcome, handoff reason, token counts, latency,
>   provider error class, source ids/checksums) — never the prompt, the answer, visitor text,
>   retrieved content, or PII. `Audit\AuditLogger` fail-closed redaction applies. `ai`
>   messages follow the existing encrypted-at-rest and retention paths; `ai_turns` rows are
>   purged with their conversation.
> - **Testing.** `FakeProvider` in all CI; **no real OpenAI call in CI** (structural — unit
>   has no HTTP, integration wires `FakeProvider`, a boundary test confines `wp_*remote_*` to
>   `src/AI/Provider/`). Unit, integration, security, and browser coverage per plan v1 §8.
>   The interop suite must stay green with Universal Telegram unchanged, and a check asserts
>   an `ai`-direction message is never mirrored to Telegram.
> - **Schema and version.** Migration step 13 advances
>   `universal_support_chat_db_version` `12 → 13` after `[run, verify]` for both tables
>   succeed. The plugin version bumps `0.8.0 → 0.9.0` for asset cache-busting only. Downgrade
>   compatibility is preserved: existing conversation rows, messages (including any `ai`), and
>   statuses remain valid; the new tables become inert.
> - **Structural boundaries.** `tests/unit/Core/StructuralBoundariesTest.php` is updated to
>   authorize `src/AI/`, to assert `is_mirrored_direction('ai') === false`, and to assert
>   provider HTTP exists only under `src/AI/Provider/`.
>   `tests/unit/Core/NoTelegramCouplingTest.php` stays green (no Telegram symbol, no
>   `ActionScheduler`, no `WooCommerce` reference in the AI path).
>
> This authorization **excludes**: any embeddings / vector store / semantic search /
> chunking / ingestion pipeline (SC-AI3); any tool, function-calling action, or side effect;
> any WooCommerce / order / customer-data read, guest order lookup, or email-based identity
> linking (a follow-up milestone with its own ADR); any multi-provider or provider failover
> or non-OpenAI adapter; any operator-draft "approve and send" co-pilot UX; any per-message
> visitor AI opt-in checkbox; any AI-generated greeting on conversation open; any promised
> response-time / SLA / ETA copy; any new public or unauthenticated REST route (including a
> public availability endpoint); re-enabling AI on a conversation after handoff/takeover; any
> Universal Telegram or Contract v1 change; any Telegram notify/dispatch mechanism; any DEV
> or production deployment; any live API key; enabling AI on any live site; any GitHub
> Release, version tag, or data operation; any change to the frozen technical content of plan
> v1 or ADR-0018; any closure record as part of the implementation PR; and any work outside
> SC-M07.
>
> Signed: Product Owner
> Date: 2026-08-31

## Scope authorized (for reference — the record above is authoritative)

Exactly the work packages frozen in
[plan v1 §9](../plans/sc-m07-ai-first-visitor-support-plan-v1.md) (WP1–WP9) and the decisions
in [ADR-0018 §Decision](../adr/0018-ai-first-visitor-support.md) (§§1–11 plus the schema
verification boundary):

1. **WP1** — migration step 13: `universal_support_chat_ai_turns` (metadata-only `verify()`)
   + `universal_support_chat_knowledge_sources` (encrypted-content-only `verify()`);
   `db_version` `12 → 13`; `SchemaHealth` failure path; `AiTurnRepository` +
   `KnowledgeSourceRepository` with safe count helpers; `Uninstaller` +
   `RetentionCleanupHandler` extension.
2. **WP2** — `AI\Provider\AiProvider` + value objects + enums + `FakeProvider`;
   `OpenAiChatProvider` via `wp_safe_remote_post`; `ProviderKeyManager` (vault AAD
   `ai.provider_api_key`, `autoload = false` secret option); provider-HTTP confinement test.
3. **WP3** — additive `Settings` keys + `AI_ALLOWED_MODELS = ['gpt-4o-mini','gpt-4o']`
   (default `gpt-4o-mini`) + clamping; "AI Assistant" Settings section (hidden companions);
   `ProviderKeyAction` (`admin_post`, `MANAGE`, dedicated nonce, set/rotate/clear);
   `ai.config_changed` / `ai.token_rotated` audit.
4. **WP4** — `ConversationMessage::DIRECTION_AI`; poll `author_label` mapping + additive
   `ai_pending`; widget `ai` bubble + one-time disclosure line; Hub `ai` transcript
   rendering; `is_mirrored_direction('ai') === false`.
5. **WP5** — `KnowledgeSourceRepository` (encrypt/decrypt via `CredentialVault`),
   `KnowledgeIndexer` (canonical snapshot; `save_post` / `wp_trash_post` / `deleted_post`
   hooks + daily re-checksum sweep + manual reindex), `KnowledgeRetriever` (keyword-overlap
   ranking + budget), the "AI Knowledge" Hub submenu, revoke/stale/hard-delete semantics,
   `ai.knowledge_source_changed` audit.
6. **WP6** — `AiTurnEnqueuer` (atomic message + turn write), `AiTurnWorker` (WP-Cron
   recurring + immediate hook, lease recovery), `AiTurnRateLimiter`, `AiSystemPolicy` +
   `PromptAssembler` (fenced data blocks), `EscalationDecider` + the full handoff state
   machine incl. any transition-map edge, `ai` message write with idempotency key
   `ai-turn:<turn_uuid>`, `source_ids` + `source_checksums` recording, the controller gate,
   retention extension.
7. **WP7** — `TakeoverAction` (`admin_post`, `MANAGE`, nonce → `claim()`, `ai.takeover`);
   Hub AI panel (safe aggregates only — redaction test); Waiting-view handoff badge.
8. **WP8** — Diagnostics AI block (safe aggregates only); `ai.*` audit wiring review +
   redaction tests for every `ai.*` path.
9. **WP9** — wiring in `Plugin.php`; `UNIVERSAL_SUPPORT_CHAT_VERSION` `0.8.0 → 0.9.0` (asset
   cache-bust; no release/tag); structural-test updates; `docs/testing/test-strategy.md`;
   full CI gate run; the implementation PR (left open, unmerged; no closure record), citing
   the freeze SHA and this record's merge SHA.

## Not authorized

Per the acceptance text: embeddings / vector store / semantic search / chunking / ingestion
pipeline (SC-AI3); any tool or side effect; any WooCommerce / order / customer-data read,
guest order lookup, or identity linking; multi-provider / failover / non-OpenAI adapter;
operator-draft co-pilot UX; visitor AI opt-in checkbox; AI-generated greeting; response-time
/ SLA / ETA copy; any new public or unauthenticated REST route; re-enabling AI after
handoff/takeover; any Universal Telegram or Contract v1 change; any Telegram
notify/dispatch mechanism; any DEV or production deployment; any live API key; enabling AI on
a live site; any GitHub Release, tag, or data operation; no schema change beyond migration
step 13; no new capability; no change to the frozen technical content of plan v1 or ADR-0018;
no closure record as part of the implementation PR; no work outside SC-M07.

## Affected Documents/Milestones

- [ADR-0018](../adr/0018-ai-first-visitor-support.md) — Status moves `Proposed` → `Accepted`
  in the same commit as this record, referencing it.
- [plan v1](../plans/sc-m07-ai-first-visitor-support-plan-v1.md) — header gains a short
  "implementation authorized" note (frozen technical content unchanged), fixing the §14 open
  decisions as recorded here.
- [`docs/decisions/README.md`](README.md) — index entry.
- [`docs/adr/README.md`](../adr/README.md) — ADR-0018 index status `Proposed` → `Accepted`.
- [SC-M07 charter](../milestones/sc-m07-ai-first-visitor-support.md) — already points to
  plan v1 and ADR-0018; milestone scope unchanged.

## Baseline

Implementation begins from `main` after this record merges. The implementation branch and PR
must cite:

- ADR-0018 / plan v1 freeze commit: `537d3b050040e68f8bc227b9fd104b0fe9ab82ad` (PR #57).
- This acceptance record's merge commit (to be filled in the implementation PR).
