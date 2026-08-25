# ADR-0002 — Plugin Identity and Ownership Boundaries

## Status

Accepted

## Context

Website support chat previously lived inside Universal Telegram. Product direction extracts chat into a standalone plugin so Support Chat works without Telegram, and Telegram becomes an optional channel adapter. Identity and ownership must be fixed before any runtime code.

## Decision

### Identity

| Field | Value |
|---|---|
| Display name | Universal Support Chat |
| Repository / plugin folder / text domain slug | `universal-support-chat` |
| GitHub repository | `magpern/universal-support-chat` |

PHP namespace root, hook/option prefixes, and Composer package name are SC-M00 implementation-plan decisions and must remain consistent with this slug.

### Ownership (Support Chat)

Support Chat owns:

- website chat widget;
- conversations, messages, visitor identity and retention;
- tickets, waiting queue, assignment, notes and audit;
- WordPress Hub inbox and direct operator replies;
- support hours and availability;
- future chat AI;
- privacy and access control for the support domain.

### Non-ownership

- Universal Telegram (and any channel adapter) owns bots, destinations, channel credentials, topics/threads, remote message IDs, outbound channel queues and retry state, and operator-to-channel identity maps.
- Support Chat must never require Telegram to create, store, reply to, or resolve a support conversation.
- Support Chat must never store Telegram tokens, topic IDs, or Telegram-specific persistent state.
- No cross-plugin direct database table access.

### Relationship to Universal Telegram

- Universal Telegram chat tables and chat modules remain **legacy** until SC-M03 cutover; this repository does **not** claim that chat code is already extracted.
- Universal Telegram supersession and Support Chat Adapter documentation is a **separate next step** in the Universal Telegram repository and must pin to Contract v1 (`docs/adr/0005-canonical-support-channel-contract-v1.md`) by **immutable commit SHA** and canonical document URL after this freeze merges.

## Alternatives

- Keep chat inside Universal Telegram — rejected: violates standalone support and optional-Telegram product requirements (R1, R7).
- Shared database schema across plugins — rejected: breaks install isolation.

## Consequences

- Roadmap milestones SC-M00+ implement only Support Chat-owned concerns.
- Channel features go through Contract v1 (ADR-0005).

## Security and privacy impact

Clear ownership reduces accidental secret or PII leakage across plugin boundaries.

## Affected Documents/Milestones

`docs/ARCHITECTURE.md`, `docs/master-plan.md`, all SC milestones; UT Adapter M1 (external repo).

## Compatibility/Migration Impact

Documented only; migration principles in ADR-0004; execution in SC-M03 after UT Adapter M1.
