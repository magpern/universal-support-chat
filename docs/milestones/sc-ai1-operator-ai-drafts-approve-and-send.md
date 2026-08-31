# SC-AI1 — Operator AI Drafts and Approve-and-Send

## Status

**Superseded by [SC-M07 — AI-First Visitor Support](sc-m07-ai-first-visitor-support.md)**
([ADR-0018](../adr/0018-ai-first-visitor-support.md), Proposed in the SC-M07 documentation
freeze). SC-M07 makes AI the first responder with human escalation always available and
operator override authoritative, so this operator-draft co-pilot is no longer a prerequisite
and has no committed slot. This charter is retained unchanged as immutable history; the
scope and acceptance criteria below are not implemented.

Original status: Planned

Depends on: SC-M02; **must precede SC-AI2**

## Objective

Operator AI drafts with explicit **Approve and send to chat** delivering as visitor-facing **Support team**. No autonomous send.

## Product requirements

- Preserves the safety boundary before R4/R6 autonomy.
- Rehomes the approved M09.1 intent from the legacy Universal Telegram roadmap as a Support Chat milestone (historical UT M09 remains delivered history elsewhere; this is a new SC milestone with its own plan).

## Included scope

- Operator draft generation against approved sources (details in plan/ADRs at AI freeze time).
- Version-bound approve-and-send into Support Chat transcript as *Support team*.
- Optional Contract `deliver_message` only if conversation already escalated.
- Migration of eligible legacy draft/config data from Universal Telegram historical tables under a dedicated AI migration plan (not SC-M03).

## Explicit exclusions

- Direct visitor-facing AI replies (SC-AI2).
- Visitor checkbox as enablement (R4 forbids for SC-AI2; SC-AI1 is operator-only).
- Write-capable tools.

## Acceptance criteria

- Approve-and-send requires explicit operator action.
- Attribution is Support team, not AI assistant.
- No send without approval.

## Frozen plan

[sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md](../plans/sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md)

Note: Additional AI-specific ADRs may be required before implementation; this foundation freeze records the milestone boundary only.
