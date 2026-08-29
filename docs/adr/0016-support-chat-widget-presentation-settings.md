# ADR-0016 — Support Chat widget presentation settings

## Status

**Proposed** — 2026-08-30. Introduced for [SC-M05 — Professional Widget Experience](../milestones/sc-m05-professional-widget-experience.md)
and its plan [sc-m05-professional-widget-experience-plan-v2.md](../plans/sc-m05-professional-widget-experience-plan-v2.md).
Following the ADR-0015 sequence, this ADR is merged **Proposed** in the SC-M05 documentation
freeze; a later, separate Product Owner acceptance record
(`docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`) will change only this
Status line to **Accepted**, and implementation begins only after that acceptance merges.

## Context

SC-M05 R3 requires operator-configurable widget presentation: a support **title**, an opening
**greeting**, and an optional identity **avatar**. Support Chat has never before rendered
operator-authored free text to a visitor's browser — every string in the current widget
(`src/ChatWidget/WidgetAssets.php`, `assets/js/chat-widget.js`) is a compile-time constant
passed through `esc_html__()` or a `wp_localize_script` i18n array, and all dynamic message
text is rendered via `.textContent`, never `innerHTML`.

[ADR-0002](0002-plugin-identity-and-ownership-boundaries.md) places the website chat widget,
its greeting, and support-domain identity inside Support Chat's ownership (adapters own bot
identity, not this). [ADR-0003](0003-security-privacy-and-visitor-isolation.md) fixes visitor
isolation and classification but says nothing about sanitising operator-authored content shown
to visitors. [ADR-0015](0015-operator-settings-page-and-diagnostics-separation.md) established
`UniversalSupportChat\Core\Configuration\Settings` as the sole owner of the single option array
`universal_support_chat_settings` and a real operator Settings page; its own "no new option /
no default change" fence is scoped to ADR-0015 and does not bind later milestones.

No existing ADR defines how operator-authored, visitor-rendered presentation text is sanitised
and escaped, or how an operator identity image is referenced. SC-M06 (offline-ticket
confirmation copy) and SC-AI1/SC-AI2 (AI-drafted content) will build on or deliberately deviate
from that boundary, so it is recorded here rather than left as an implementation detail.

## Decision

1. **Three additive keys** are added to the existing `universal_support_chat_settings` option
   array (owned by `Settings`), taking it from six keys to nine. No new option is created.

   | Key | Type | Default | Input sanitisation |
   |---|---|---|---|
   | `widget_title` | string | `''` (empty — resolves to the translated `Support chat` at render) | `sanitize_text_field()`, then truncate to **80 characters** |
   | `widget_greeting` | string (multiline) | translated `Hi — how can we help?` | `sanitize_textarea_field()` (strips tags, preserves newlines), then truncate to **500 characters** |
   | `widget_avatar_attachment_id` | int | `0` (no avatar) | `absint()`, then require `wp_attachment_is_image()` — any non-image or unknown id becomes `0` |

2. **Operator presentation text is plain text only.** `widget_title` and `widget_greeting` are
   tag-stripped on input (per the sanitisers above) and are **never accepted, stored, or
   rendered as HTML or Markdown**. On output: the title is rendered server-side with
   `esc_html()` inside the panel's `<h2>`; the greeting is delivered to the widget script as a
   raw string and rendered with `.textContent` (newlines preserved by CSS `white-space:
   pre-wrap`). No widget code path uses `innerHTML`. Any future rich (HTML/Markdown)
   presentation content requires a new ADR.

3. **The avatar is referenced by WordPress Media Library attachment id.** The stored value is
   an integer; `0` means "no avatar". Validity (the id resolves to an image attachment) is
   re-checked server-side by `Settings::sanitize()` regardless of how the value was submitted.
   The image URL is resolved server-side at render (`wp_get_attachment_image_url()`); the
   `<img>` element is emitted only when a valid URL is available and carries `alt=""`
   (decorative — the `<h2>` conveys identity).

