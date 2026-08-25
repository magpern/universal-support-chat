# SC-M01 — Conversation System of Record

## Status

Closed (PASS) — see `docs/closure/sc-m01-conversation-system-of-record-closure.md`.

Depends on: SC-M00

## Objective

Persist conversations, messages, ownership, retention, and visitor REST as the Support Chat system of record — with **no Telegram dependency** and **no AI**.

## Product requirements

- **R1** — Support Chat works without Telegram; conversation SoR does not call channel adapters for ordinary message storage.

## Included scope

- Conversations and messages persistence (encrypted at rest per ADR-0003).
- Visitor identity / ownership model.
- Retention defaults and purge hooks (Support Chat–owned).
- Visitor REST: start/mine/post/poll (exact shapes in frozen plan).
- Notes/assignment schema foundations as required for later Hub (may be partial if SC-M02 owns Hub UX).

## Explicit exclusions

- Widget UI and Hub reply UX (SC-M02).
- Channel ensure/deliver calls beyond optional inert contract stubs if needed for compile-time boundaries (no live Telegram).
- AI drafts or acknowledgement checkboxes.
- Migration from Universal Telegram (SC-M03).

## Acceptance criteria

- Create and continue conversations without Universal Telegram installed.
- Visitor isolation: no cross-visitor transcript access.
- Retention job does not require Telegram.
- Automated tests cover ownership and REST authz.

## Frozen plan

[sc-m01-conversation-system-of-record-plan-v1.md](../plans/sc-m01-conversation-system-of-record-plan-v1.md)
