# SC-M05 Professional Widget Experience — Implementation Plan v2

Realises [ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md)
for [SC-M05](../milestones/sc-m05-professional-widget-experience.md). **Supersedes**
[sc-m05-professional-widget-experience-plan-v1.md](sc-m05-professional-widget-experience-plan-v1.md)
(a 27-line product-boundary freeze), which is retained unedited per
`docs/plans/README.md`. Frozen code-free against `origin/main` @
`cf558d132c7a8886bc66261a7eca3d9e3a4b4f7e`.

**Acceptance sequence (mirrors ADR-0015).** This documentation freeze merges ADR-0016 as
**Proposed** together with this plan. A **separate** Product Owner acceptance record
(`docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`) is created later and, in its
own commit, changes only ADR-0016's Status line to **Accepted**. **No implementation begins
until that acceptance record merges.** The implementation branch and PR must cite both the
freeze commit above and the acceptance record's merge commit.

---

## 1. Charter and ADR references

- **Charter:** [sc-m05-professional-widget-experience.md](../milestones/sc-m05-professional-widget-experience.md)
  — Objective "Deliver professional launcher and greeting presentation." **R2** (circular
  launcher; chat icon closed / X open; subtle morph; `prefers-reduced-motion` support). **R3**
  (configurable greeting/title/avatar; professional presentation). Exclusions: availability
  chrome claiming live/offline unless SC-M06's status model has shipped; AI surfaces.
  Acceptance: "R2 and R3 observable on desktop and mobile viewports per frozen plan checklist";
  "Works with Universal Telegram inactive".
- **ADR introduced:** [ADR-0016](../adr/0016-support-chat-widget-presentation-settings.md)
  (Proposed in this freeze; Accepted later by a separate record).
- **ADRs relied on (unchanged):**
  [ADR-0002](../adr/0002-plugin-identity-and-ownership-boundaries.md) (Support Chat owns the
  widget, greeting, and support-domain identity; adapters own bot identity — so M05 identity
  settings are Support-Chat-owned option keys, never Telegram bot identity),
  [ADR-0003](../adr/0003-security-privacy-and-visitor-isolation.md) (visitor isolation,
  classification, fail closed), [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md)
  (the operator Settings page this plan extends).
- **Product boundary:** `UniversalSupportChat\ChatWidget` (SC-M02, Implemented) and
  `UniversalSupportChat\Administration\Settings` (ADR-0015, Implemented). No new boundary, no
  new capability, no new milestone scope.

---

## 2. Repository findings (verified against `origin/main` @ `cf558d1`)

**Widget composition**

- `src/ChatWidget/WidgetAssets.php` — the only widget class, `final`. `register()` hooks
  `wp_enqueue_scripts` → `enqueue()` and `wp_footer` (priority 30) → `render_shell()`. Both
  early-return on `empty( $settings['widget_enabled'] )` and `is_admin()`. Hand-wired at
  `src/Core/Plugin.php:218` — `( new WidgetAssets( $settings, $schema_health ) )->register();`
  (`$settings = new Settings()` at `Plugin.php:130`; no DI container).
- `render_shell()` echoes concatenated strings (no template file). Launcher:
  `<button class="usc-chat__launcher" id="usc-chat-launcher" aria-expanded="false"
  aria-controls="usc-chat-panel">` + the literal `esc_html__( 'Chat' )` — **no icon**. Panel:
  `#usc-chat-panel` with `role="dialog" aria-modal="true" aria-labelledby="usc-chat-title
  hidden"`; header `<h2 id="usc-chat-title">` (literal "Support chat") + `<button
  id="usc-chat-close">` (literal "Close"); `#usc-chat-status[role=status aria-live=polite]`;
  `#usc-chat-messages[role=log aria-live=polite aria-relevant=additions]`; `<form
  id="usc-chat-form" hidden>` (`textarea maxlength=4096 required` + Send); `#usc-chat-signin` div.
- `enqueue()` localizes `window.uscChatWidget` via `wp_localize_script`: `restBase`, `nonce`
  (only when logged in), `loggedIn`, `schemaOk`, `loginUrl`, `pollInterval` (4000), `i18n` (a
  fixed set of UI strings). CSS and JS are enqueued with `UNIVERSAL_SUPPORT_CHAT_VERSION`
  (`0.6.0`) as the version argument — **the only asset cache-bust key**.
  `wp_set_script_translations()` is not used; there is no `languages/` directory.

**Widget JS** — `assets/js/chat-widget.js`, hand-authored ES5 IIFE, `'use strict'`, ~302
lines.

- Reads `window.uscChatWidget`; bails if missing. `appendMessage()` builds nodes with
  `document.createElement` + `.textContent` only — never `innerHTML`.
  `openPanel()`/`closePanel()`/`togglePanel()`; `closePanel()` calls `launcher.focus()`;
  `openPanel()` success calls `input.focus()`.
- Only keyboard handler: a document `keydown` for `Escape` → `closePanel()` while `open`. **No
  focus trap.**
- Polls `GET /conversations/{uuid}?after_id=` on an interval while `open && !document.hidden`;
  `pagehide` and `visibilitychange` stop polling.

**Widget CSS** — `assets/css/chat-widget.css`, hand-authored, ~180 lines, entirely scoped under
`.usc-chat`.

- `.usc-chat` fixed bottom-right, `z-index: 99999`, `system-ui` font stack.
  `.usc-chat__launcher` is a `1px` bordered white rectangle, `border-radius: 2px` — **not
  circular, no icon**. `.usc-chat__panel` `width: min(360px, calc(100vw - 2rem))`,
  `max-height: min(480px, calc(100vh - 6rem))`. `:focus-visible { outline: 2px solid #0b57d0 }`.
  **No CSS custom properties, no `@keyframes`/`transition`/`animation`/`transform`, no `@media`
  query at all.** Hardcoded hex colours throughout.

**Build / assets** — no `package.json`, no Node, **no build step**, no `assets/img/`. Assets
are committed static files served via `plugins_url()`. There is no JS lint, JS test runner, or
browser/visual CI.

**Visitor REST boundary (preserved unchanged)** —
`src/Conversations/Rest/ConversationsController.php`, namespace `universal-support-chat/v1`;
routes `POST /conversations`, `GET /conversations/mine`, `POST /conversations/{uuid}/messages`,
`GET /conversations/{uuid}`. Every `permission_callback` is `__return_true` followed by
`authenticate_session()` which requires `is_user_logged_in()` and a valid `X-WP-Nonce`
(`wp_verify_nonce( $n, 'wp_rest' )`). Logged-out visitors have **no** REST path — the widget
shows a sign-in prompt only. Cross-owner access returns a uniform `404`. M05 adds **no** route,
field, or permission change.

