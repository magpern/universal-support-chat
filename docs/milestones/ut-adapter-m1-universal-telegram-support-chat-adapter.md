# UT Adapter M1 — Universal Telegram Support Chat Adapter

## Status

Planned (**implemented in the Universal Telegram repository**, not in this repository)

Depends on: Canonical Contract v1 ([ADR-0005](../adr/0005-canonical-support-channel-contract-v1.md)) merged on Support Chat `main`; Support Chat conversation/Hub surfaces as required for callbacks (SC-M01/SC-M02)

## Objective

Provide an optional Universal Telegram adapter that consumes Support Chat Contract v1: binding table, inbound operator replies/commands, outbound channel delivery and transcript backfill send/retry, and failure reporting.

## Product requirements

- **R1** — Telegram receives only escalated/support-channel traffic.
- **R5** — `/support` commands exist only when this adapter is active; Hub remains authoritative in Support Chat.

## Included scope (Universal Telegram repo)

- Adapter client compliant with ADR-0005.
- Binding table: Support Chat conversation ↔ Telegram destination/topic (Telegram-owned).
- Inbound: operator replies → `ingest_operator_reply`; lifecycle → claim/release/resolve/reopen/assignment/presence.
- Outbound: accept plaintext delivery/backfill; own encrypted queue and retry; `report_delivery_failure` / `report_channel_unavailable`.
- Adapter ADR in Universal Telegram pinning this repository’s Contract v1 **commit SHA** and canonical URL.

## Explicit exclusions

- Implementing adapter code in `universal-support-chat`.
- Dual-write of chat SoR.
- Storing Telegram IDs inside Support Chat tables.
- Migration cutover (SC-M03) — but Adapter M1 must exist **before** SC-M03 so bindings for existing topics can be created.

## Acceptance criteria

- Contract handshake and capability discovery succeed against pinned Contract v1.
- Idempotent ensure/backfill/deliver/ingest per ADR-0005.
- Deactivation fails closed for the channel only (ADR-0006).

## Charter ownership note

Detailed freeze/plan for UT Adapter M1 lives in the Universal Telegram repository after its supersession documentation step. This charter records the cross-repo dependency and ordering only.

## Related Support Chat plan note

See [ut-adapter-m1-dependency-plan-v1.md](../plans/ut-adapter-m1-dependency-plan-v1.md) (dependency record; not an implementation plan for this repo).
