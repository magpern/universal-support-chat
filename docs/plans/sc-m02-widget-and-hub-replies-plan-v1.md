# SC-M02 Widget and Hub Replies — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-m02-widget-and-hub-replies.md`
- ADRs: ADR-0002, ADR-0003, ADR-0006

## 2. Findings

SC-M01 provides SoR/REST. Hub reply path does not exist in legacy UT as first-class visitor delivery from WP admin in the extracted design — this milestone establishes it in Support Chat.

## 3. Assumptions

- Functional widget only; SC-M05 owns professional launcher/greeting.
- Hub is a Support Chat admin surface (menu naming in implementation).

## 4. Decisions

- Hub → visitor reply is first-class and must not call Telegram.
- Optional future `deliver_message` only when escalated and adapter present (not required for Hub success).
- R5/R7: operators manage tickets in Hub without `/support`.

## 5. Impact

- ChatWidget assets (baseline).
- Administration Hub inbox/detail/reply/notes/assignment UX.
- Polling/realtime-enough visitor fetch of operator messages.

## 6. Security and privacy

Capability-gated Hub mutations; CSRF/nonces; visitor never sees operator-only fields.

## 7. Test and CI

Hub reply visible to visitor with Telegram inactive; capability negative tests; widget auth gate tests.

## 8. Work packages

1. Widget baseline enqueue + UI  
2. Hub list/detail  
3. Reply + notes + assignment  
4. Tests for telegram-absent path  

## 9. Risks

- Accidental channel send on Hub reply — gate behind escalation+adapter and default off.

## 10. Out of scope

R2/R3 polish, availability schedule, AI, migration, adapter implementation.

## 11. Definition of done

Charter acceptance; Hub reply works with Universal Telegram inactive.
