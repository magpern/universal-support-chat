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

The next available number for any future ADR is **0010**.

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
