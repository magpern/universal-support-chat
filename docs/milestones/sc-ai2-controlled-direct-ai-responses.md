# SC-AI2 — Controlled Direct AI Responses

## Status

**Superseded by [SC-M07 — AI-First Visitor Support](sc-m07-ai-first-visitor-support.md)**
([ADR-0018](../adr/0018-ai-first-visitor-support.md), Proposed in the SC-M07 documentation
freeze). SC-M07 defines the AI-first visitor experience directly — grounded, read-only,
human-escalating, disabled by default — without the SC-AI1 prerequisite. This charter is
retained unchanged as immutable history; the scope and acceptance criteria below are not
implemented as written.

Original status: Planned

Depends on: **SC-AI1**; SC-M06 recommended for availability-aware escalation

## Objective

Controlled direct AI first-line responses labelled **AI assistant**, with human escalation and no write-capable tools.

## Product requirements

- **R4** — Enabled by administrator site policy and visitor disclosure; **not** a visitor checkbox.
- **R6** — AI answers routine questions before escalating by controlled policy.
- **R1** — Ordinary AI-only chat must **not** open Telegram/channel cases.

## Included scope

- Site policy enablement + disclosure UX.
- Direct replies attributed as AI assistant.
- Escalation rules to human/Hub (and optional channel only on escalation).
- No write-capable tools.

## Explicit exclusions

- Shipping before SC-AI1.
- Visitor checkbox acknowledgement gate as the product enablement model.
- Channel traffic for non-escalated AI turns.

## Acceptance criteria

- With AI enabled by policy, routine questions can be answered without Hub; escalation creates human ticket path.
- With Telegram connected, AI-only turns produce zero channel ensure/deliver calls.
- Attribution visible as AI assistant.

## Frozen plan

[sc-ai2-controlled-direct-ai-responses-plan-v1.md](../plans/sc-ai2-controlled-direct-ai-responses-plan-v1.md)

Note: A future AI ADR package is required before implementation beyond this boundary freeze.