**Settings** — `src/Core/Configuration/Settings.php`, `final`, sole owner of the option
`universal_support_chat_settings` (array), group `universal_support_chat_settings_group`.
`defaults()` returns exactly six keys. `sanitize()` returns a **fixed-shape** array of exactly
those six keys — unknown keys are dropped; non-array input returns `defaults()`. `get()` =
`sanitize( get_option( NAME, [] ) )`. Boolean keys use `array_key_exists( … ) ? ! empty( … ) :
$default`, so an absent key on an upgraded site yields the default. There is a private
`positive_int()` helper. `register()` sets `register_setting` with `'default' =>
$this->defaults()`.

- `src/Administration/Settings/SupportChatSettingsPage.php` (ADR-0015) — WordPress Settings API
  form POSTing to `options.php`: `settings_fields( Settings::OPTION_GROUP )` +
  `do_settings_sections( self::SLUG )` + `submit_button()`. `register()` adds the
  `option_page_capability_universal_support_chat_settings_group` filter (→
  `CapabilityRegistrar::MANAGE`) synchronously. Four sections: General, Conversation lifecycle,
  Telegram adapter, Data removal. Private helpers `checkbox( $key, $desc )` (hidden `0`
  companion + checkbox) and `number( $key, $desc )` (`type=number min=1 step=1`). Constructor
  takes `Settings` + `PeerRepository`. It does **not** call `wp_enqueue_media()` or enqueue any
  admin script; `add_submenu_page()`'s return value (the hook suffix) is available for a
  page-scoped enqueue.
- `CapabilityRegistrar::MANAGE = 'universal_support_chat_manage'` — the only capability,
  granted to `administrator`.
- `src/Administration/Diagnostics/DiagnosticsPage.php` — read-only; the ADR-0015 §3 redaction
  boundary limits it to booleans, fixed enum labels, integer counts, and the version string.
  **M05 adds no Diagnostics row** — `widget_enabled` already answers "is the widget on".
- `src/Administration/Hub/HubPage.php` — the top-level menu; renders no widget presentation
  config and has no `Settings` dependency. M05 does not touch it.

**Versioning** — `universal-support-chat.php` header `Version: 0.6.0` and `define(
'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.6.0' )`. `src/Persistence/Migrator.php::target_version()`
returns `12`. ADR-0015 added no schema step and no version bump.

**Tests / CI** — `.github/workflows/ci.yml`: `phpcs` (WordPress-Extra; scans
`universal-support-chat.php`, `uninstall.php`, `src`, `tests`, `bin/check-doc-links.php` —
**not `assets/`**), `phpstan` (level 5, `src` only), `unit` (PHP 8.1/8.3/8.4),
`integration-wp-only-floor` (WP 6.9 / PHP 8.1), `integration-wp-only-current` (WP 7.1 / PHP
8.3), `docs` (link check), `interop` (pinned Universal Telegram). **No JS lint, JS test
runner, or browser/visual job.**

- Existing widget test: `tests/unit/ChatWidget/WidgetAssetsTest.php` — a static string-scan
  (files non-empty; `innerHTML` absent; `textContent`/`pagehide`/`idempotency_key`/
  `supportTeam`/`author_label` present).
- Existing: `tests/unit/Core/Configuration/SettingsTest.php`,
  `tests/integration/Administration/Settings/SupportChatSettingsPageTest.php`,
  `tests/integration/Conversations/VisitorRestTest.php`.
- SC-M02 shipped its "minimal accessible widget" with **no browser CI**; its closure records
  only that. M05 follows the same bar plus a mandatory manual QA checklist as PR evidence.

**Conventions** — `docs/plans/README.md` requires the eleven numbered sections below (this
plan uses the extended house layout of `sc-operator-settings-and-diagnostics-plan-v1.md`);
plans are immutable once committed (revisions are `vN+1`); implementation reports cite the
freeze SHA. `docs/governance.md`: the plan and every ADR it relies on land in one code-free
freeze commit; no implementation before that commit; milestone closure needs Product Owner
acceptance. `docs/adr/README.md`: Accepted ADRs are immutable except their Status line; the
eight required sections; the next available ADR number is `0016`.

---

## 3. Assumptions and open questions

**Assumptions (carried as decisions unless a reviewer objects):**

- A1 — R3's "greeting configuration in Hub/settings" is satisfied by extending the ADR-0015
  **Settings** page. The Settings submenu already lives under the Hub menu; `HubPage` has no
  settings surface by design.
- A2 — "Professional presentation" is visual polish only: no new conversational capability, no
  message-rendering change, no REST change.
- A3 — The widget stays fully functional and truthful with Universal Telegram inactive and with
  the visitor logged out (unchanged sign-in prompt path).
- A4 — CSS may restyle freely while staying scoped under `.usc-chat` and introducing no build
  step.
- A5 — `wp.media` (the WordPress core media modal) is an acceptable **admin-only** dependency;
  it ships with core and is not a "new dependency". `settings-media.js` declares `media-editor`
  as its script dependency.
- A6 — On upgrade, `Settings::sanitize()` supplies the new keys' defaults for any option array
  that lacks them, so existing sites converge automatically.

**Open questions:** none. All product decisions are settled — see §4.

---

## 4. Architectural decisions (with alternatives / tradeoffs)

All product decisions below are **settled** (Product Owner, this planning cycle). ADR-0016
records the durable subset (data model + plain-text rule).

### D1 — Launcher icon: inline hand-authored SVG in the shell markup

`render_shell()` emits two `<svg>` elements inside the launcher button — a speech-bubble glyph
and an X glyph — each `viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
aria-hidden="true" focusable="false"` with a `data-usc-icon` attribute. Paths are trivially
original geometric shapes (rounded rectangle + tail; two crossed lines) — original work, no
attribution file needed. CSS toggles which is visible from `#usc-chat-launcher[aria-expanded]`.
The button's accessible name stays on `aria-label` (already maintained by JS from the existing
`open` / `close` i18n strings); the literal visible text "Chat" is removed.

- *Rejected — Unicode emoji (💬 / ✕):* platform-dependent rendering, inconsistent metrics,
  unprofessional.
- *Rejected — pure CSS-drawn icon:* brittle, more code than two `<path>` elements.
- *Rejected — an icon library or icon font:* not proven necessary for two shapes; adds a
  network request, FOUT, and licensing/attribution burden. Dashicons are admin-only and not
  guaranteed on the front end.
