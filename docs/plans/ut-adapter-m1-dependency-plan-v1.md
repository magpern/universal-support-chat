# UT Adapter M1 — Dependency Record v1 (Support Chat repository)

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-telegram-support-chat-adapter.md`
- Canonical contract: `docs/adr/0005-canonical-support-channel-contract-v1.md`
- Failure model: ADR-0006

## 2. Purpose of this document

This is **not** an implementation plan for code in `universal-support-chat`. It freezes the cross-repo dependency and ordering so SC-M03 cannot start before the adapter binding table exists.

## 3. Decisions

- Implementation and detailed UT plan/ADR live in the Universal Telegram repository.
- UT adapter ADR must pin Support Chat Contract v1 by **immutable commit SHA** + canonical document URL (not a future SC release tag).
- Ordering: SC-M00–M02 → UT Adapter M1 → SC-M03 → SC-M04.

## 4. Support Chat obligations before/during Adapter M1

- Expose Contract v1 server operations and discovery as required for the adapter (may span late SC-M01/SC-M02 work packages).
- Never store Telegram-native IDs.

## 5. Out of scope here

Any PHP/Telegram code in this repository.

## 6. Definition of done (for this dependency record)

Documented ordering accepted; UT supersession/adapter freeze (next program task) cites Contract v1 SHA from Support Chat `main`.
