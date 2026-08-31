# Closure — SC-M07: AI-First Visitor Support

## Status

**Closed (PASS WITH LIMITATIONS). Merged to `main`. Not deployed by this record.**

Documentation-only closure record. No runtime code, test, plugin-version, schema, settings,
CI, dependency, Universal Telegram, DEV, production, deployment, tag, release, or live API
key change is made by this record.

The accepted limitations are validation activities this environment could not run and that
are **not** claimed as passed:

1. **Real-provider verification.** All tests use the deterministic `AI\Provider\FakeProvider`;
   no real OpenAI request is made in CI (structural — see below). A single manual
   verification against the live OpenAI endpoint, using the env-var-guarded non-CI path, is
   recommended as part of DEV acceptance.
2. **Browser QA to the SC-M05 standard** (Playwright + axe-core + Lighthouse) of the AI
   bubble, the one-time disclosure line and its `aria-live` announcement, the `ai_pending`
   state, and the handoff state. Not executed here.
3. **Human assistive-technology smoke** (VoiceOver / NVDA) of the disclosure note and the
   AI-vs-operator bubble distinction — the same limitation shape carried by SC-M05 / SC-M06.
4. **Functional DEV acceptance and a Product Owner functional sign-off** per
   [plan v1 §13](../plans/sc-m07-ai-first-visitor-support-plan-v1.md) — deferred to a
   separate, explicitly-authorized step. See
   [`sc-m07-dev-acceptance-plan.md`](sc-m07-dev-acceptance-plan.md).

None of these limitations blocks the merge (already done). DEV acceptance and production
remain separate, explicitly-authorized later steps.

## What this closes

SC-M07 ([charter](../milestones/sc-m07-ai-first-visitor-support.md), requirements
**R1** / **R4** / **R6**), realising
[ADR-0018 — AI-first visitor support: grounded, read-only, human-escalating](../adr/0018-ai-first-visitor-support.md)
(Accepted) exactly within the frozen scope of
[plan v1](../plans/sc-m07-ai-first-visitor-support-plan-v1.md) §9 (WP1–WP9). **Supersedes
SC-AI1 and SC-AI2** (charters retained, immutable).

- An **AI assistant answers a visitor first** when an operator has set `ai_enabled` **and**
  configured a provider key; disabled by default, byte-identical legacy behaviour when off.
- **The provider is never called in the visitor request** — `AI\Turn\AiResponder` commits
  the visitor message and a queued `ai_turns` row (plus any existing content-free ADR-0012
  outbox row) in one transaction and fires a non-blocking cron kick; **all** provider I/O
  runs in `AI\Turn\AiTurnWorker`, a WP-Cron worker on the `DispatchWorker` pattern
  (recurring sweep + immediate hook, lease-based crash recovery, bounded retry/backoff).
- AI answers are a new **`ConversationMessage` direction value `ai`** (free-form
  `VARCHAR(16)`; no column change), attributed "AI assistant", **structurally never mirrored
  to Telegram** — `DispatchEnqueuer::is_mirrored_direction()` matches only `visitor` /
  `operator` and was not extended (master-plan **R1**; asserted in unit **and** integration).
- **Bounded keyword-overlap retrieval** over an administrator-approved allow-list, stored as
  canonical plain-text snapshots **encrypted at rest** (`CredentialVault`, AAD context
  `knowledge_source:<source_uuid>`), **copied at approval / reindex, never read live**. This
  is **not** embeddings / a vector store / semantic search / "RAG" — that stays SC-AI3.
- **Zero tools; no side effects.** The AI produces only an inert-text message. Safety-
  sensitive, human-request, and order/account/side-effecting requests are caught by a
  deterministic server-side pre-check **before** the model and hand off; model refusal /
  uncertainty / repeated provider failure / rate-limit breach also hand off. Every handoff
  writes a plain visitor-visible `system` message, transitions to `waiting_for_operator`,
  records a bounded `handoff_reason` enum, emits `ai.handoff` (`ai.escalation` for safety),
  and **stops every further AI turn** for that conversation.
- **Operator takeover** — a Hub "Take over from AI" `admin_post` action claims the
  conversation and skips queued turns; the worker re-checks eligibility immediately before
  every provider call. A read-only Hub AI panel shows enum labels, counts, token totals,
  provider error classes, and knowledge-source labels with a "same text / content changed
  since this turn" flag — never a prompt, answer body, key, identifier, timestamp, or raw
  error.
- **Model / spending controls** — nine additive `universal_support_chat_settings` keys
  (`ai_enabled` default `false`; `ai_model` from the fixed allow-list
  `['gpt-4o-mini', 'gpt-4o']`; output-token / timeout / context-char / retry / daily-request
  / per-conversation-turn caps clamped to range; disclosure text). The OpenAI key is
  `CredentialVault`-encrypted (AAD `ai.provider_api_key`) in the `autoload = false` option
  `universal_support_chat_ai_provider_secret`, written only through a nonce + `MANAGE`
  `admin_post` action, never rendered back, never audited.
