# SC-M01 Conversation System of Record — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-m01-conversation-system-of-record.md`
- ADRs: ADR-0002, ADR-0003, ADR-0005 (contract authority surfaces may be inert stubs), ADR-0006

## 2. Repository findings

No conversation schema yet (post-SC-M00). Legacy chat remains in Universal Telegram until SC-M03.

## 3. Assumptions

- Authenticated visitor ownership (exact login gate details align with product decision at implementation; isolation principle fixed in ADR-0003).
- REST namespace under Support Chat (e.g. `universal-support-chat/v1`).

## 4. Decisions

- Support Chat tables own conversations/messages; no Telegram columns.
- No dual-write to Universal Telegram.
- No AI acknowledgement checkbox feature.
- R1: SoR works with adapter absent.

## 5. Impact

- Schema: conversations, messages (+ notes/assignment columns as needed).
- Visitor REST: start, mine, post message, poll/get.
- Retention job Support Chat–owned.

## 6. Security and privacy

Encrypted bodies; ownership checks on every read/write; no channel IDs stored.

## 7. Test and CI

Ownership/IDOR tests; REST capability tests; retention dry-run tests; WordPress-only config.

## 8. Work packages

1. Schema migrations  
2. Repositories/services  
3. Visitor REST  
4. Retention  
5. Optional Contract discovery stub (no Telegram calls)  
6. Tests  

## 9. Risks

- Premature channel coupling — forbid Telegram imports in Conversations boundary tests.

## 10. Out of scope

Widget/Hub UI, adapter, migration, AI, R2/R3 polish, availability.

## 11. Definition of done

Charter acceptance; R1 SoR-without-Telegram proven in tests.
