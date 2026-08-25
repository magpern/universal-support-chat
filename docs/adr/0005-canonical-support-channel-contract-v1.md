# ADR-0005 — Canonical Support Channel Contract v1

## Status

Accepted

## Context

Universal Support Chat owns website support conversations and must work without any channel adapter. Optional adapters (starting with Universal Telegram) may escalate selected conversations to an external operator channel. Without a precise, versioned cross-plugin contract, adapters would invent private table access, diverge on plaintext handling, or make ticket creation depend on the channel.

This ADR is the **canonical Contract v1** specification. Consuming plugins must pin to this document’s immutable git commit SHA and canonical URL in this repository. A later Support Chat release tag may reference the same commit for packaging; a tag is not required for consumers to pin Contract v1.

## Decision

### 1. Ownership

| Concern | Owner |
|---|---|
| Support conversations, messages, tickets, visitor identity, waiting queue, assignment, notes, audit, Hub workflow, support-domain state | **Support Chat** |
| Channel-specific identities, credentials, topics/threads, remote message IDs, remote delivery queues, remote retry state | **Channel adapter** |

Rules:

- Support Chat must **never** store Telegram tokens, Telegram topic IDs, or other Telegram-specific persistent state (and the same rule applies to any future channel’s native IDs/credentials).
- No plugin may read or write the other plugin’s database tables directly.
- Support Chat remains the authority for all support-domain mutation and audit.
- The adapter validates channel/operator identity before calling Support Chat.

### 2. Adapter capability and failure model

- Support Chat **must operate** when no adapter is installed (R1, R7).
- Contract discovery is **versioned** and **capability-based** (handshake advertises Contract v1 and supported operations).
- Contract mismatch, adapter deactivation, or adapter failure **fails closed for that channel only**; Hub and website chat continue (see also ADR-0006).
- Visitors never receive channel binding references, remote IDs, credentials, or internal operator data.

### 3. Opaque channel-case reference

When an escalated channel case is opened or ensured, the adapter returns an opaque `channel_case_ref` (UUID or equivalent). Support Chat stores only this opaque reference plus delivery/availability status derived from adapter callbacks. Support Chat must not parse channel-native structure out of the reference.

### 4. Support Chat → adapter operations

All outbound calls are authorised by Support Chat capability checks. Plaintext exists **only in memory** for the duration of the call. The adapter formats, encrypts/queues, sends, retries, and records remote delivery state.

#### 4.1 `ensure_channel_case`

**Purpose:** Open or reuse an escalated channel case for a support conversation that has entered human/escalated support.

**Inputs (logical):** `conversation_uuid`, escalation reason/code, optional operator-facing summary metadata (non-secret), idempotency key.

**Outputs:** `channel_case_ref` (opaque), status (`created` | `reused` | `unavailable`).

**Semantics:** Invoked only for escalated / support-channel traffic — **never** for ordinary AI-only chat (R1). Idempotent on the idempotency key and conversation identity: repeated ensure returns the same `channel_case_ref` when the case still exists.

#### 4.2 `notify_operators`

**Purpose:** Notify operators that attention is required (new escalation, waiting ticket, etc.) without necessarily sending full transcript bodies.

**Inputs:** `channel_case_ref` or conversation identity previously bound, notification kind, bounded non-secret summary, idempotency key.

**Semantics:** Best-effort channel notify; failure reports via `report_delivery_failure` / `report_channel_unavailable` without failing Hub ticket creation.

#### 4.3 `deliver_transcript_backfill`

**Purpose:** Deliver eligible prior transcript messages when a channel case is created or catch-up is required.

**Responsibility split:**

| Party | Responsibility |
|---|---|
| **Support Chat** | Authorises the backfill; selects **eligible** messages; **exports** ordered plaintext payloads in bounded pages/batches through a narrow channel-delivery call |
| **Adapter** | Formats and sends remote messages; **owns delivery retry state** and outbound queue records |

**Eligibility (must exclude):** internal notes; audits; secrets; credentials; non-visitor-facing content; any field classified private/internal under ADR-0003.

**Inputs:** `channel_case_ref`, ordered page of eligible message payloads (plaintext in memory), page cursor, idempotency key per message.

**Semantics:** Support Chat does not leave transcript snapshots as an ambiguous opaque content `ref` without an export/send contract. Adapters must not invent transcript content.

#### 4.4 `deliver_message`

**Purpose:** Deliver a subsequent Support Chat message (visitor or operator Hub-originated, or later AI-attributed per policy) to an **already escalated** channel case.

**Inputs:** `channel_case_ref`, message identity, plaintext body (in memory), attribution label appropriate for the channel, idempotency key.

