# ADR-0006 — Optional Channel and Adapter Failure Model

## Status

Accepted

## Context

Support Chat must satisfy R1 and R7: full operation without Telegram, and durable tickets that never depend on a channel. Adapters will fail, be deactivated, or mismatch Contract versions. Failure behaviour must be fail-closed for the channel without breaking Hub or website chat.

## Decision

### Install modes

1. **Support Chat alone** — supported production mode; Hub and widget fully functional.
2. **Support Chat + compatible adapter** — optional escalation/notify/delivery for escalated traffic only.
3. **Adapter alone** — no website support chat from this plugin (Support Chat not installed).

### Discovery

- Versioned, capability-based handshake per ADR-0005.
- Incompatible or absent adapter ⇒ Support Chat disables channel features and continues.

### Failure behaviour

| Event | Behaviour |
|---|---|
| Adapter not installed | No channel calls; tickets and Hub replies work |
| Adapter deactivated mid-conversation | Hub continues; channel marked unavailable via local state / last callback; no fatal errors |
| Adapter process/API failure | `report_channel_unavailable` / `report_delivery_failure` as applicable; Hub remains authoritative |
| Contract version mismatch | Channel features disabled; Hub/widget continue |

### Product rules reinforced

- Offline human request always creates a Support Chat ticket with truthful wording (R7).
- Telegram may notify if connected; notification failure must not prevent ticket creation.
- Ordinary AI-only chat never opens a channel case (R1; enforced when SC-AI2 exists).

## Alternatives

- Hard-depend on Telegram for waiting tickets — rejected (R7).
- Fail the entire chat stack when the adapter errors — rejected: violates standalone operation.

## Consequences

- SC-M04 is the acceptance milestone proving telegram-optional behaviour after cutover.
- UT Adapter M1 must honour unavailable/failure callbacks.

## Security and privacy impact

Fail-closed channel degradation avoids leaking partial binding state to visitors.

## Affected Documents/Milestones

SC-M02, SC-M04, SC-M06, UT Adapter M1; ADR-0005.

## Compatibility/Migration Impact

None for this freeze; behavioural acceptance in SC-M04.
