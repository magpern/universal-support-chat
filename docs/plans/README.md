# Implementation Plan Conventions

A definitive implementation plan is required before any milestone's implementation begins (`docs/governance.md`).

## Required structure

Each plan at `docs/plans/<id>-plan-vN.md` must contain:

1. Reference to the milestone charter and every ADR it relies on or introduces.
2. Repository findings at plan-drafting time.
3. Assumptions and open questions (separated from decisions).
4. Architectural decisions with alternatives/tradeoffs (cite ADRs).
5. Directory, namespace, schema, and API impact (scoped).
6. Security and privacy impact.
7. Test and CI impact.
8. Work packages in execution order.
9. Risks and mitigations.
10. Explicit out-of-scope list.
11. Definition of done matching the charter acceptance criteria.

## Freeze, revision, and supersession

- Plans are committed code-free with required ADRs, or after those ADRs already exist.
- Once committed, a plan is immutable. Changes require a new `vN+1` file that supersedes the prior plan.
- Implementation reports cite the plan-freeze commit SHA.

## Foundation freeze plans

This foundation documentation freeze includes boundary plans for the initial roadmap. SC-M00–SC-M04 plans are implementation-ready architecture freezes. SC-M05, SC-M06, SC-AI1, and SC-AI2 plans freeze product boundaries; they may require additional ADRs before coding.

| Plan | Milestone |
|---|---|
| [sc-m00-foundation-plan-v1.md](sc-m00-foundation-plan-v1.md) | SC-M00 |
| [sc-m01-conversation-system-of-record-plan-v1.md](sc-m01-conversation-system-of-record-plan-v1.md) | SC-M01 |
| [sc-m02-widget-and-hub-replies-plan-v1.md](sc-m02-widget-and-hub-replies-plan-v1.md) | SC-M02 |
| [ut-adapter-m1-dependency-plan-v1.md](ut-adapter-m1-dependency-plan-v1.md) | UT Adapter M1 (external) |
| ~~[sc-m03-controlled-migration-and-cutover-plan-v1.md](sc-m03-controlled-migration-and-cutover-plan-v1.md)~~ (superseded) | SC-M03 |
| [sc-m03-controlled-migration-and-cutover-plan-v2.md](sc-m03-controlled-migration-and-cutover-plan-v2.md) | SC-M03 (current; ADR-0007 sequencing) |
| [sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md](sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md) | SC-M03 work packages 3–4 detail (ADR-0008 authorization) |
| [sc-m04-telegram-optional-acceptance-plan-v1.md](sc-m04-telegram-optional-acceptance-plan-v1.md) | SC-M04 |
| [sc-m05-professional-widget-experience-plan-v1.md](sc-m05-professional-widget-experience-plan-v1.md) | SC-M05 |
| [sc-m06-support-availability-and-offline-tickets-plan-v1.md](sc-m06-support-availability-and-offline-tickets-plan-v1.md) | SC-M06 |
| [sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md](sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md) | SC-AI1 |
| [sc-ai2-controlled-direct-ai-responses-plan-v1.md](sc-ai2-controlled-direct-ai-responses-plan-v1.md) | SC-AI2 |
