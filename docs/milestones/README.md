# Milestone Registry — Universal Support Chat

Status values: Not Started, Planned, In Progress, Implemented, Verifying, Closed (PASS / PASS WITH LIMITATIONS / FAIL / DEFERRED).

This registry reflects runtime progress after SC-M00/SC-M01.

| # | Milestone | Charter | Status | Depends on | R-trace |
|---|---|---|---|---|---|
| SC-M00 | Foundation | [sc-m00-foundation.md](sc-m00-foundation.md) | Closed (PASS) | none | — |
| SC-M01 | Conversation System of Record | [sc-m01-conversation-system-of-record.md](sc-m01-conversation-system-of-record.md) | Closed (PASS) | SC-M00 | R1 |
| SC-M02 | Widget and WordPress Hub Replies | [sc-m02-widget-and-hub-replies.md](sc-m02-widget-and-hub-replies.md) | Closed (PASS) | SC-M01 | R5 |
| UT Adapter M1 | Universal Telegram Support Chat Adapter | [ut-adapter-m1-universal-telegram-support-chat-adapter.md](ut-adapter-m1-universal-telegram-support-chat-adapter.md) | Planned (external repo) | Contract v1 (ADR-0005); SC-M01/M02 surfaces as needed | R1 |
| SC-M03 | Controlled Migration and Cutover | [sc-m03-controlled-migration-and-cutover.md](sc-m03-controlled-migration-and-cutover.md) | Planned | SC-M02; UT Adapter M1 | ADR-0004 |
| SC-M04 | Telegram-Optional Acceptance | [sc-m04-telegram-optional-acceptance.md](sc-m04-telegram-optional-acceptance.md) | Planned | SC-M03 | R1, R7 |
| SC-M05 | Professional Widget Experience | [sc-m05-professional-widget-experience.md](sc-m05-professional-widget-experience.md) | Planned | SC-M02 | R2, R3 |
| SC-M06 | Support Availability and Offline Tickets | [sc-m06-support-availability-and-offline-tickets.md](sc-m06-support-availability-and-offline-tickets.md) | Planned | SC-M02; soft SC-M04 | R5, R7 |
| SC-AI1 | Operator AI Drafts and Approve-and-Send | [sc-ai1-operator-ai-drafts-approve-and-send.md](sc-ai1-operator-ai-drafts-approve-and-send.md) | Planned | SC-M02; before SC-AI2 | Safety |
| SC-AI2 | Controlled Direct AI Responses | [sc-ai2-controlled-direct-ai-responses.md](sc-ai2-controlled-direct-ai-responses.md) | Planned | SC-AI1; SC-M06 recommended | R4, R6 |

## Locked execution order

1. SC-M00 → SC-M01 → SC-M02  
2. UT Adapter M1 (Universal Telegram repository)  
3. SC-M03 → SC-M04  
4. SC-M05, SC-M06, SC-AI1, then SC-AI2  

**SC-AI1 precedes SC-AI2.**