**Semantics:** Only for conversations that already have an escalated channel case. Ordinary AI-only turns must not invoke this operation (R1). Adapter owns queue/retry after accept.

### 5. Adapter → Support Chat operations

All calls are authenticated, capability-checked, and executed by Support Chat as domain mutations. The adapter must validate channel/operator identity before invoking.

| Operation | Purpose |
|---|---|
| `ingest_operator_reply` | Ingest an operator reply from the channel into the conversation transcript |
| `claim` | Claim assignment for an operator |
| `release` | Release claim |
| `resolve` | Resolve the conversation/ticket |
| `reopen` | Reopen a resolved conversation |
| `update_assignment` | Assign or reassign an operator |
| `update_operator_presence` | Update operator presence signals that affect Hub (if exposed) |
| `report_channel_unavailable` | Channel/topic/bot unavailable; Support Chat marks channel degraded; Hub remains authoritative |
| `report_delivery_failure` | Outbound delivery failed after the adapter accepted the send |

Support Chat rejects unauthenticated or capability-insufficient calls. Telegram `/support`-style commands are adapter-side UX only; they map to these operations when the adapter is active (R5).

### 6. Idempotency

Durable idempotency boundaries:

| Boundary | Rule |
|---|---|
| Opening a channel case (`ensure_channel_case`) | Same idempotency key / conversation identity → same `channel_case_ref`; no duplicate remote topics for one logical ensure |
| Transcript backfill | Per-message idempotency key; retries must not duplicate visitor-visible remote messages |
| Adapter outbound delivery (`deliver_message` / backfill sends) | Adapter queue deduplicates on accept key; Support Chat may re-call with same key after uncertain failure |
| Adapter inbound operator reply (`ingest_operator_reply`) | Deduplicate on remote update identity (or adapter-supplied idempotency key); duplicate remote updates must not duplicate transcript messages |
| Lifecycle actions (`claim`, `release`, `resolve`, `reopen`, `update_assignment`) | Idempotent or safely no-op when target state already matches; concurrent conflicting actions use Support Chat concurrency rules |
| Duplicate remote updates / retry recovery | Adapter and Support Chat both treat retries as at-most-once for visitor-visible transcript effects |

### 7. Versioning and discovery

- Contract version id: **`support-channel-contract/v1`**.
- Canonical document: this ADR path in the Support Chat repository.
- Consumers pin **immutable commit SHA** + canonical raw/blob URL of this file (or docs tree containing it).
- Handshake: adapter advertises compatible contract version; Support Chat enables channel features only when compatible.

### 8. Non-goals of Contract v1

- Dual-write of chat SoR between plugins.
- Support Chat storing channel-native IDs or tokens.
- Requiring an adapter for ticket creation, Hub reply, resolve, or visitor chat (R7).
- Autonomous AI send semantics (SC-AI2); Approve-and-send (SC-AI1) uses Support Chat Hub delivery plus optional `deliver_message` only if already escalated.

## Alternatives

- Share database tables across plugins — rejected: couples installs, breaks isolation, violates ownership.
- Dual-write during coexistence — rejected: diverging histories and duplicate sends (see ADR-0004).
- Ambiguous transcript `ref` without export contract — rejected: unclear plaintext custody and retry ownership.
- Pin consumers to a future Support Chat release tag before the repo exists — rejected: circular dependency; pin commit SHA instead.

## Consequences

- Universal Telegram (and future adapters) implement against this ADR only, via their own adapter ADR that cites this commit SHA and URL.
- Support Chat implements the server/authority side in milestones SC-M01+ and exposes discovery/delivery in time for UT Adapter M1.
- Product requirements R1 and R7 are enforceable at the contract boundary.

## Security and privacy impact

- Plaintext only in memory on authorised delivery/backfill paths.
- Excludes notes, audits, secrets, credentials, and non-visitor-facing content from export.
- Fail-closed channel degradation preserves Hub confidentiality boundaries.
- Visitors never see binding refs or remote IDs.

## Affected Documents/Milestones

- `docs/ARCHITECTURE.md`, `docs/master-plan.md`
- UT Adapter M1 (Universal Telegram repository), SC-M03 migration bindings, SC-M04 telegram-optional acceptance
- ADR-0002, ADR-0003, ADR-0004, ADR-0006

## Compatibility/Migration Impact

- No runtime code in this documentation freeze.
- Legacy Universal Telegram chat SoR migration (SC-M03) creates adapter bindings only after UT Adapter M1 exists; Support Chat stores opaque `channel_case_ref` values only.
