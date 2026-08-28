# SC-M04 — Telegram-Optional Acceptance

## Status

Planned

Depends on: SC-M03

## Objective

Prove Support Chat operates correctly with Universal Telegram **absent** or **unavailable** after cutover — production-supported modes, not degraded emergencies only.

## Product requirements

- **R1** — Works without Telegram.
- **R7** — Offline/human tickets do not depend on Telegram.

## Included scope

- Acceptance scenarios and automated/manual evidence for ADR-0006 modes.
- Regression of Hub reply and visitor poll with adapter deactivated or uninstalled.
- Channel-unavailable signalling without fatal errors.

## Explicit exclusions

- New visual features (SC-M05).
- Availability schedule product (SC-M06) beyond what SC-M02 already provides.
- AI milestones.

## Acceptance criteria

- Matrix cases for SC-alone, adapter absent, and adapter unavailable pass.
- No requirement to reinstall Telegram to resolve tickets.

## Frozen plan

[sc-m04-telegram-optional-acceptance-plan-v1.md](../plans/sc-m04-telegram-optional-acceptance-plan-v1.md)

## Post-freeze additions

- **ADR-0012 — automatic Support Chat → Telegram message dispatch.** Realises the
  SC-owned-delivery half of the Universal Telegram ADR-0044 end state: visitor messages and Hub
  operator replies are automatically mirrored to the linked Telegram forum topic through the
  existing signed Contract v1 path, backed by a Support-Chat-owned durable outbox; replies that
  arrive from Telegram are ingested but never mirrored back. Opt-in
  (`telegram_dispatch_enabled`, default off). Plan:
  [sc-telegram-adapter-dispatch-plan-v1.md](../plans/sc-telegram-adapter-dispatch-plan-v1.md).
