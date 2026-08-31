# Milestone Registry — Universal Support Chat

Status values: Not Started, Planned, In Progress, Implemented, Verifying, Closed (PASS / PASS WITH LIMITATIONS / FAIL / DEFERRED).

This registry reflects runtime progress after SC-M00/SC-M01.

| # | Milestone | Charter | Status | Depends on | R-trace |
|---|---|---|---|---|---|
| SC-M00 | Foundation | [sc-m00-foundation.md](sc-m00-foundation.md) | Closed (PASS) | none | — |
| SC-M01 | Conversation System of Record | [sc-m01-conversation-system-of-record.md](sc-m01-conversation-system-of-record.md) | Closed (PASS) | SC-M00 | R1 |
| SC-M02 | Widget and WordPress Hub Replies | [sc-m02-widget-and-hub-replies.md](sc-m02-widget-and-hub-replies.md) | Closed (PASS) | SC-M01 | R5 |
| UT Adapter M1 | Universal Telegram Support Chat Adapter | [ut-adapter-m1-universal-telegram-support-chat-adapter.md](ut-adapter-m1-universal-telegram-support-chat-adapter.md) | Planned (external repo) | Contract v1 (ADR-0005); SC-M01/M02 surfaces as needed | R1 |
| SC-M03 | Controlled Migration and Cutover | [sc-m03-controlled-migration-and-cutover.md](sc-m03-controlled-migration-and-cutover.md) | Work package 0 (Contract server) implemented; migration/cutover blocked on UT signed client | SC-M02; UT Adapter M1; ADR-0007 | ADR-0004, ADR-0007 |
| SC-M04 | Telegram-Optional Acceptance | [sc-m04-telegram-optional-acceptance.md](sc-m04-telegram-optional-acceptance.md) | Planned | SC-M03 | R1, R7 |
| SC-M05 | Professional Widget Experience | [sc-m05-professional-widget-experience.md](sc-m05-professional-widget-experience.md) | Closed (PASS WITH LIMITATIONS) — [closure](../closure/sc-m05-professional-widget-experience-closure.md); merged `ceb5284` / closed `b3bc9d9`. **DEV deployed & PO-accepted** ([record](../closure/sc-m05-dev-deployment-acceptance.md); DEV = `main` @ `b3bc9d9`, v`0.7.0`, `db_version` 12). Production untouched; post-merge human AT (VoiceOver/NVDA) validation still recommended | SC-M02 | R2, R3 |
| SC-M06 | Support Availability and Offline Tickets | [sc-m06-support-availability-and-offline-tickets.md](sc-m06-support-availability-and-offline-tickets.md) | Closed (PASS WITH LIMITATIONS) — [closure](../closure/sc-m06-support-availability-and-offline-tickets-closure.md); merged `f3b327b` (PR #53; freeze `cdfcd5a` / PR #51, PO acceptance `e7518bb` / PR #52; ADR-0017 Accepted). **DEV deployed** ([record](../closure/sc-m06-dev-deployment-record.md); DEV = `main` @ `4cdd213`, v`0.8.0`, `db_version` 12; technical health checks passed). Accepted limitations: SC-M05-standard browser QA and a VoiceOver/NVDA offline-widget smoke, both recommended post-merge. Plan v2 §18 functional DEV acceptance & PO functional sign-off still outstanding; production untouched | SC-M02; soft SC-M04 | R5, R7 |
| SC-M07 | AI-First Visitor Support | [sc-m07-ai-first-visitor-support.md](sc-m07-ai-first-visitor-support.md) | Planned — [ADR-0018](../adr/0018-ai-first-visitor-support.md) **Proposed** in the SC-M07 documentation freeze; not authorized for implementation until a separate Product Owner acceptance record merges | SC-M02; SC-M06 / ADR-0017 | R1, R4, R6 |
| SC-AI1 | Operator AI Drafts and Approve-and-Send | [sc-ai1-operator-ai-drafts-approve-and-send.md](sc-ai1-operator-ai-drafts-approve-and-send.md) | **Superseded by SC-M07** (charter retained, immutable) | SC-M02; before SC-AI2 | Safety |
| SC-AI2 | Controlled Direct AI Responses | [sc-ai2-controlled-direct-ai-responses.md](sc-ai2-controlled-direct-ai-responses.md) | **Superseded by SC-M07** (charter retained, immutable) | SC-AI1; SC-M06 recommended | R4, R6 |
| SC-AI3 | AI-Assisted Support / RAG Knowledge Base | [sc-ai3-ai-assisted-support-and-rag.md](sc-ai3-ai-assisted-support-and-rag.md) | Future / not implemented (deferred — no ADR, plan, or PO approval) | SC-AI1; SC-AI2; own ADR + plan + PO approval | R4, R6 |

## Locked execution order

1. SC-M00 → SC-M01 → SC-M02  
2. UT Adapter M1 (Universal Telegram repository)  
2a. ADR-0007 authenticated Contract server (Support Chat) + Universal Telegram signed-client follow-up slice (external repo) + end-to-end authenticated interoperability proof  
3. SC-M03 → SC-M04  
4. SC-M05, SC-M06, then **SC-M07** (AI-first visitor support, [ADR-0018](../adr/0018-ai-first-visitor-support.md)) — SC-M07 **supersedes SC-AI1 and SC-AI2**  

**SC-M07 replaces the SC-AI1-then-SC-AI2 sequence**: ADR-0018 makes AI the first responder with human escalation always available and operator override authoritative, so an operator-draft co-pilot is no longer a prerequisite. SC-AI3 (genuine vector / RAG knowledge base) is a deferred future note only — not scheduled, not authorized; it needs its own ADR, plan, and Product Owner approval.