4. **No schema, API, capability, Telegram, or AI change.** `universal_support_chat_db_version`
   stays at `12`; `Migrator::target_version()` is untouched. No REST route, field, or
   permission changes. No new capability — the existing `CapabilityRegistrar::MANAGE` gates who
   may set these values, through the existing ADR-0015 Settings page and its
   `option_page_capability_universal_support_chat_settings_group` filter. No Universal Telegram
   dependency and no adapter/bot identity is surfaced. No AI, provider, prompt, or automation.

## Alternatives

- **Allow limited HTML in the greeting via `wp_kses`.** Rejected: introduces an XSS surface and
  an allow-list policy to maintain, for no product need — a support greeting is a sentence.
- **Ship a bundled set of predefined avatar icons.** Rejected: adds binary assets to a
  repository that has none, plus per-icon licensing/attribution, and still needs a picker UI.
- **A dedicated `usc_widget_presentation` option separate from `universal_support_chat_settings`.**
  Rejected: the existing single fixed-shape option already models operator configuration
  (ADR-0015); a second option fragments ownership for no benefit.
- **Defer the avatar to a later milestone.** Rejected: R3 explicitly lists "title/avatar".
- **Plan-only, no ADR.** Rejected: the plain-text-only rule for operator-authored,
  visitor-rendered content is a durable security boundary later milestones must see, and the
  attachment-id identity model is a data-model decision worth pinning.

## Consequences

- Existing and upgraded sites gain the default greeting and the resolved default title
  automatically on the next front-end render (absent keys resolve to defaults through
  `Settings::sanitize()`); the default greeting makes no availability or response-time claim.
- Operators get title/greeting/avatar control on the existing Support Chat Settings page; no
  new menu, page, or capability.
- `Settings::defaults()` and `Settings::sanitize()` grow to nine keys, and the three
  `Settings` array-shape docblocks are updated (PHPStan level 5). `sanitize()` remains
  fixed-shape — unknown keys are still dropped.
- SC-M06 and SC-AI1/SC-AI2 inherit the "operator-authored visitor-facing text is plain text,
  tag-stripped in, escaped/`.textContent` out, never HTML" precedent.

## Security and privacy impact

- Operator-authored text cannot inject markup or script into the visitor page: tag-stripping on
  input plus `esc_html()` (title) / `.textContent` (greeting) on output, and no `innerHTML`
  anywhere in the widget script (enforced by an existing static test).
- The avatar exposes only an already-public uploaded media URL; the stored value is an integer
  id, server-validated as an image attachment.
- Visitor isolation and the authenticated-only visitor REST boundary
  ([ADR-0003](0003-security-privacy-and-visitor-isolation.md)) are untouched — no route, field,
  or permission changes.
- Only a user with `universal_support_chat_manage` can set these values, through the existing
  Settings API save path and its option-page capability filter.
- No new logging, no new retained data beyond the option (already covered by the uninstall
  data-removal setting). No visitor PII is introduced.

## Affected Documents/Milestones

- [SC-M05 — Professional Widget Experience](../milestones/sc-m05-professional-widget-experience.md)
  (charter — pointer updated to plan v2 and this ADR; milestone scope unchanged).
- [sc-m05-professional-widget-experience-plan-v2.md](../plans/sc-m05-professional-widget-experience-plan-v2.md)
  (realises this ADR).
- Extends [ADR-0002](0002-plugin-identity-and-ownership-boundaries.md) (support-domain identity
  ownership) and complements [ADR-0015](0015-operator-settings-page-and-diagnostics-separation.md)
  (the Settings page these fields are added to).
- Forward-referenced by SC-M06 and SC-AI1/SC-AI2 for the plain-text-only precedent.
- `docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md` (later; records acceptance
  and flips this Status to Accepted).

## Compatibility/Migration Impact

- No migration. `universal_support_chat_db_version` and `Migrator::target_version()` stay at
  `12`. No table is created, altered, dropped, or reinterpreted.
- Additive option keys; an option array that lacks them resolves to the documented defaults
  through `Settings::sanitize()`, so existing sites converge with no upgrade step.
- Uninstall behaviour is unchanged — the keys live inside the option already removed by the
  existing `remove_data_on_uninstall` path.
- Fully backward compatible with Universal Telegram absent, disabled, or unavailable — the
  widget presentation has no adapter dependency.
