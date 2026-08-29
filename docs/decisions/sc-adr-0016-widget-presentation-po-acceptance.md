# Product Owner Decision Record — ADR-0016 / SC-M05 Professional Widget Experience: implementation acceptance

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md)
and its companion plan
[`sc-m05-professional-widget-experience-plan-v2.md`](../plans/sc-m05-professional-widget-experience-plan-v2.md)
were frozen as documentation-only on `main` at commit
**`76c5113db456e2586436dab73f2138be4e93dff6`** (PR #46, "docs(sc-m05): freeze ADR-0016
(Proposed) + professional widget plan v2"). Plan v2 supersedes the original product-boundary
freeze `sc-m05-professional-widget-experience-plan-v1.md` (retained unedited).

Both the ADR and plan v2 state that ADR-0016 is merged **Proposed** in the freeze and that
implementation is authorized only from the merged freeze baseline, after a separate Product
Owner acceptance act recorded distinctly from the design freeze (per `docs/governance.md` —
"No role approves its own work product as final"). This record captures that act.

This record is documentation-only. It changes no architecture — ADR-0016 remains the
authoritative design — and it authorizes no work beyond the frozen scope of ADR-0016 and
plan v2.

## Decision

The Product Owner records the following acceptance verbatim:

> Product Owner acceptance — ADR-0016 / SC-M05 professional widget experience implementation
>
> I accept [ADR-0016](../adr/0016-support-chat-widget-presentation-settings.md) and
> [`docs/plans/sc-m05-professional-widget-experience-plan-v2.md`](../plans/sc-m05-professional-widget-experience-plan-v2.md)
> for implementation exactly as merged in the freeze at
> `76c5113db456e2586436dab73f2138be4e93dff6`, and exactly within their frozen scope.
>
> This authorizes implementation of the frozen SC-M05 plan v2 only:
>
> - a professional circular launcher with a CSS icon morph (speech bubble ↔ X) and full
>   `prefers-reduced-motion` support;
> - operator-configurable widget title, greeting, and an optional Media Library avatar
>   (`widget_title` ≤ 80 chars, `widget_greeting` ≤ 500 chars, both plain text only and never
>   rendered as HTML; `widget_avatar_attachment_id`, a server-validated image attachment id,
>   `0` = none), added to the existing `universal_support_chat_settings` option and surfaced on
>   the existing ADR-0015 Settings page;
> - default greeting `Hi — how can we help?`;
> - `#0b57d0` accent;
> - the widget dialog as a non-modal `role="dialog"` — no `aria-modal`, no Tab focus trap;
>   focus enters the panel on open and returns to the launcher on close/Escape;
> - the plugin version bump `0.6.0 → 0.7.0` (asset cache-bust).
>
> It does not authorize: any Telegram dependency or Universal Telegram change; any AI, RAG,
> provider, prompt, or automation; any availability, online/offline, or response-time claim;
> any REST route/field/permission change; any schema or `universal_support_chat_db_version`
> change (stays 12); any new capability; any new option beyond the three keys named above; any
> change to the frozen technical content of plan v2 or ADR-0016; any DEV or production
> deployment; any live setting change; any GitHub Release, tag, or data operation.
>
> Signed: Product Owner
> Date: 2026-08-30

## Scope authorized (for reference — the record above is authoritative)

Exactly the work packages frozen in
[plan v2 §10](../plans/sc-m05-professional-widget-experience-plan-v2.md) (WP1–WP8) and the data
model and rules in [ADR-0016 §Decision](../adr/0016-support-chat-widget-presentation-settings.md):

1. **WP1** — three additive keys in `universal_support_chat_settings` (`widget_title`,
   `widget_greeting`, `widget_avatar_attachment_id`) with the ADR-0016 sanitisation and length
   caps and server-side `wp_attachment_is_image()` validation; a `WidgetPresentation` value
   object. `Settings::sanitize()` stays fixed-shape (nine keys).
2. **WP2** — `WidgetAssets::render_shell()` restyle: circular launcher with two inline
   hand-authored SVG icons; server-rendered `<h2>` title and optional `<img class="usc-chat__avatar"
   alt="">`; a new `#usc-chat-intro` block; icon-only close button; `role="dialog"` with **no**
   `aria-modal`; `aria-describedby="usc-chat-intro"`; launcher `aria-haspopup="dialog"`.
   `enqueue()` adds only `greeting` and one `i18n.loading` string to the localized payload.
3. **WP3** — full `assets/css/chat-widget.css` rewrite: `.usc-chat` CSS custom-property palette
   with hardcoded fallbacks (`#0b57d0` accent); circular launcher; CSS icon morph; a
   `@media (prefers-reduced-motion: reduce)` block that disables all motion; a
   `@media (max-width: 480px)` full-screen panel that hides the intro once messages exist;
   RTL mirror.
4. **WP4** — `assets/js/chat-widget.js`: set `#usc-chat-intro` text via `.textContent` during
   init; move focus into the panel on open and back to the launcher on close/Escape (**no Tab
   trap**); a `loading` status string; a `data-has-messages` flag. No style writes, no
   `innerHTML`.
5. **WP5** — `SupportChatSettingsPage`: a new "Widget presentation" section with the three
   fields; an admin-only `assets/js/settings-media.js` `wp.media` image picker (image-only;
   "Remove" writes `0`), enqueued only on the Settings page hook with a `media-editor`
   dependency.
6. **WP6** — plugin version `0.6.0 → 0.7.0` (`Version:` header and
   `UNIVERSAL_SUPPORT_CHAT_VERSION`); changelog / `Stable tag` if the repo tracks them.
7. **WP7** — the plan v2 §9 manual QA checklist, executed and attached to the PR as evidence
   (desktop + mobile viewports; keyboard-only pass with no trap; VoiceOver + NVDA smoke;
   reduced motion; RTL; contrast; Lighthouse a11y ≥ 95; **Universal Telegram deactivated** —
   visitor create/send → Hub reply → widget poll, with no Telegram UI/identity/availability/error).
8. **WP8** — the implementation report and closure, citing the freeze SHA and this record's
   merge SHA.

## Not authorized

Per the acceptance text: no Telegram dependency or Universal Telegram change; no AI/RAG/
provider/prompt/automation; no availability or online/offline or response-time claim; no REST
route/field/permission change; no schema or `universal_support_chat_db_version` change (stays
`12`; `Migrator::target_version()` untouched); no new capability; no new option beyond the
three named keys; no change to the frozen technical content of plan v2 or ADR-0016; no DEV or
production deployment; no live setting change; no GitHub Release, tag, or data operation.

## Affected Documents/Milestones

- [ADR-0016](../adr/0016-support-chat-widget-presentation-settings.md) — Status moves
  `Proposed` → `Accepted` in the same commit as this record, referencing it.
- [plan v2](../plans/sc-m05-professional-widget-experience-plan-v2.md) — header gains a short
  "implementation authorized" note (frozen technical content unchanged).
- [`docs/decisions/README.md`](README.md) — index entry.
- [`docs/adr/README.md`](../adr/README.md) — ADR-0016 index status `Proposed` → `Accepted`.
- [SC-M05 charter](../milestones/sc-m05-professional-widget-experience.md) — already points to
  plan v2 and ADR-0016; milestone scope unchanged.

## Baseline

Implementation begins from `main` after this record merges. The implementation branch and PR
must cite:

- ADR-0016 / plan v2 freeze commit: `76c5113db456e2586436dab73f2138be4e93dff6` (PR #46).
- This acceptance record's merge commit (to be filled in the implementation PR).