- *Rejected — separate `assets/img/*.svg` files:* two extra HTTP requests, awkward
  `currentColor` inheritance, CSP `img-src` friction. Inline SVG is already the house pattern
  (no `assets/img/` exists).

### D2 — Launcher "morph": pure CSS, JS writes no styles

Driven entirely off the `aria-expanded` attribute JS already toggles — no
`requestAnimationFrame`, no JS style writes, no new JS state.

- `.usc-chat__launcher` gets `transition: transform ~160ms ease, background-color ~160ms ease`.
- Both icons are absolutely centred; each gets `transition: opacity ~140ms ease, transform
  ~180ms ease`.
- Closed (`[aria-expanded="false"]`): bubble visible/untransformed; X hidden, rotated and
  scaled down. Open (`[aria-expanded="true"]`): the reverse; the launcher itself scales up
  slightly and switches to the "close" tint — the subtle morph.
- `:active { transform: scale(.96) }` press feedback.
- **Reduced motion** — one block neutralises all of it:
  `@media (prefers-reduced-motion: reduce)` sets `transition: none` on the launcher, the icons,
  and the panel, and `transform: none` on the icons. Result: instant icon swap, no
  scale/rotate, no panel entrance transition.
- *Rejected — a JS Web Animations API implementation:* needs feature detection and adds JS; CSS
  is the correct layer and gets `prefers-reduced-motion` for free.
- *Rejected — animating the launcher's dimensions into the panel (a true FAB→sheet morph):*
  janky, forces layout, out of scope for "subtle".

### D3 — Circular launcher, professional panel; CSS custom properties for the palette only

- **Launcher:** `56×56` (`52` at `≤ 480px`, never below `44` — WCAG 2.5.5 / 2.5.8),
  `border-radius: 50%`, accent background, white `24px` icon, elevated shadow.
- **Panel — desktop (`> 480px`):** a floating card, `width: min(380px, calc(100vw - 2rem))`,
  `max-height: min(560px, calc(100vh - 6rem))`, `border-radius: var(--usc-radius, 12px)`,
  refined shadow, hairline border. Optional short `translateY` + opacity entrance, disabled
  under reduced motion.
- **Panel — mobile (`@media (max-width: 480px)`):** `position: fixed; inset: 0; width: 100%;
  height: 100dvh; max-height: none; border-radius: 0` — a full-screen sheet; sticky header.
- **Header:** `[optional avatar 28px round] [title <h2>] [spacer] [icon-only X close, 44px hit
  area, aria-label from the close i18n string]`.
- **Greeting / intro block:** a new `<div id="usc-chat-intro" class="usc-chat__intro">`
  rendered directly below the header, above `#usc-chat-status`. Its text is set by JS from
  `cfg.greeting` via `.textContent` **during widget initialization, before the panel can open**
  (so `aria-describedby="usc-chat-intro"` resolves to real text the moment focus enters the
  dialog). CSS `white-space: pre-wrap` preserves newlines; `:empty { display: none }` collapses
  it when unset.
  - **Persistence (settled):** visible above the messages on **desktop**; on **`≤ 480px`**,
    **hidden once the message log is non-empty** — a `data-has-messages` attribute is set on
    `#usc-chat-root` by JS and a rule inside the mobile `@media` hides
    `[data-has-messages] .usc-chat__intro`.
- **States** (all copy from `cfg.i18n`, all rendered via `.textContent`):

  | State | Copy | Treatment |
  |---|---|---|
  | Empty conversation | `cfg.i18n.empty` (existing) | muted hint under the intro |
  | Loading (creating conversation / first poll) | **new** `cfg.i18n.loading` ("Connecting…") | muted text in the status region; composer disabled until the first poll resolves |
  | Send in progress | `cfg.i18n.sending` (existing) | button label swap + disabled (existing) |
  | Error — auth / unavailable / generic | existing `cfg.i18n.errorAuth` / `errorUnavailable` / `errorGeneric` | status region, `.is-error` token |
  | Logged out | existing `cfg.i18n.signIn` + `signInButton` | sign-in card styled as a primary call to action |

  Exactly **one** new i18n string is added (`loading`). The title and avatar are **not**
  added to the localized payload (D4).
- **CSS custom properties** — declared once on `.usc-chat`, each usage carrying a hardcoded
  fallback (`background: #0b57d0; background: var(--usc-accent, #0b57d0);`) so a
  property-unaware or property-stripped context still renders. **No setting drives any colour
  in M05.** Themes *could* override the tokens — an unsupported escape hatch, documented as not
  a feature.
- **Accent colour (settled):** `#0b57d0` (the existing focus-ring blue). Contrast versus white
  ≈ 8.6:1 — passes 4.5:1 for text and 3:1 for UI components / large text. Muted text `#5c5c5c`
  on white ≈ 7.0:1; error `#8b0000` on white ≈ 9.1:1.
- **RTL:** logical properties for the new layout plus an explicit `[dir="rtl"] .usc-chat {
  right: auto; left: 1rem }` mirror.

### D4 — Greeting / title / avatar data model (ADR-0016)

Three additive keys in `universal_support_chat_settings`. `Settings::defaults()` and
`Settings::sanitize()` grow from six to nine keys; `sanitize()` stays fixed-shape; the three
array-shape docblocks are updated (PHPStan level 5). **No database schema change** —
`db_version` and `Migrator::target_version()` stay at `12`.

| Key | Type | Default | Sanitisation |
|---|---|---|---|
| `widget_title` | string | `''` — resolves to the translated `Support chat` at render | `sanitize_text_field()`, then truncate to **80** characters. Never HTML. |
| `widget_greeting` | string (multiline) | translated **`Hi — how can we help?`** | `sanitize_textarea_field()` (strips tags, keeps `\n`), then truncate to **500** characters. Never HTML. |
| `widget_avatar_attachment_id` | int | `0` (no avatar) | `absint()`, then require `wp_attachment_is_image()` — a non-image or unknown id becomes `0`. |

- **Title default `''`, resolved at render:** keeps the label translatable per locale; an empty
  stored value unambiguously means "not customised".
- **Greeting default renders on upgrade:** string keys are read via `?? default`, so an
  upgraded site with no stored greeting shows `Hi — how can we help?` on the next front-end
  render. It makes **no** availability claim and — deliberately — **no** service or
  response-time commitment, even implied.
