# SC-M05 — Professional Widget Experience

## Status

Closed (PASS WITH LIMITATIONS) — implementation [PR #48](https://github.com/magpern/universal-support-chat/pull/48)
squash-merged to `main` at `ceb5284fe51c1f37a52895b4f43ed422376ef902`; closure
[`docs/closure/sc-m05-professional-widget-experience-closure.md`](../closure/sc-m05-professional-widget-experience-closure.md).
Merged, not deployed. Limitation: the plan §9 VoiceOver/NVDA screen-reader smoke was not run
in this environment and is **not** claimed as passed — it is a post-merge recommended human
AT validation (checklist:
<https://github.com/magpern/universal-support-chat/pull/48#issuecomment-5469273912>).

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

[sc-m05-professional-widget-experience-plan-v2.md](../plans/sc-m05-professional-widget-experience-plan-v2.md)
— the implementation plan, realising
[ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md).
It supersedes the original product-boundary freeze
[sc-m05-professional-widget-experience-plan-v1.md](../plans/sc-m05-professional-widget-experience-plan-v1.md)
(retained unedited).

ADR-0016 is merged **Proposed** in the SC-M05 documentation freeze; a separate Product Owner
acceptance record later changes only its Status to **Accepted**, and implementation begins only
after that acceptance merges (the ADR-0015 sequence). This milestone's scope, requirements
(R2/R3), and acceptance criteria are unchanged.

## Planning note (ADR-0015)

[ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) (Accepted;
**implemented** — PR #44 merged to `main` `b56ea23`, closure
`docs/closure/sc-adr-0015-operator-settings-diagnostics-implementation-closure.md`, not
deployed) and its plan
[sc-operator-settings-and-diagnostics-plan-v1.md](../plans/sc-operator-settings-and-diagnostics-plan-v1.md)
add a real operator-facing **Support Chat Settings** page for configuration the plugin
already owns (widget enable/disable, Telegram mirror enable/disable, conversation retention
periods, uninstall data-removal). That work adds **no** greeting/title/avatar/launcher
configuration — R3's presentation settings remain SC-M05 scope — but SC-M05's "greeting
configuration in Hub/settings" now has an established Settings-page home to extend rather
than create.
