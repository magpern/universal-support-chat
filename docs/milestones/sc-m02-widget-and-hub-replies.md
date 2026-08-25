# SC-M02 — Widget and WordPress Hub Replies

## Status

Planned

Depends on: SC-M01

## Objective

Ship a functional website widget baseline and a WordPress Hub operator inbox with **first-class Hub → visitor reply**, without Telegram and without AI.

## Product requirements

- **R5** (partial) — Hub can always manage conversations; channel commands are not required.
- Reinforces **R1** / **R7** — replies and tickets do not depend on Telegram.

## Included scope

- Widget baseline (functional; not final R2/R3 polish).
- Authenticated ownership continuity for visitors.
- Operator inbox/detail workflow in WordPress Hub.
- Hub reply writes to Support Chat SoR and is visible to the visitor via poll/REST.
- Assignment/notes as needed for basic operator workflow.

## Explicit exclusions

- Professional launcher/greeting polish (SC-M05).
- Support hours Automatic/Online/Offline (SC-M06).
- Channel delivery (UT Adapter M1).
- AI drafts (SC-AI1) and direct AI (SC-AI2).

## Acceptance criteria

- Operator can reply from Hub with Universal Telegram inactive; visitor sees the reply.
- Widget works without channel adapter.
- No Telegram tokens or topic IDs stored in Support Chat.

## Frozen plan

[sc-m02-widget-and-hub-replies-plan-v1.md](../plans/sc-m02-widget-and-hub-replies-plan-v1.md)