- **Diagnostics** gains a read-only "AI assistant" block (safe aggregates only). `ai.*`
  audit rows and the `ai_turns` table hold only ids, counts, and enums.
- **Schema:** migration step 13 adds `universal_support_chat_ai_turns` (metadata-only
  `verify_step_13`) and `universal_support_chat_knowledge_sources` (encrypted-content-only
  `verify_step_13`); `universal_support_chat_db_version` **`12 → 13`**.
- **Version:** repository-code-only increase **`0.8.0 → 0.9.0`** (asset cache-bust; no
  release, no tag).
- The plan §8 automated test matrix (unit, integration, and structural boundary tests).

## Gates and SHAs

| Gate | SHA / URL |
|---|---|
| SC-M07 documentation freeze (ADR-0018 Proposed + plan v1) | `537d3b050040e68f8bc227b9fd104b0fe9ab82ad` — [PR #57](https://github.com/magpern/universal-support-chat/pull/57) |
| Product Owner implementation acceptance (ADR-0018 → Accepted) | `b47ce61a4b044e5d0dccb82588b3b2be81365785` — [PR #58](https://github.com/magpern/universal-support-chat/pull/58) (`docs/decisions/sc-adr-0018-ai-first-po-acceptance.md`) |
| Implementation PR | [PR #59](https://github.com/magpern/universal-support-chat/pull/59) |
| Implementation branch | `feature/sc-m07-ai-first-visitor-support` |
| WP1 — persistence, migration step 13, repositories | `eaf3e9f` |
| WP2 — provider interface, OpenAI adapter, credentials | `68db6a4` |
| WP3 — AI settings section and provider-key admin action | `54e0005` |
| WP4 — `ai` conversation direction, widget and Hub rendering | `71e1bc9` |
| WP5 — approved-knowledge indexer, retriever, Hub submenu | `78dee9e` |
| WP6 — AI turn queue, async worker, escalation state machine | `7f78e4d` |
| WP7 — operator takeover and the Hub AI panel | `d62b9da` |
| WP8 — Diagnostics block, retention, uninstall, redaction | `7a1a629` |
| WP9 — wiring, version bump, structural test, final gate | `616f53b` |
| **Squash-merge commit on `main`** | **`a81390086e37af04eba0a0ee1874949376be2c5a`** |

Both baselines are verified ancestors of `origin/main` (`537d3b0` and `b47ce61` precede the
merge commit `a813900`). ADR-0018 is **Accepted**; plan v1 is frozen and its authorization
line cites the PO-acceptance record. The plan was not revised during the milestone.

## Automated verification (all green)

Run in CI on [PR #59](https://github.com/magpern/universal-support-chat/pull/59) and
re-verified locally in Docker:

| Job | Result |
|---|---|
| `phpcs` (`WordPress-Extra`) | pass |
| `static-analysis` (PHPStan level 5) | pass |
| `unit` (PHP 8.1 / 8.3 / 8.4) | pass — 169 tests |
| `integration-wp-only-floor` (WP 6.9 / PHP 8.1) | pass — 251 tests |
| `integration-wp-only-current` (WP 7.1 / PHP 8.3) | pass — 251 tests |
| `interop` (WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3) | pass — 10 tests each; Universal Telegram pinned at the CI ref `9b4a6ef`, read-only, untouched |
| `docs` (`check-doc-links`) | pass |

**"No real AI provider call in CI" is structural, not policy:** the unit suite makes no
HTTP; the integration and interop suites wire `AI\Provider\FakeProvider`; and
`tests/unit/Core/StructuralBoundariesTest.php` asserts every `wp_remote_*` /
`wp_safe_remote_*` call in `src/` lives under `src/AI/Provider/` (reached only by the async
worker) and that `is_mirrored_direction('ai') === false`.

## Limitations carried forward

1. **Real-provider verification** — one manual call against the live OpenAI endpoint, via
   the env-var-guarded non-CI path, during DEV acceptance.
2. **Browser QA to the SC-M05 standard** — Playwright + axe-core + Lighthouse of the AI
   bubble, disclosure line + `aria-live`, `ai_pending`, and handoff states.
3. **Human AT smoke** (VoiceOver / NVDA) of the disclosure note and the AI-vs-operator
   bubble distinction. Record screen-reader + browser + OS versions.
4. **Functional DEV acceptance + Product Owner functional sign-off** — see
   [`sc-m07-dev-acceptance-plan.md`](sc-m07-dev-acceptance-plan.md).

Limitations 2–3 are the same follow-up shape SC-M05 / SC-M06 carry. None gates the merge
(done) or any deployment decision on its own.

## Explicit non-implementation / unchanged

Per ADR-0018, plan v1 §11, and the PO-acceptance record — none of the following was touched:

- **Order lookup / WooCommerce / customer data** — none. Order-specific, account, refund,
  coupon, discount, and other side-effecting requests always hand off. No WooCommerce CI
  job was added; `NoTelegramCouplingTest`'s `WooCommerce` string ban stays satisfied.
- **Vector / embeddings / RAG / chunking / ingestion pipeline** — none. The knowledge
  system is bounded in-PHP keyword overlap; genuine vector retrieval stays deferred to
  [SC-AI3](../milestones/sc-ai3-ai-assisted-support-and-rag.md).
- **Tools / function-calling / autonomous actions** — none. Zero tools in v1.
- **Universal Telegram / Contract v1** — no change. The interop run used a read-only
  checkout of Universal Telegram pinned at `9b4a6ef`; that repository is untouched. No
  Telegram notify or dispatch mechanism was added; where ADR-0012 dispatch is enabled, only
  `visitor` / `operator` messages are mirrored by the existing `DispatchWorker` — an `ai`
  message is never mirrored.
- **REST** — no new route (no public availability or AI endpoint); the poll response gains
  one additive `ai_pending` boolean. Visitor isolation and the authenticated-only path are
  unchanged.
- **Capabilities** — no new capability; `MANAGE` gates the settings section, the
  provider-key action, the "AI Knowledge" submenu, the takeover action, and the Hub AI
  panel.
- **`ConversationStatus`** — one added map edge, `waiting_for_visitor → waiting_for_operator`
  (a pure code constant; no stored data touched; every existing row stays valid). Existing
  rows and statuses remain valid on a downgrade to a pre-SC-M07 build.
- **Retention / uninstall** — `ai_turns` rows are purged with their conversation by the
  existing daily job; the two new tables and the provider-secret option are removed only
  under the existing opted-in `remove_data_on_uninstall` path. Knowledge sources are
  config-like admin data and are never touched by the conversation retention sweep.
- **CI / dependencies / build tooling** — no workflow change; no Composer, npm, or
  browser-CI change.
- **Multi-provider / failover / a non-OpenAI adapter; a visitor AI opt-in checkbox; an
  AI-generated greeting; response-time / SLA / ETA copy; re-enabling AI after
  handoff/takeover** — none (out of scope, plan v1 §11).

## Deviations from ADR-0018 / plan v1

- Plan v1 §9's "daily re-checksum sweep" is implemented as a dedicated daily WP-Cron hook
  `universal_support_chat_ai_knowledge_recheck` (a naming detail).
- Uninstall table removal cannot be asserted in the WordPress test harness (it rewrites
  `DROP TABLE` to a temporary-table no-op — true of every plugin table); it is covered by
  an option-removal integration test plus a source assertion. No behavioural deviation.
- No other deviation.

## Non-authorization

This closure authorizes nothing operational. The feature is merged to `main` at
`a81390086e37af04eba0a0ee1874949376be2c5a` but has **not** been deployed to DEV or
production. No plugin was activated, deactivated, or updated on any live site; no
`wp option` value was changed on any live site; no OpenAI request was made; no API key was
set on any live site; no Telegram message, webhook, bot, group, topic, pairing, or
credential action occurred; no GitHub Release or version tag was created. Deploying to DEV
(with the [`sc-m07-dev-acceptance-plan.md`](sc-m07-dev-acceptance-plan.md) steps) and later
production are separate, explicitly-authorized steps.

## Documents

- [SC-M07 charter](../milestones/sc-m07-ai-first-visitor-support.md)
- [Plan v1 — `sc-m07-ai-first-visitor-support-plan-v1.md`](../plans/sc-m07-ai-first-visitor-support-plan-v1.md) — frozen; authorization line cites the PO-acceptance record.
- [ADR-0018 — AI-first visitor support: grounded, read-only, human-escalating](../adr/0018-ai-first-visitor-support.md) — Accepted.
- [`docs/decisions/sc-adr-0018-ai-first-po-acceptance.md`](../decisions/sc-adr-0018-ai-first-po-acceptance.md) — Approved.
- [Feature PR #59](https://github.com/magpern/universal-support-chat/pull/59)
- [`sc-m07-dev-acceptance-plan.md`](sc-m07-dev-acceptance-plan.md) — the DEV acceptance runbook (deployment not yet performed).

## Next

DEV deployment and a Product Owner functional sign-off of SC-M07 remain outstanding and will
be recorded separately (as SC-M05's and SC-M06's DEV records were). SC-AI3 (genuine vector /
RAG knowledge base) remains a deferred future note requiring its own ADR, plan, and Product
Owner approval; it now builds on the SC-M07 provider and knowledge seams rather than the
retired SC-AI1 / SC-AI2 boundaries.