- **Output — greeting:** `enqueue()` adds only `'greeting' => $values['widget_greeting']` (raw
  string) to the localized object (`wp_localize_script` JSON-encodes it safely); the widget
  script assigns it to `#usc-chat-intro` via `.textContent` during init. `.textContent` is
  categorically safe and matches the existing message-rendering discipline the string-scan test
  enforces. *Rejected — server-rendered `esc_html` + `nl2br`:* reintroduces HTML assembly into
  a codebase that deliberately has none.
- **Output — title:** rendered **only** server-side in `render_shell()` as `esc_html(
  $resolved_title )` inside `<h2 id="usc-chat-title">`. The dialog's accessible name comes from
  `aria-labelledby="usc-chat-title"` pointing at that `<h2>`, so it is present before JS runs.
  **No resolved title is added to the JS payload** — the widget script does not consume it; the
  pre-existing `i18n.title` localize key is left untouched (out of M05 scope).
- **Output — avatar:** rendered **entirely server-side** in `render_shell()` via
  `WidgetPresentation`, which resolves `wp_get_attachment_image_url( $id, 'thumbnail' )` when
  `$id > 0`. `render_shell()` emits `<img class="usc-chat__avatar" alt="" src="…">` (`src` via
  `esc_url()`) **only** when the resolved URL is non-empty; otherwise no `<img>` node at all —
  never a broken image. `alt=""` decorative (the `<h2>` conveys identity — D8). **No avatar URL
  is added to the JS payload.**
- **Durable rule (ADR-0016):** operator-authored widget presentation text is **plain text
  only** — never accepted, stored, or rendered as HTML; tag-stripped on input; `.textContent` /
  `esc_html` on output. Any future rich presentation content requires a new ADR.

### D5 — Avatar admin control: `wp.media` image picker (settled)

- New file `assets/js/settings-media.js` — an ES5 IIFE, ~40 lines. It declares WordPress's
  **`media-editor`** script handle as its dependency, so `wp.media` is guaranteed present.
- Enqueued **only on the Settings page**: `SupportChatSettingsPage::add_menu()` captures the
  hook suffix returned by `add_submenu_page()`; `register()` adds an `admin_enqueue_scripts`
  callback that returns immediately unless `$hook_suffix` matches, then calls
  `wp_enqueue_media()` and `wp_enqueue_script( '…-settings-media', …, array( 'media-editor' ),
  UNIVERSAL_SUPPORT_CHAT_VERSION, true )`.
- The picker frame opens with `wp.media({ library: { type: 'image' }, multiple: false })` —
  **images only**. On select it writes the attachment id to the hidden input
  `universal_support_chat_settings[widget_avatar_attachment_id]` and swaps in a thumbnail
  preview. The **"Remove" button writes `0`** to the hidden input and clears the preview.
- Server-side validation is authoritative regardless of the control: `Settings::sanitize()`
  runs `absint()` then `wp_attachment_is_image()`, so a non-image or bad id becomes `0`.
- *Rejected — a bundled predefined icon set:* binary assets in a repo that has none plus
  per-icon licensing.
- *Rejected — deferring the avatar:* R3 explicitly lists "title/avatar".
- The stored data model (an attachment id) is identical to that of a plain number field, so a
  future change of control needs no rework.

### D6 — ADR-0016 (short ADR, separate acceptance)

M05 introduces the first operator-authored, visitor-rendered content in Support Chat and its
durable "plain-text-only, tag-stripped in, escaped/`.textContent` out, never HTML" rule (a
security-boundary precedent for SC-M06 and SC-AI1/SC-AI2), plus the "avatar = a validated
Media Library image attachment id, `0` = none" identity data model under ADR-0002. ADR-0003 has
no client-side sanitisation rule, so this precedent is new. ADR-0016 is short (one to three
sentences per required section) and lets the plan cite an authorisation rather than argue
product scope.

**Process (mirrors ADR-0015):** the freeze PR merges ADR-0016 as **Proposed** with this plan.
After the freeze merges, a **separate** `docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`
records Product Owner acceptance and, in that same commit, changes **only** ADR-0016's Status
line to **Accepted** (the sole edit ever permitted to an Accepted-track ADR). Implementation
(WP1 onward) begins only after that acceptance record merges; the implementation PR cites both
the freeze SHA and the acceptance SHA.

### D7 — Plugin version bump `0.6.0` → `0.7.0` (settled)

Two independent reasons: (1) `UNIVERSAL_SUPPORT_CHAT_VERSION` is the **only** `ver` argument on
the widget CSS and JS; M05 rewrites both, so without a bump returning visitors get stale assets
from browser and page caches; (2) the established per-milestone convention (SC-M01 = `0.1.0` …
SC-M03 WP0 = `0.3.0` … current `0.6.0`). The bump changes the `Version:` header and the
`define()` in `universal-support-chat.php`.

- *Rejected — `filemtime()`-based asset versioning:* breaks reproducible packaging (mtimes
  differ per checkout / CI / zip) and diverges from every other enqueue in the plugin.

### D8 — Dialog semantics and focus management: **non-modal, no focus trap** (settled)

The panel is a **non-blocking** support widget, not a modal task. `aria-modal="true"` while the
rest of the page stays operable is contradictory, and a focus trap alone does not make a true
modal. M05:

- **Removes `aria-modal="true"`** from `#usc-chat-panel`; keeps `role="dialog"` and
  `aria-labelledby="usc-chat-title"`; adds `aria-describedby="usc-chat-intro"`.
- **Adds no Tab focus trap.** `Tab` from the last panel control moves to the page as normal — a
  visitor can inspect the page while composing a message.
- **On open:** JS moves focus into the panel (to the close button immediately, before the async
  conversation bootstrap, so focus never sits on a hidden/detached element); the existing
  `input.focus()` on open success stays.
- **On close (Close button or Escape):** focus returns to the launcher — already implemented,
  kept.
- **Launcher:** keeps `aria-expanded` and `aria-controls`; `aria-label` maintained by JS; add
  `aria-haspopup="dialog"` for clarity.
- **Avatar:** `alt=""` decorative — the operator/site name is not repeated in `alt` (it would
  double-announce with the `<h2>`).
- **No-JS / JS-error fallback:** `#usc-chat-root` and `#usc-chat-panel` are both `hidden` in
  the markup; JS un-hides the root. If JS fails before that line, the whole widget stays
  invisible and inert — no launcher, no keyboard dead-end. This matches today's behaviour and
  is the accepted degradation.
- **Background `inert` / `aria-hidden` on siblings:** **not** applied — a persistent corner
  utility must not make the rest of the site inert while a visitor references page content
  mid-message. WCAG 2.1.2 (no keyboard trap) is satisfied precisely because there is no trap.
