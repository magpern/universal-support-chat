# SC-M07 — DEV Acceptance Plan

## Status

**Planning-only. Nothing deployed, configured, or executed by this record.** No runtime
plugin code, test, plugin-version, schema, settings, database data, CI, Compose, Universal
Telegram, DEV, production, tag, release, or API-key change is made by it. Production remains
untouched throughout SC-M07 DEV acceptance.

Extends the [SC-M07 closure](sc-m07-ai-first-visitor-support-closure.md) (Closed — PASS WITH
LIMITATIONS). It expands [plan v1 §13](../plans/sc-m07-ai-first-visitor-support-plan-v1.md)
into an operator runbook. Deploying to DEV and running the walkthrough below are a
**separate, explicitly-authorized step** — this document does not authorize them; it
describes them.

## Pre-conditions

| Item | Value |
|---|---|
| Merge commit on `main` | `a81390086e37af04eba0a0ee1874949376be2c5a` (SC-M07 WP1–WP9, squash of [PR #59](https://github.com/magpern/universal-support-chat/pull/59)) |
| Freeze / acceptance baselines | `537d3b0` (PR #57) / `b47ce61` (PR #58) — verified ancestors of the merge |
| DEV checkout path | `/opt/biopentra/dev/universal-support-chat` (bind-mounted read-write into the `wordpress` container) |
| DEV checkout SHA before this deploy | `4cdd213e0b9a5da1cf6802063b05196402a68b7f` (SC-M06 DEV state) |
| `db_version` on DEV before this deploy | `12` |
| Plugin version on DEV before this deploy | `0.8.0` |

**Do not proceed** unless a Product Owner has explicitly authorized the DEV deployment and
provided a dedicated OpenAI API key for DEV (see "API key" below).

## Deployment method (no new mechanism)

The DEV VPS bind-mounts `/opt/biopentra/dev/universal-support-chat` into the running
WordPress container; advancing that checkout's Git ref is the deployment — the same method
used for SC-M05 and SC-M06.

1. In the DEV checkout: `git fetch origin` then `git pull --ff-only origin main`
   (`4cdd213..a813900` — must be a clean fast-forward, no merge commit, working tree clean
   apart from any untracked local `.claude/` directory, which is not served).
2. `docker compose restart wordpress` (restart, not recreate — same container, same image)
   so the new PHP loads under a fresh opcache.
3. No other service is touched. No Compose file change. No `proxy/` change.

After step 2, the first authenticated request runs migration **step 13**: it creates
`universal_support_chat_ai_turns` and `universal_support_chat_knowledge_sources` and advances
`universal_support_chat_db_version` `12 → 13`. AI is **disabled** (`ai_enabled = false`)
until step 3 of configuration below.

## Post-deployment technical health checks (before any AI configuration)

Run these with AI still disabled — behaviour must be byte-identical to SC-M06 DEV:

- [ ] `curl -sI https://dev.biopentra.eu` → `200`; wp-admin loads.
- [ ] `ss -tuln` public listeners are exactly `2222`, `80`, `443` (no new published port).
- [ ] `docker compose logs wordpress --since 10m` — no new PHP warnings, notices, or fatals.
- [ ] WP-CLI: `wp eval 'echo get_option("universal_support_chat_db_version");'` → `13`.
- [ ] WP-CLI: `wp eval 'echo UNIVERSAL_SUPPORT_CHAT_VERSION;'` → `0.9.0`.
- [ ] Diagnostics page: "AI assistant" row = **disabled**; "AI provider key" = **not configured**.
- [ ] Send a visitor message as a logged-in test user — it stores and appears in the Hub
      exactly as before; **no `ai_turns` row is created** (`wp db query 'SELECT COUNT(*) FROM
      wp_universal_support_chat_ai_turns;'` → `0`).
- [ ] Telegram dispatch setting and the active `universal-telegram` peer are unchanged; no
      Telegram traffic.

## AI configuration (Product Owner decision to enable)

### API key

- The DEV OpenAI key must be a **dedicated key for DEV**, with a **hard usage limit** set on
  the OpenAI dashboard (spend cap and/or a low monthly budget). It must not be a production
  key and must not be reused elsewhere.
- Set it only through **Support Chat → Settings → AI Assistant → OpenAI API key** (the
  nonce + `MANAGE` `admin_post` form). Never via `wp option update` or `wp-config.php`. The
  stored key is vault-encrypted in an `autoload = false` option and is never shown back.
- Confirm on Diagnostics: "AI provider key" = **configured** (and not "configured
  (fail-closed)").

### Knowledge

- Support Chat → Conversations → **AI Knowledge**: approve two or three **published,
  non-password-protected** posts/pages that contain answerable support content, and add one
  operator snippet (e.g. a returns or shipping policy).
- Confirm each shows status **approved**.

### Enable

- Support Chat → Settings → AI Assistant: set a plain-text **Visitor disclosure**, keep the
  default model (`gpt-4o-mini`) and the default caps, then tick **Enable the AI assistant**
  and save.
- Confirm Diagnostics: "AI assistant" = **enabled**; "AI model" = `gpt-4o-mini`;
  "AI knowledge sources (approved / stale / revoked)" reflects the approvals.

## Functional walkthrough (plan v1 §13)

Run each as a logged-in test visitor. Between scenarios, note the Hub AI panel values.

| # | Scenario | Expected |
|---|---|---|
| 1 | Ask a question answered by approved content ("Do you ship to Norway?" style) | An **AI assistant** bubble appears within ~1–2 cron cycles; the answer is grounded in the approved content; the one-time disclosure line showed once; the Hub AI panel shows status `answered`, a finish reason, token totals, and the source label with "same text". |
| 2 | Ask something **not** in the approved content | The AI hands off: a plain "I'm not able to answer that confidently…" `system` message, the conversation moves to **Waiting**, the Hub panel shows `handoff_reason = uncertain` (or `refused`). No AI answer. |
| 3 | Ask for a person ("can I talk to a human?") | Handoff **before** any provider call (`handoff_reason = visitor_requested`); the Hub AI panel shows `tool calls = 0`; no provider tokens recorded for that turn. |
| 4 | Ask for a refund / discount code / to cancel an order | Handoff `handoff_reason = unsupported_request`; the AI never claims it can act. |
| 5 | Send a safety-sensitive message (e.g. "I want to file a chargeback") | Handoff `handoff_reason = safety`; an `ai.escalation` audit event in addition to `ai.handoff`. |
| 6 | While a conversation is active, an operator clicks **Take over from AI** in the Hub | The conversation is claimed; any queued AI turn is `skipped`; a subsequent visitor message produces **no** new AI turn; the operator sees the full transcript and the AI handoff reason. |
| 7 | Set the availability schedule so the team is **offline**, then start a conversation and let the AI hand off | The handoff `system` message uses the **honest offline copy** (no "online", no response-time estimate) — ADR-0017 §5. |
| 8 | With Telegram dispatch **enabled** and the `universal-telegram` peer paired, run scenario 1 again | **Zero** Telegram traffic for the AI turn: no topic ensure, no `deliver_message`. Confirm via `docker compose logs` for both plugins and the dispatch outbox (`SELECT COUNT(*) … WHERE …` for the `ai` message's uuid → `0`). The `visitor` message still mirrors as normal. |
| 9 | Disable WP-Cron briefly (or just observe) — send a visitor message | The visitor request returns immediately; **no provider call happens in the request**; the AI answer appears once the recurring 60 s sweep runs. |
| 10 | Exhaust the per-conversation turn cap (send many messages) | Once the cap is passed, the next turn hands off `handoff_reason = rate_limited` — an honest handoff, not an error. |
| 11 | Edit one approved post's content, then ask a question that used it | The source is marked **needs re-approval** (stale) and is excluded from retrieval until re-approved; unpublish it → it is **revoked** and its stored ciphertext is NULLed. |

## Real-provider verification (closure limitation 1)

- Scenario 1 above already exercises the live OpenAI endpoint through the normal path.
- Additionally, run the env-var-guarded non-CI provider script (if present) once against the
  real endpoint to confirm the adapter's request shape and error mapping against the live
  API. Record the model, latency, and token counts — no transcript.

## Cost / rate-limit monitoring

- Diagnostics: "AI turns today vs daily cap", "AI handoffs today", "AI last provider error
  class".
- `ai.*` audit events (`ai.config_changed`, `ai.token_rotated`, `ai.handoff`,
  `ai.escalation`, `ai.takeover`, `ai.knowledge_source_changed`) — ids / counts / enums
  only.
- The OpenAI dashboard usage graph for the DEV key.

## Rollback

If any health check or scenario fails in a way that cannot be fixed forward:

1. **Disable first:** Support Chat → Settings → AI Assistant → untick **Enable the AI
   assistant**, save. This stops all AI activity immediately without a redeploy.
2. If a redeploy is needed: in the DEV checkout `git checkout 4cdd213e0b9a5da1cf6802063b05196402a68b7f`
   then `docker compose restart wordpress`. Note: `db_version` stays at `13` and the two AI
   tables remain (inert) — a pre-SC-M07 build simply stops using them; existing `ai`
   messages and `waiting_for_operator` rows stay valid.
3. Optionally clear the provider key (Settings → AI Assistant → **Remove key**) and revoke
   it on the OpenAI dashboard.

## Product Owner functional sign-off

To be completed after the walkthrough:

> Product Owner functional acceptance — SC-M07 on DEV
>
> _(pending)_
>
> Signed: Product Owner
> Date:

Until this is signed, SC-M07 remains **Closed (PASS WITH LIMITATIONS)** with functional DEV
acceptance outstanding, and production is not in scope.

## Production

Explicitly out of scope. A separate, later Product Owner decision, its own record, and its
own runbook. No release or tag is created by SC-M07's documentation, implementation, or DEV
acceptance.
