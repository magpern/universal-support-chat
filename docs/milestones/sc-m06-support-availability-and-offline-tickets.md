# SC-M06 — Support Availability and Offline Tickets

## Status

Closed (PASS WITH LIMITATIONS) — implementation [PR #53](https://github.com/magpern/universal-support-chat/pull/53)
squash-merged to `main` at `f3b327b79185f02130571a8cdc074b77b8e094f9`; closure
[`docs/closure/sc-m06-support-availability-and-offline-tickets-closure.md`](../closure/sc-m06-support-availability-and-offline-tickets-closure.md).
Accepted limitations: browser QA to the SC-M05 standard and a VoiceOver/NVDA smoke of the
offline widget state — both recommended post-merge, neither run in the implementation
environment. **DEV deployment and Product Owner acceptance remain outstanding** and will be
recorded separately.

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

## Governing ADR

[ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
(**Proposed** in the SC-M06 freeze) — Support Chat is the sole availability authority;
site-timezone evaluation; precedence `manual override → date exception → weekly schedule →
fail-safe unavailable`; visitor-copy honesty; offline ticket = existing authenticated
conversation committed atomically to `waiting_for_operator`.

## Frozen plan

[sc-m06-support-availability-and-offline-tickets-plan-v2.md](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md)
— current, implementation-ready. Supersedes
[sc-m06-support-availability-and-offline-tickets-plan-v1.md](../plans/sc-m06-support-availability-and-offline-tickets-plan-v1.md)
(the original product-boundary stub, retained unedited).