- *Rejected — keep `aria-modal="true"` and add a focus trap:* over-promises a blocking modal
  the widget is not, and is hostile to a visitor referencing page content while typing.

---

## 5. Directory, namespace, schema, and API impact (scoped)

**Schema: none.** `Migrator::target_version()` stays `12`. No migration step, no new option
(three keys added to the existing `universal_support_chat_settings` array), no REST
route/field/permission change, no new capability, uninstall unchanged.

**New files (implementation phase — not this freeze)**

| Path | Purpose |
|---|---|
| `src/ChatWidget/WidgetPresentation.php` | `final` value object resolving `title()` (with the "Support chat" fallback), `greeting()`, and `avatar_image_url()` (server-side, for the `<img>` in `render_shell()`) from a `Settings` array. Keeps `WidgetAssets` thin; pure unit-testable. Namespace `UniversalSupportChat\ChatWidget`. |
| `assets/js/settings-media.js` | ES5 admin-only `wp.media` image picker for the avatar (D5); depends on `media-editor`; image-only; "Remove" writes `0`. |
| `tests/unit/ChatWidget/WidgetPresentationTest.php` | Value-object unit tests. |
| `tests/integration/ChatWidget/WidgetShellRenderTest.php` | `render_shell()` / `enqueue()` assertions (§9). |
| `tests/integration/Core/Configuration/SettingsAvatarValidationTest.php` | `wp_attachment_is_image()` validation gate (§9). |

**Modified files (implementation phase — not this freeze)**

| Path | Change |
|---|---|
| `src/Core/Configuration/Settings.php` | `defaults()` + `sanitize()` grow to nine keys — `widget_title` / `widget_greeting` / `widget_avatar_attachment_id` with the D4 caps and the attachment-image validation; the three array-shape docblocks updated. |
| `src/ChatWidget/WidgetAssets.php` | `render_shell()`: circular launcher with two inline SVGs (drop the "Chat" text); server-rendered `<img class="usc-chat__avatar" alt="">` only when the resolved URL is non-empty; server-rendered `<h2>` title via `WidgetPresentation`; new `#usc-chat-intro`; icon-only close button; **`role="dialog"` with no `aria-modal`**; `aria-describedby="usc-chat-intro"`; launcher `aria-haspopup="dialog"`. `enqueue()`: add **only** `greeting` (raw string) and `i18n.loading` to the localized object; build a `WidgetPresentation` from the injected `Settings`. |
| `assets/js/chat-widget.js` | Set `#usc-chat-intro` `.textContent` from `cfg.greeting` **during init, before any open is possible**; move focus into the panel on open and back to the launcher on close/Escape (**no Tab trap**); `loading` status string; set `data-has-messages` on the root once messages exist (drives the mobile intro hide). No style writes. |
| `assets/css/chat-widget.css` | Full restyle: `.usc-chat` custom-property block with hardcoded fallbacks; circular 56px accent launcher; two stacked absolutely-positioned icons toggled by `[aria-expanded]`; `transition` rules; `@media (prefers-reduced-motion: reduce)` block; `@media (max-width: 480px)` full-screen panel + `[data-has-messages] .usc-chat__intro { display: none }`; `.usc-chat__avatar`, `.usc-chat__intro` (`pre-wrap`, `:empty { display: none }`); refined header/close/composer; logical properties + `[dir="rtl"]` mirror. |
| `src/Administration/Settings/SupportChatSettingsPage.php` | New `SECTION_PRESENTATION` constant + `add_settings_section` ("Widget presentation", after General); three `add_settings_field` + render callbacks; new private helpers `text( $key, $desc, $maxlength )` and `textarea( $key, $desc, $maxlength )`; an avatar render callback (media button + hidden input + current thumbnail); capture the `add_submenu_page` hook suffix and, on that hook only, `wp_enqueue_media()` + enqueue `settings-media.js`. |
| `universal-support-chat.php` | `Version: 0.7.0` and `define( 'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.7.0' )`. |
| `tests/unit/ChatWidget/WidgetAssetsTest.php` | Add the §9 string-scan assertions. |
| `tests/unit/Core/Configuration/SettingsTest.php` | Add the §9 defaults / caps / upgrade-path assertions. |
| `tests/integration/Administration/Settings/SupportChatSettingsPageTest.php` | Add the §9 presentation-section assertions. |
| `docs/adr/README.md` | (freeze) ADR-0016 index entry; next available number → `0017`. |
| `docs/plans/README.md` | (freeze) SC-M05 v1 marked superseded; v2 added. |
| `docs/milestones/sc-m05-professional-widget-experience.md` | (freeze) "Frozen plan" → v2; ADR-0016 note. Milestone scope unchanged. |
| `docs/milestones/README.md` | SC-M05 status → **In Progress** only when implementation actually starts (not in this freeze). |

`src/Core/Plugin.php:218` changes **only** if `WidgetAssets`'s constructor signature changes;
the preferred approach builds `WidgetPresentation` inside `WidgetAssets` from the
already-injected `Settings`, leaving the composition root untouched. `readme.txt` / `README.md`
changelog and `Stable tag`: update to `0.7.0` if the repository tracks them (verify during
implementation).

**Docs changed in this freeze commit:** `docs/adr/0016-support-chat-widget-presentation-settings.md`
(new), `docs/plans/sc-m05-professional-widget-experience-plan-v2.md` (new, this file),
`docs/adr/README.md`, `docs/plans/README.md`,
`docs/milestones/sc-m05-professional-widget-experience.md`. All are under `docs/`.

---

## 6. Widget presentation contract

| Surface | Source | Sanitised as | Rendered as | Empty behaviour |
|---|---|---|---|---|
| Panel title `<h2 id="usc-chat-title">` | `widget_title` | `sanitize_text_field`, ≤ 80 | server `esc_html` only (dialog name via `aria-labelledby`); **not in the JS payload** | falls back to the translated `Support chat` |
| Launcher accessible name | existing `open` / `close` i18n strings | n/a | `aria-label` set by JS | always present |
| Greeting / intro `#usc-chat-intro` | `widget_greeting` | `sanitize_textarea_field`, ≤ 500, newlines kept | JS `.textContent` (set during init), CSS `white-space: pre-wrap` | node stays empty; CSS `:empty { display: none }` |
| Header avatar `<img class="usc-chat__avatar" alt="">` | `widget_avatar_attachment_id` | `absint` + `wp_attachment_is_image` else `0` | `render_shell()` emits `<img>` server-side only when `WidgetPresentation::avatar_image_url()` is non-empty; `src` via `esc_url`; **not in the JS payload** | no `<img>` node |
| Launcher icons | inline SVG in the shell | n/a (static, original) | `aria-hidden="true" focusable="false"`, `stroke="currentColor"` | both always present; CSS toggles visibility |

