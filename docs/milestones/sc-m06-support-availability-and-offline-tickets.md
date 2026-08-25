# SC-M06 — Support Availability and Offline Tickets

## Status

Planned

Depends on: SC-M02; soft dependency on SC-M04 for telegram-optional proof

## Objective

Own support schedule, exceptions, manual `Automatic / Online / Offline`, waiting queue UX, and truthful offline human-ticket behaviour.

## Product requirements

- **R5** — Support Chat owns schedule, exceptions, Automatic/Online/Offline, waiting queue, Hub administration. Telegram `/support` only if adapter active.
- **R7** — Human request always creates durable Support Chat ticket with truthful offline wording; Telegram notify optional; ticket never depends on Telegram.

## Included scope

- Weekly schedule (site timezone), exceptions, manual override.
- Waiting queue and Hub surfacing.
- Offline copy configuration.
- Optional `notify_operators` / `ensure_channel_case` when adapter present — never required for ticket persistence.

## Explicit exclusions

- Implementing Telegram `/support` inside Support Chat (adapter-owned).
- AI first-line (SC-AI2).

## Acceptance criteria

- Ticket created offline with Telegram uninstalled.
- Hub manages waiting conversations.
- With adapter connected, notify may occur without being on the ticket-creation critical path.

## Frozen plan

[sc-m06-support-availability-and-offline-tickets-plan-v1.md](../plans/sc-m06-support-availability-and-offline-tickets-plan-v1.md)
