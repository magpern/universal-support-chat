# SC-M07 — AI-First Visitor Support

## Status

**Closed (PASS WITH LIMITATIONS)** — implementation
[PR #59](https://github.com/magpern/universal-support-chat/pull/59) squash-merged to `main`
at `a81390086e37af04eba0a0ee1874949376be2c5a`; closure
[`docs/closure/sc-m07-ai-first-visitor-support-closure.md`](../closure/sc-m07-ai-first-visitor-support-closure.md).
Freeze `537d3b0` (PR #57) / Product Owner acceptance `b47ce61` (PR #58); ADR-0018 **Accepted**.
All CI green (`db_version` `12 → 13`, version `0.8.0 → 0.9.0`). Accepted limitations —
real-provider verification, SC-M05-standard browser QA, a VoiceOver/NVDA smoke, and
functional DEV acceptance + a Product Owner functional sign-off — are deferred to DEV
acceptance ([`docs/closure/sc-m07-dev-acceptance-plan.md`](../closure/sc-m07-dev-acceptance-plan.md)).
Not deployed; production untouched.

Original status: Planned

Depends on: SC-M02 (conversation SoR + visitor REST + Hub replies); SC-M06 / ADR-0017
(availability authority — the AI must not claim a human is available unless the availability
service says so); [ADR-0018](../adr/0018-ai-first-visitor-support.md).

**Supersedes [SC-AI1](sc-ai1-operator-ai-drafts-approve-and-send.md) and
[SC-AI2](sc-ai2-controlled-direct-ai-responses.md).** Those charters remain immutable
history; their status lines point here.
[SC-AI3](sc-ai3-ai-assisted-support-and-rag.md) (genuine vector / RAG knowledge base) stays
deferred behind its own ADR, plan, and Product Owner approval.

ADR-0018 is merged **Proposed** in the SC-M07 documentation freeze. Implementation begins
only after a separate, standalone Product Owner implementation-acceptance record
(`docs/decisions/sc-adr-0018-ai-first-po-acceptance.md`) is merged and ADR-0018 becomes
**Accepted** — the ADR-0015 / ADR-0016 / ADR-0017 sequence.

## Objective

Make an AI assistant the **first responder** to a website visitor: it answers basic support
and product questions from explicitly approved site/support content only, retrieves nothing
about customers or orders, takes no action with side effects, and escalates to a human
whenever the visitor asks or the AI cannot answer safely. Operators can take over at any
time and see the complete conversation plus the AI handoff reason. The feature is **disabled
by default** until an authorized operator configures a provider key, approves knowledge
sources, and enables it.

## Product requirements

- **R4** — AI is enabled by administrator **site policy** and visitor **disclosure**, not a
  visitor checkbox.
- **R6** — AI answers routine questions before escalating according to controlled policy.
- **R1** — Ordinary AI-only chat must **not** open Telegram / channel cases; Telegram stays
  an optional transport adapter and no AI request depends on it.

## Included scope

- AI-first lifecycle: the visitor request commits the message + an AI-turn row atomically and
  fires a non-blocking cron kick; **all** provider I/O runs in an async WP-Cron worker.
- A provider-neutral `AiProvider` interface with one **OpenAI** adapter
  (`wp_safe_remote_post`, confined to `src/AI/Provider/`), typed request/result objects, and
  a deterministic `FakeProvider` for tests.
- Secure provider-key storage through `CredentialVault` (AAD `ai.provider_api_key`) in an
  `autoload = false` option, set/rotated/cleared only through a nonce + `MANAGE` `admin_post`
  action; never rendered back, never audited.
- Additive `universal_support_chat_settings` keys: `ai_enabled` (default `false`), a
  fixed-allow-list `ai_model`, output-token / timeout / context-char / retry / daily-request
  / per-conversation-turn caps, and `ai_disclosure_text`; malformed values clamped to safe
  defaults.
- A new `ai` `ConversationMessage` direction value attributed as "AI assistant"; a one-time
  visitor disclosure line; distinct AI bubble rendering in the widget and Hub, `.textContent`
  only, SC-M05 accessibility preserved.
- Bounded **keyword** retrieval over an administrator-approved allow-list of published,
  non-password-protected posts/pages plus operator-authored snippets; canonical plain-text
  snapshots encrypted at rest (AAD `knowledge_source:<source_uuid>`), copied not read live,
  with stale/revoke/hard-delete semantics and per-turn source provenance.
- Explicit human-handoff triggers (visitor asks; refusal; uncertainty; safety-sensitive
  content; repeated provider failure; unsupported/order/account request; rate-limit breach) —
  each transitions to `waiting_for_operator`, writes a plain visitor-visible message, records
  a bounded reason enum, and stops further AI turns for that conversation.
- Operator "Take over from AI" Hub action (existing claim/assignment primitives) and a Hub AI
  panel showing only enums, counts, token totals, provider error classes, and source labels.
- Read-only Diagnostics AI block (safe aggregates only); `ai.*` audit events with fail-closed
  redaction; per-user / per-conversation / daily rate limiting.

## Explicit exclusions

- Any operator-draft "approve and send" co-pilot UX (the former SC-AI1) — no committed slot.
- Embeddings, vector store, semantic search, chunking, or any ingestion pipeline — deferred
  to [SC-AI3](sc-ai3-ai-assisted-support-and-rag.md). The v1 knowledge system is **not**
  "RAG".
- Any autonomous or write-capable action or tool: no coupons, rebates, refunds, discounts,
  order changes, order creation, account changes, or any other side effect.
- Any WooCommerce / order / customer-data integration, including guest order lookup and
  email-based identity linking — a follow-up milestone with its own ADR.
- Multi-provider or provider failover; a non-OpenAI adapter.
- Promised response times, SLA, or ETA copy.
- Any new public/unauthenticated REST route.
- Any Universal Telegram or Contract v1 change; any Telegram notification/dispatch mechanism.
- DEV or production deployment; a live API key; enabling AI on any live site; a plugin
  release or tag; a closure record as part of the implementation PR.

## Acceptance criteria

- With `ai_enabled` off (the default), visitor behaviour is byte-identical to pre-SC-M07 and
  no `ai_turns` rows or provider calls occur.
- With AI enabled and a provider key configured, a routine question is answered by the AI
  from approved content without an operator; every handoff trigger creates a
  `waiting_for_operator` conversation with an honest visitor-visible message and a recorded
  reason.
- With Telegram dispatch enabled and an adapter paired, AI-only turns produce **zero**
  channel ensure/deliver calls (the `is_mirrored_direction()` predicate is unchanged; proven
  by the interop suite).
- An operator can take over from the Hub; once claimed, all queued and future AI turns are
  skipped, and the operator sees the full transcript and the AI handoff reason.
- The AI never performs a mutation; order/account questions always hand off.
- Diagnostics and the Hub AI panel expose only safe aggregates — no key, prompt, answer,
  identifier, timestamp, or raw provider error.
- `universal_support_chat_db_version` is `13`; no new PHP warnings/fatals; all CI gates green;
  the interop suite green with Universal Telegram unchanged.

## Governing ADR

[ADR-0018 — AI-first visitor support: grounded, read-only, human-escalating](../adr/0018-ai-first-visitor-support.md)
(**Proposed** in the SC-M07 freeze).

## Frozen plan

[sc-m07-ai-first-visitor-support-plan-v1.md](../plans/sc-m07-ai-first-visitor-support-plan-v1.md)
— the implementation plan (WP1–WP9), realising ADR-0018. Not authorized for implementation
until the separate Product Owner acceptance record merges.