**Durable rule:** operator presentation text is plain text only — never accepted, stored, or
rendered as HTML. Tag-strip on input; `.textContent` / `esc_html` on output. No `innerHTML` in
the widget script, ever (enforced by `WidgetAssetsTest`).

---

## 7. Launcher and panel visual spec

- **Launcher:** `56×56` (`52` at `≤ 480px`, never `< 44`), `border-radius: 50%`, `background:
  var(--usc-accent, #0b57d0)`, white `24px` icon centred, elevated shadow; hover
  `filter: brightness(1.05)`; `:active { transform: scale(.96) }`.
- **Icon states** driven by `#usc-chat-launcher[aria-expanded]` (D2), ~140–180ms ease, fully
  disabled under `prefers-reduced-motion`.
- **Panel desktop:** `min(380px, calc(100vw - 2rem))` × `max-height: min(560px, calc(100vh -
  6rem))`, `border-radius: var(--usc-radius, 12px)`, refined shadow, hairline border. Optional
  short `translateY` + opacity entrance, reduced-motion off.
- **Panel mobile (`@media (max-width: 480px)`):** `position: fixed; inset: 0; width: 100%;
  height: 100dvh; max-height: none; border-radius: 0`; sticky header; `[data-has-messages]
  .usc-chat__intro { display: none }`.
- **Header:** `display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem`; avatar
  `28px` round (when present) → `<h2>` (`font-size: 1rem; font-weight: 600`) → spacer →
  icon-only X close (`44px` hit area, transparent background, `aria-label`).
- **Intro:** `padding: .75rem 1rem; color: var(--usc-text, #1a1a1a); white-space: pre-wrap;
  border-bottom: 1px solid var(--usc-border)`; `:empty { display: none }`.
- **Status / messages / composer:** keep the current structure; restyle to tokens; bubbles keep
  `pre-wrap` + `word-break: break-word`.
- **RTL:** logical properties + `[dir="rtl"] .usc-chat { right: auto; left: 1rem }`.

---

## 8. Security and privacy impact

- **The visitor isolation boundary is unchanged.** REST routes, `authenticate_session()`, the
  nonce, the uniform ownership `404`, and the logged-out sign-in path are all untouched.
- **New input surface:** three operator-set option values, writable only by
  `CapabilityRegistrar::MANAGE` (administrator) through the existing Settings API and its
  `option_page_capability_` filter — the same trust level as the six existing keys.
- **XSS:** the title is tag-stripped and server `esc_html`'d; the greeting is tag-stripped and
  rendered with `.textContent`; there is no `innerHTML` in the widget script (test-enforced);
  `wp_localize_script` JSON-encodes the one payload string (`greeting`) safely; the avatar
  `src` is server `esc_url`'d and points only to an already-public uploaded image. The JS
  payload carries neither the title nor the avatar URL — a smaller client surface and a cleaner
  server/client split.
- **CSP:** inline SVG is inline *content*, not an inline script or style — no CSP relaxation.
  There is no external font or asset (a string-scan asserts no `http(s)://` literal in the
  JS/CSS). No new inline `<script>`.
- **Privacy:** the greeting and title are operator content, not visitor PII; the avatar URL
  exposes an attachment already public on the site. No new logging, no new retained data beyond
  the option (already covered by the uninstall data-removal setting).
- **Admin script** (`settings-media.js`): runs only on the Settings page for `MANAGE` users;
  uses core `wp.media`; stores only an integer id; the server re-validates.
- **Fail-closed:** a bad avatar id becomes `0` (no image); a missing greeting becomes the
  default text (no availability claim); a JS failure leaves the widget hidden and inert.
- Respects [ADR-0003](../adr/0003-security-privacy-and-visitor-isolation.md) (no visitor data
  on the widget beyond the existing transcript) and
  [ADR-0006](../adr/0006-optional-channel-and-adapter-failure-model.md) (a slow or absent
  adapter never blocks or breaks the widget — unchanged).

---

## 9. Test and CI impact

**No CI workflow change.** All new and modified PHP passes `phpcs` (WordPress-Extra) and
`phpstan` level 5. `assets/` stays unscanned by `phpcs`, so the widget JS/CSS invariants are
policed by the `WidgetAssetsTest` string-scan, which grows.

- **UPDATE `tests/unit/ChatWidget/WidgetAssetsTest.php`** (string-scan, no WordPress):
  - CSS contains `@media (prefers-reduced-motion: reduce)` **and** that block sets `transition:
    none`.
  - CSS contains `border-radius: 50%` on the launcher rule (circular).
  - CSS contains at least one `transition:` on the launcher/icons (morph present).
  - CSS declares at least one `--usc-` custom property and token usages carry a fallback
    (`var(--usc-accent,` pattern).
  - CSS **and** JS contain no `http://` / `https://` literal (no external asset/font).
  - JS still has no `innerHTML`, no `eval(`, no `new Function`.
  - JS adds **no** Tab focus trap (assert the source contains no `Tab`-key wrap handler on the
    panel / no `trapFocus`); it sets focus into the panel on open and returns focus to the
    launcher on close.
  - JS references `cfg.greeting` and sets `#usc-chat-intro` text content during init; JS sets
    `data-has-messages`.
- **NEW `tests/unit/ChatWidget/WidgetPresentationTest.php`** — `title()` falls back to
  "Support chat" when empty; `greeting()` passthrough; `avatar_image_url()` resolution with a
  stubbed resolver; caps applied.
- **NEW `tests/integration/ChatWidget/WidgetShellRenderTest.php`** (`WP_UnitTestCase`):
  - `render_shell()` output contains `usc-chat__launcher`, both `data-usc-icon` SVGs with
    `aria-hidden="true"`, `role="dialog"`, **no `aria-modal`**, `aria-describedby="usc-chat-intro"`,
    `id="usc-chat-intro"`.
  - `widget_title = "<script>alert(1)</script>Team"` → the rendered `<h2>` contains the
    escaped/stripped form, **not** a raw `<script>`.
  - `widget_title = ''` → the `<h2>` contains the translated "Support chat" fallback.
  - Avatar: id `0` → no `usc-chat__avatar` `<img>`; a real image attachment (via
    `self::factory()->attachment`) → exactly one `<img class="usc-chat__avatar"` with `alt=""`
    and an `esc_url`'d `src`; a non-image attachment id → no `<img>`.
  - `enqueue()` then read `wp_scripts()->get_data( 'universal-support-chat-widget', 'data' )` —
    the blob contains `greeting` (tag-free even for a tag-containing input) and contains
    **neither** `avatarUrl` **nor** a resolved `title` key.
