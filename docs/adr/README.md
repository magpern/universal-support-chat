# Architecture Decision Records — Conventions

## Numbering and status

- Sequential, never reused: `docs/adr/NNNN-kebab-slug.md`, four digits, starting at 0001.
- Status values: Proposed, Accepted, Deprecated, Superseded by ADR-XXXX.
- Reserved numbers for this foundation freeze:

| Number | Decision |
|---|---|
| 0001 | Project governance |
| 0002 | Plugin identity and ownership boundaries |
| 0003 | Security, privacy, and visitor isolation |
| 0004 | Migration and retention principles |
| 0005 | Canonical Support Channel Contract v1 |
| 0006 | Optional channel and adapter failure model |
| 0007 | Contract v1 mutual signed adapter authentication profile |
| 0008 | Legacy export boundary and migration authority model |
| 0009 | Legacy binding preparation boundary and non-routing prepared status |
| 0010 | Final cutover handoff contract and cohort activation |
| 0011 | `channel_case_ref` is the Support Chat conversation UUID (F1 correction to ADR-0010 §4) |
| 0012 | Automatic Support Chat → Telegram message dispatch (SC-owned outbox) |
| 0013 | Retirement of the obsolete SC-M03 legacy-migration / final-cutover engine |
| 0014 | Interactive delivery class for Support Chat → Telegram, and a bounded immediate dispatch attempt |
| 0015 | Operator-facing Support Chat Settings page, and separation of read-only Diagnostics |

The next available number for any future ADR is **0016**.

## Immutability

Once an ADR is Accepted, its Context, Decision, Alternatives, Consequences, Security and privacy impact, Affected Documents/Milestones, and Compatibility/Migration Impact sections are never edited. Only the Status field may later change to Deprecated or Superseded by ADR-XXXX. A changed decision is always a new ADR.

## Required sections

1. Status
2. Context
3. Decision
4. Alternatives
5. Consequences
6. Security and privacy impact
7. Affected Documents/Milestones
8. Compatibility/Migration Impact

## When an ADR is required

Architecture or composition pattern; a security boundary; a persistence model; a public contract; a milestone boundary; significant product behaviour with no prior precedent; a previously accepted decision that must change.

## Index (foundation freeze)

- [ADR-0001 — Project governance](0001-project-governance.md)
- [ADR-0002 — Plugin identity and ownership boundaries](0002-plugin-identity-and-ownership-boundaries.md)
- [ADR-0003 — Security, privacy, and visitor isolation](0003-security-privacy-and-visitor-isolation.md)
- [ADR-0004 — Migration and retention principles](0004-migration-and-retention-principles.md)
- [ADR-0005 — Canonical Support Channel Contract v1](0005-canonical-support-channel-contract-v1.md)
- [ADR-0006 — Optional channel and adapter failure model](0006-optional-channel-and-adapter-failure-model.md)
- [ADR-0007 — Contract v1 mutual signed adapter authentication profile](0007-contract-v1-mutual-signed-adapter-authentication-profile.md)
- [ADR-0008 — Legacy export boundary and migration authority model](0008-legacy-export-boundary-and-migration-authority-model.md)
- [ADR-0009 — Legacy binding preparation boundary and non-routing prepared status](0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md)
- [ADR-0010 — Final cutover handoff contract and cohort activation](0010-final-cutover-handoff-contract-and-cohort-activation.md)
- [ADR-0011 — `channel_case_ref` is the Support Chat conversation UUID (F1 correction to ADR-0010 §4)](0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md) — Accepted 2026-08-27
- [ADR-0012 — Automatic Support Chat → Telegram message dispatch (SC-owned outbox)](0012-automatic-support-chat-to-telegram-dispatch.md) — Accepted
- [ADR-0013 — Retirement of the obsolete SC-M03 legacy-migration / final-cutover engine](0013-retirement-of-obsolete-sc-m03-migration-cutover-engine.md) — Accepted
- [ADR-0014 — Interactive delivery class for Support Chat → Telegram, and a bounded immediate dispatch attempt](0014-interactive-chat-delivery-class-and-immediate-dispatch.md) — Proposed
- [ADR-0015 — Operator-facing Support Chat Settings page, and separation of read-only Diagnostics](0015-operator-settings-page-and-diagnostics-separation.md) — Accepted 2026-08-29
