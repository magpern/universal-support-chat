# Architecture Reference — Universal Support Chat

This document records product boundaries, contracts, and versioning conventions for Universal Support Chat. It is documentation-only at foundation; runtime module directories do not exist until SC-M00+.

## Product identity

See [ADR-0002](adr/0002-plugin-identity-and-ownership-boundaries.md).

- Display name: **Universal Support Chat**
- Slug: `universal-support-chat`
- Standalone WordPress plugin; **must work fully without Universal Telegram**

## Product boundaries (planned)

| Boundary | Responsibility | First milestone |
|---|---|---|
| Core | Bootstrap, configuration, capabilities, vault, lifecycle | SC-M00 |
| Persistence | Schema migrations, health | SC-M00 |
| Privacy / Audit | Classification, redaction, audit log | SC-M00 |
| Conversations | Conversations, messages, tickets, waiting, assignment, notes, retention, visitor REST | SC-M01 |
| ChatWidget | Visitor widget (functional then polished) | SC-M02 / SC-M05 |
| Administration (Hub) | Operator inbox, reply, workflow | SC-M02 |
| Availability | Schedule, exceptions, Automatic/Online/Offline | SC-M06 |
| AI | Operator drafts + approve-and-send; later direct AI | SC-AI1 / SC-AI2 |
| ChannelContract | Server-side Contract v1 authority and discovery | SC-M01+ / before UT Adapter M1 consumers |

Channel adapters (e.g. Universal Telegram) are **external plugins**, not boundaries inside this repository.

## Canonical Contract v1

[ADR-0005 — Canonical Support Channel Contract v1](adr/0005-canonical-support-channel-contract-v1.md) is the sole canonical cross-plugin contract.

Consumers must pin:

1. The immutable git commit SHA on `main` that contains the accepted ADR-0005 text used for implementation, and
2. The canonical document URL in this repository.

Do not maintain a second full copy of Contract v1 in another repository.

## Optional channel failure model

[ADR-0006](adr/0006-optional-channel-and-adapter-failure-model.md): fail closed for the channel only; Hub and website chat continue.

## Migration

[ADR-0004](adr/0004-migration-and-retention-principles.md): no dual-write; quiesced one-shot cutover; UT Adapter M1 before SC-M03.

## Security and privacy

[ADR-0003](adr/0003-security-privacy-and-visitor-isolation.md).

## Execution sequence (locked)

1. SC-M00 → SC-M01 → SC-M02
2. **UT Adapter M1** (Universal Telegram repository; after Contract v1 exists)
3. SC-M03 (migration/cutover)
4. SC-M04 (telegram-optional acceptance)
5. SC-M05, SC-M06, **SC-AI1**, then **SC-AI2**

SC-AI1 precedes SC-AI2.

## Versioning conventions (planned)

- Plugin SemVer and independent integer `db_version` — defined in SC-M00 plan when runtime code begins.
- No Contract v1 release tag is required for adapter pinning; commit SHA is sufficient.
- This foundation freeze creates **no** plugin version, schema, tag, or release.

## Where to look

- Governance: `docs/governance.md`
- Master plan / roadmap: `docs/master-plan.md`
- Milestones: `docs/milestones/`
- ADRs: `docs/adr/`
- Plans: `docs/plans/`
- Testing: `docs/testing/`
- Closure: `docs/closure/`