- **UPDATE `tests/unit/Core/Configuration/SettingsTest.php`:**
  - `defaults()` returns nine keys with the documented values (including `widget_greeting =
    "Hi — how can we help?"`).
  - `sanitize( [] )` returns all nine keys (fixed shape) at defaults; unknown keys are still
    dropped.
  - Title cap: a 200-character input → 80; tags stripped. Greeting cap: 1000 → 500; newlines
    preserved; tags stripped. Avatar: non-numeric → `0`; negative → `0`.
  - Upgrade path: `get()` on an option array holding only the original six keys returns the
    three new keys at their defaults.
- **NEW `tests/integration/Core/Configuration/SettingsAvatarValidationTest.php`** — a positive
  integer that is not an image attachment → `0`; a real image attachment id → preserved.
- **UPDATE `tests/integration/Administration/Settings/SupportChatSettingsPageTest.php`:**
  - A "Widget presentation" section is registered on the page.
  - The three fields render (title text input, greeting textarea, avatar control + hidden
    input).
  - Round-trip through `sanitize()`; `<script>` in the title field renders escaped in the field
    `value` attribute on reload.
  - `settings-media.js` and `wp_enqueue_media()` fire on the Settings page hook and **not** on
    an unrelated admin page.
- **Existing** `VisitorRestTest`, `CapabilityRegistrarTest`, `PluginTest` — run to prove no
  regression; REST behaviour must be byte-identical.
- **Docs link check** must pass (the new ADR + plan cross-links).

**Manual QA checklist** (mandatory PR evidence; no browser CI, matching SC-M02):

- Viewports: desktop 1280×800 and mobile 375×667; browsers: Chrome, Firefox, Safari (WebKit).
- Keyboard-only pass: Tab → launcher → Enter opens → focus moves into the panel → **Tab from
  the last panel control leaves the panel to the page (no trap)** → Esc closes → focus returns
  to the launcher.
- Screen-reader smoke: VoiceOver (Safari) and NVDA (Firefox) — on open, the dialog is announced
  by its name (`<h2>` title) and its description (the greeting).
- `prefers-reduced-motion: reduce` on: instant icon swap, no transform/scale/rotate, no panel
  entrance slide.
- RTL (`<html dir="rtl">`): the widget flips to bottom-left and the header mirrors.
- 80-character title and 500-character greeting: no overflow, the panel scrolls.
- Lighthouse accessibility ≥ 95 with the widget open; contrast of `#0b57d0` vs white (≥ 4.5:1
  text / 3:1 UI).
- **Universal Telegram inactive** (plugin deactivated), verify all of: (a) a logged-in visitor
  can create and send a message in the Support Chat widget; (b) a Hub operator can reply; (c)
  the widget receives that reply on its next poll; (d) **no** Telegram UI, identity,
  availability claim, error, or dependency appears anywhere in the widget or the Hub.
- Logged-out: the sign-in card renders and is styled; no console errors.
- Hard reload confirms the new CSS/JS load (the version bump busted the cache).

---

## 10. Work packages (execution order)

- **WP0 — Freeze (this PR).** ADR-0016 (**Status: Proposed**) + this plan (v2), code-free, in
  one commit, with the index updates (`docs/adr/README.md`, `docs/plans/README.md`, the
  milestone charter link + note). Gate: Master Architect review + Product Owner approval
  (governance §"Milestone lifecycle" steps 2–4).
- **WP0a — ADR acceptance (separate, later).** A separate commit adds
  `docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md` and, in that same commit,
  changes only ADR-0016's Status line to **Accepted**. No other ADR text is touched.
  Implementation (WP1 onward) begins only after this merges.
- **WP1 — Data model.** `Settings.php` `defaults()` + `sanitize()` + docblocks (nine keys,
  caps, avatar validation); `WidgetPresentation` value object. Tests: `SettingsTest`
  additions, `SettingsAvatarValidationTest`, `WidgetPresentationTest`. Gate: unit + integration
  + phpstan green.
- **WP2 — Widget shell + enqueue.** `WidgetAssets::render_shell()` (SVG launcher, server-rendered
  avatar `<img>`, `<h2>` from `WidgetPresentation`, `#usc-chat-intro`, icon close,
  `role="dialog"` with no `aria-modal`, `aria-describedby`, launcher `aria-haspopup`);
  `enqueue()` adds only `greeting` + `i18n.loading`. Tests: `WidgetShellRenderTest`. Gate:
  integration green.
- **WP3 — Widget CSS restyle.** Full `chat-widget.css` rewrite: tokens + fallbacks, circular
  launcher, icon-morph transitions, reduced-motion block, mobile full-screen `@media` +
  intro-hide rule, intro/avatar styles, RTL. Tests: `WidgetAssetsTest` CSS scans. Gate: unit
  green.
- **WP4 — Widget JS a11y + greeting.** `chat-widget.js`: set `#usc-chat-intro` text content
  during init; focus into the panel on open, focus back to the launcher on close/Escape (**no
  Tab trap**); `loading` string; `data-has-messages` toggle. Tests: `WidgetAssetsTest` JS
  scans (including the "no focus-trap" assertion). Gate: unit green. (WP3 and WP4 may run in
  parallel.)
- **WP5 — Settings presentation section.** `SupportChatSettingsPage`: `SECTION_PRESENTATION`,
  three fields, `text` / `textarea` helpers, avatar render callback; `assets/js/settings-media.js`
  + `wp_enqueue_media()` gated to the page hook. Tests: `SupportChatSettingsPageTest`
  additions. Gate: integration + phpcs green.
- **WP6 — Version bump + docs.** `universal-support-chat.php` → `0.7.0`; changelog / `Stable
  tag` if tracked; final charter/plan link tidy; `docs/milestones/README.md` SC-M05 status →
  In Progress.
- **WP7 — Manual QA pass.** Execute the §9 checklist; capture evidence; attach to the PR.
  Gate: every checklist item passes.
- **WP8 — Implementation report + closure**, citing the WP0 freeze SHA **and** the WP0a
  ADR-0016 acceptance SHA; Product Owner acceptance per `docs/closure/` conventions.

Sequencing: WP0 → WP0a → WP1 → WP2 → (WP3, WP4 parallel) → WP5 → WP6 → WP7 → WP8.

