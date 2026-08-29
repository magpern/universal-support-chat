# SC-M05 — Professional Widget Experience

## Status

Planned

Depends on: SC-M02

## Objective

Deliver professional launcher and greeting presentation.

## Product requirements

- **R2** — Circular launcher; chat icon closed; X open; subtle morph; reduced-motion support.
- **R3** — Configurable Hello/greeting, title/avatar, professional presentation.

## Included scope

- Launcher morph animation with `prefers-reduced-motion` fallback.
- Header title/avatar and greeting configuration in Hub/settings.
- Visual polish without requiring Telegram or AI.

## Explicit exclusions

- Availability chrome that claims live/offline unless SC-M06 has shipped the authoritative status model (coordinate in plan).
- AI surfaces.

## Acceptance criteria

- R2 and R3 observable on desktop and mobile viewports per frozen plan checklist.
- Works with Universal Telegram inactive.

## Frozen plan

[sc-m05-professional-widget-experience-plan-v1.md](../plans/sc-m05-professional-widget-experience-plan-v1.md)

## Planning note (ADR-0015)

[ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) (Accepted) and
its plan [sc-operator-settings-and-diagnostics-plan-v1.md](../plans/sc-operator-settings-and-diagnostics-plan-v1.md)
add a real operator-facing **Support Chat Settings** page for configuration the plugin
already owns (widget enable/disable, Telegram mirror enable/disable, conversation retention
periods, uninstall data-removal). That work adds **no** greeting/title/avatar/launcher
configuration — R3's presentation settings remain SC-M05 scope — but SC-M05's "greeting
configuration in Hub/settings" now has an established Settings-page home to extend rather
than create.
