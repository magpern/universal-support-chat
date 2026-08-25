# ADR-0001 — Project Governance

## Status

Accepted

## Context

Universal Support Chat is a new standalone WordPress plugin repository. Without explicit roles, freeze rules, and closure authority, documentation and implementation agents cannot distinguish drafts from decisions.

## Decision

Adopt the governance model documented in `docs/governance.md`:

- Product Owner approves scope, priorities, milestone acceptance, and material product decisions.
- Master Architect owns architecture, milestone boundaries, ADR decisions, plan review, and architectural close recommendations.
- Implementation Agent prepares evidence-backed proposals and implements only frozen plans; never treats its own drafts as decisions.
- Independent Tester performs acceptance testing independent of the Implementation Agent’s suite when required by a milestone’s charter.

Freeze model: a milestone’s implementation plan and every new ADR that authorizes it must be accepted and included in the same code-free freeze commit, or already exist from an earlier documentation-only commit. No implementation code may precede the ADRs it relies on.

Revised plans supersede earlier plans via new files; earlier plan files are never edited or deleted.

Closure statuses: PASS, PASS WITH LIMITATIONS, FAIL, DEFERRED — Product Owner decides.

## Alternatives

- Informal chat-only decisions — rejected: no durable evidence trail.
- Implementation Agent self-certifying closure — rejected: no separation of duties.

## Consequences

- All milestones follow `docs/governance.md` and `docs/plans/README.md`.
- This documentation foundation freeze establishes the baseline before SC-M00 code.

## Security and privacy impact

None directly; governance constrains how security-affecting ADRs are accepted.

## Affected Documents/Milestones

`docs/governance.md`; all milestones.

## Compatibility/Migration Impact

None — new repository.