---

## 11. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Stale CSS/JS from browser/page caches after ship (the sole cache-bust key is the version constant) | D7 bump to `0.7.0`; WP6 verifies both the `Version:` header and the `define()`; WP7 hard-reload check. |
| Keyboard focus may leave the non-modal panel while it is open | Intended behaviour (D8): focus enters the panel on open; Escape and Close restore focus to the launcher; the panel is never trapped. WP7 keyboard-only pass verifies no trapping and no focus loss (focus always lands somewhere sensible). |
| Non-modal `role="dialog"` under-signals "a dialog opened" to some screen readers | Focus is moved into the panel on open and the dialog carries `aria-labelledby` + `aria-describedby`, which triggers a dialog announcement in NVDA/VoiceOver; verified in the WP7 SR smoke. Accepted: the widget is genuinely non-blocking. |
| Greeting text not yet in `#usc-chat-intro` when focus enters the dialog → empty `aria-describedby` | `intro.textContent` is set during init, before `openPanel()` can run (D8); `WidgetAssetsTest` asserts the init-time assignment; WP7 SR smoke confirms the description is announced. |
| Default greeting silently appears on upgraded production sites | Intended; the string is `Hi — how can we help?` — no availability claim, no implied service commitment; a one-line fallback to default `''` if ever reversed. |
| `settings-media.js` conflicts with the block editor or other `wp.media` consumers | Enqueued only on the Settings page hook; declares `media-editor` as its dependency; a namespaced frame variable; no global `wp.media` reconfiguration; a test asserts it does not load elsewhere. |
| Theme CSS bleeding into `.usc-chat` (or the widget leaking out) | All rules stay scoped under `.usc-chat`; specific class selectors; `z-index: 99999` retained; WP7 checks three themes. |
| RTL layout breakage | Logical properties + an explicit `[dir="rtl"]` mirror; WP7 RTL check. |
| Very long greeting/title overflow | 80 / 500 caps in `sanitize()`; the panel scrolls; `word-break: break-word`; WP7 uses max-length strings. |
| Chosen accent fails contrast | `#0b57d0` pre-verified ≈ 8.6:1; tokens carry hardcoded fallbacks so a theme override cannot silently break the shipped default; WP7 re-checks. |
| PHPStan array-shape drift (six → nine keys) breaks level 5 | WP1 updates all three docblocks; CI catches it. |
| Reduced-motion still cross-fades the icons | The reduced-motion block sets `transition: none` on the icons; `WidgetAssetsTest` asserts it. |
| The milestone-charter touch is treated as a boundary change | The charter edit is a link + a note only; if the Master Architect deems it a boundary change it becomes its own standalone documentation-only commit (governance §"Changing a frozen milestone charter"). |

---

## 12. Out of scope (explicit)

- **Any availability / presence chrome** — no "online", "offline", "typically replies in…", or
  "we're away" text or indicator. Deferred to SC-M06's authoritative status model. **Stop and
  report** if the design seems to need it — do not expand scope.
- **AI surfaces** — no drafts, suggestions, or bot identity.
- **Telegram** — no dependency; the widget is fully functional with Universal Telegram
  inactive; no Telegram bot identity is surfaced (adapter-owned per ADR-0002). **No Universal
  Telegram change.**
- **REST / conversation model** — no new route, field, permission, or message type; visitor
  isolation and the authenticated-only path are byte-identical.
- **Schema / migration** — `db_version` stays `12`; `Migrator::target_version()` is untouched.
- **HTML or Markdown in the greeting/title** — plain text only (ADR-0016 durable rule).
- **Per-operator avatars / multiple operator identities** — a single site-level avatar only.
- **Settings-driven colours / a theming UI** — CSS custom properties exist only as an
  unsupported override hook.
- **Build tooling / npm / a bundler / a JS test runner / browser CI** — not introduced.
- **`wp_set_script_translations()` / a `languages/` directory** — the widget's JS strings stay
  via `wp_localize_script` (a pre-existing gap, not this milestone).
- **Hub landing-page redesign** — the presentation config lives on the ADR-0015 Settings page.
- **Notification sound / an unread badge / proactive auto-open** — not in R2/R3.
- **DEV or production deployment, a feature branch, a settings change, a tag or release** —
  not part of this planning deliverable; the freeze PR changes only files under `docs/`.

---

## 13. Definition of done (mapped to charter acceptance)

- **R2 observable, desktop + mobile:** a circular accent launcher; a speech-bubble glyph when
  closed and an X when open; a subtle CSS morph on toggle; `@media (prefers-reduced-motion:
  reduce)` disables all motion (instant swap). Verified in WP7 at 1280px and 375px, in
  Chrome/Firefox/Safari, with reduced motion on and off.
- **R3 observable, desktop + mobile:** the operator-set title, greeting, and optional avatar
  render in the panel; empty values fall back correctly (title → "Support chat", greeting →
  `Hi — how can we help?`, avatar → none, never a broken image). Header and states polished per
  §7.
- **"Works with Universal Telegram inactive":** with the Universal Telegram plugin deactivated,
  WP7 verifies (a) visitor create/send in Support Chat, (b) a Hub operator reply, (c) the
  widget polling and showing that reply, (d) no Telegram UI, identity, availability claim,
  error, or dependency anywhere.
- **Accessibility:** `role="dialog"` **without** `aria-modal` and **no** Tab focus trap; focus
  moves into the panel on open and back to the launcher on Esc/close; the dialog name and
  description (greeting) are announced on open (VoiceOver + NVDA smoke); contrast ≥ 4.5:1
  (text) / 3:1 (UI); Lighthouse accessibility ≥ 95 with the widget open; keyboard-only pass
  clean and never trapped.
- **Security:** `<script>` in the title/greeting proven escaped/stripped by integration tests;
  no `innerHTML` in the widget script (string-scan); a bad avatar id → no image.
- **Quality gates green:** `phpcs`, `phpstan` level 5, `unit` (8.1/8.3/8.4),
  `integration-wp-only` (WP 6.9/PHP 8.1 + WP 7.1/PHP 8.3), `docs`, `interop` — all pass.
- **`db_version` unchanged at 12**; `Migrator::target_version()` untouched.
- **Version bumped to `0.7.0`** (both the header and `UNIVERSAL_SUPPORT_CHAT_VERSION`) so the
  new assets bust caches.
- **ADR-0016 was merged Proposed in the freeze commit and later flipped to Accepted by a
  separate acceptance record;** the implementation report cites both the freeze SHA and the
  acceptance SHA and attaches the manual QA evidence.
