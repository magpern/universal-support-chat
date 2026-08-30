# Closure — SC-M05: Professional Widget Experience

## Status

**Closed (PASS WITH LIMITATIONS). Merged to `main`. Not deployed.**

Documentation-only closure record. No runtime code, test, plugin-version, schema, settings,
CI, dependency, Universal Telegram, DEV, production, deployment, tag, or release change is
made by this record.

The single limitation is a **post-merge recommended human assistive-technology (AT)
validation** (VoiceOver / NVDA) that this environment could not run. It is **not** claimed as
passed, and axe / Lighthouse do not substitute for it. See
[Post-merge recommended human AT validation](#post-merge-recommended-human-at-validation).

## What this closes

SC-M05 ([charter](../milestones/sc-m05-professional-widget-experience.md), requirements
**R2** / **R3**), realising [ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md)
(Accepted) exactly within the frozen scope of
[plan v2](../plans/sc-m05-professional-widget-experience-plan-v2.md) §10 (WP1–WP8):

- operator-configurable **widget presentation settings** — support **title**, opening
  **greeting**, and an optional **Media Library image avatar**;
- a **professional circular launcher**, **desktop panel**, **mobile full-screen sheet**, with
  **RTL** mirroring and **`prefers-reduced-motion`** handling;
- **non-modal dialog** behaviour with defined **focus management**;
- repository-code-only version increase **`0.6.0 → 0.7.0`**;
- the plan §9 automated tests and browser QA evidence.

## Gates and SHAs

| Gate | SHA / URL |
|---|---|
| SC-M05 design freeze (plan v2 + ADR-0016 as Proposed) | `76c5113db456e2586436dab73f2138be4e93dff6` — [PR #46](https://github.com/magpern/universal-support-chat/pull/46) |
| Product Owner implementation acceptance (ADR-0016 → Accepted) | `a2708f64ea8112158b982bdc2e1872d2ff317ed6` — [PR #47](https://github.com/magpern/universal-support-chat/pull/47) (`docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`) |
| Implementation PR | [PR #48](https://github.com/magpern/universal-support-chat/pull/48) |
| Implementation branch | `feature/sc-m05-professional-widget-experience` |
| First implementation push (reviewed) | `e997d2546f26761c7d80bbf4825eddff52e60b75` |
| Review-fix head (reviewed, merged content) | `59b50bf064167ff25a0aa6b11fc13d1e5f845ade` |
| **Squash-merge commit on `main`** | **`ceb5284fe51c1f37a52895b4f43ed422376ef902`** |

Both baselines are verified ancestors of `origin/main` (`76c5113` and `a2708f6` precede the
merge commit `ceb5284`). ADR-0016 is **Accepted**; plan v2 is frozen and its authorization
line cites the PO-acceptance record.

## What shipped

### Widget presentation settings (WP1)

`src/Core/Configuration/Settings.php` — `defaults()` and `sanitize()` extended from six to
**nine** keys, fixed-shape preserved; the six existing keys are byte-identical in behaviour.
The three additive keys:

| Key | Type / rule | Default |
|---|---|---|
| `widget_title` | plain text, `sanitize_text_field()`, `mb_substr` cap **80**; empty renders the translated fallback **"Support chat"** | `''` |
| `widget_greeting` | plain multiline, `sanitize_textarea_field()` (newlines kept), `mb_substr` cap **500** | `Hi — how can we help?` (a plain literal `Settings::DEFAULT_WIDGET_GREETING` — `register()` runs on `plugins_loaded`, before translations load; the string is translated at render, see WP4) |
| `widget_avatar_attachment_id` | integer; **any value `< 1` is rejected before casting** (so `-5` never becomes attachment `5`), then `wp_attachment_is_image()` must pass, else `0` | `0` (none) |

`src/ChatWidget/WidgetPresentation.php` (new) — a `final` value object resolving
`title()` (translated "Support chat" fallback), `greeting()` (translated default at render;
custom text passed through), and `avatar_image_url()` (server-side, via
`wp_get_attachment_image_url( id, 'thumbnail' )`, with an injectable resolver seam; returns
`''` — never a broken image — when the id is `0` or no longer an image).

No new option, no schema change, no `universal_support_chat_db_version` change (stays **12**),
no REST route/field/permission change, no new capability.

### Launcher, panel, mobile sheet, RTL, reduced motion (WP2 / WP3)

- `src/ChatWidget/WidgetAssets.php` — `render_shell()` emits a **circular launcher button**
  carrying two hand-authored inline SVGs (`data-usc-icon="bubble"` / `"close"`,
  `aria-hidden="true"`, `focusable="false"`), `aria-haspopup="dialog"`; a header with the
  server-rendered `<h2 id="usc-chat-title">` (`esc_html( $presentation->title() )`) and an
  optional decorative `<img class="usc-chat__avatar" alt="">` emitted **only** when the avatar
  URL resolves; a new `<div id="usc-chat-intro">` block; an icon-only close button.
  `enqueue()` adds only `greeting` and one `i18n.loading` string to the localized payload —
  **not** the title or the avatar URL.
- `assets/css/chat-widget.css` — full restyle. `.usc-chat` declares a `--usc-*` custom-property
  palette (`--usc-accent: #0b57d0`, contrast, surface, text, muted, error, border, radius),
  **every use carrying a hardcoded fallback** (`background: #0b57d0; background: var(--usc-accent, #0b57d0)`).
  Circular 56px launcher (52px ≤480px), CSS-only icon morph toggled by `[aria-expanded]`.
  Desktop card panel; `@media (max-width: 480px)` full-screen sheet (`position: fixed; inset: 0;
  height: 100dvh; border-radius: 0`) with a sticky header and `[data-has-messages] .usc-chat__intro { display: none }`.
  `[dir="rtl"] .usc-chat { right: auto; left: 1rem }` mirror plus logical properties.
  `@media (prefers-reduced-motion: reduce)` sets `transition: none` on launcher, icons, and
  panel and `transform: none` on the icons — an instant icon swap, no motion.
- `assets/js/settings-media.js` (new) — ES5 `wp.media` image-only picker for the avatar field;
  `media-editor` dependency; "Remove" writes `0`.

### Non-modal dialog behaviour and focus management (WP2 / WP4)

- The panel is `role="dialog"` with **no `aria-modal`** and **no Tab focus trap**;
  `aria-labelledby="usc-chat-title"` and `aria-describedby="usc-chat-intro"`.
- `assets/js/chat-widget.js` — the greeting is written to `#usc-chat-intro` via `.textContent`
  **during init, before the panel can open**, so the description resolves to real text on
  first open. On open, focus moves to the **close button**; on close and on **Escape**, focus
  returns to the **launcher**. Keyboard focus may leave the dialog to the page normally (no
  wrap, no trap). No `innerHTML`, no style writes.
- Mobile ≤480px hides the intro once messages exist (`data-has-messages` flag); desktop
  retains it.

### Settings UI (WP5)

`src/Administration/Settings/SupportChatSettingsPage.php` — a new **"Widget presentation"**
section on the existing ADR-0015 Settings page with the three controls (text title, textarea
greeting, image-only avatar picker with preview + Choose / Remove). The `wp.media` assets and
`settings-media.js` are enqueued **only on the Settings page hook** (`admin_enqueue_scripts`
guarded against `$this->hook_suffix`), with a `media-editor` dependency. No new menu, page, or
capability; no colour / theming UI.

### Version (WP6)

`universal-support-chat.php` — `Version: 0.7.0` header and
`define( 'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.7.0' )` (from `0.6.0`), for asset cache-busting.
**In repository code only** — no `git` tag, no GitHub Release.

### Review fixes carried in `59b50bf`

Round-1 review raised two implementation defects and one incomplete QA gate; all three are
resolved in `59b50bf` (the merged content):

1. **Negative avatar IDs** — `Settings::image_attachment_id()` no longer calls `absint()` on
   the raw value. It returns `0` for any value `< 1` **before** casting, so `-5` can never
   resolve to attachment `5`. Regression:
   `tests/integration/Core/Configuration/SettingsAvatarValidationTest.php::test_negative_of_a_real_image_attachment_id_becomes_zero`
   (uploads a real image; asserts `-<id>` as int and as string both sanitise to `0`).
2. **Close-during-bootstrap focus protection** — `openPanel()` captures an `openSession`
   token; `closePanel()` and Escape bump it; every post-bootstrap `.then()` / `.catch()`
   callback bails on `session !== openSession || !open`, so a late `input.focus()` from an
   in-flight `ensureConversation() → poll()` chain can no longer pull focus into a closed
   panel. Regression: `WidgetAssetsTest` source-scan plus a deterministic real-browser check
   (stall `POST /conversations`, press Escape, release the route, assert `activeElement` is the
   launcher and the panel stays hidden).
3. **`DOMContentLoaded` initialization fix** — `chat-widget.js` previously ran as a bare IIFE
   at parse time, but the shell markup prints on `wp_footer` priority 30, **after**
   `wp_print_footer_scripts` (priority 20), so `getElementById('usc-chat-root')` was `null`
   and the widget never initialised on a normal page. The body is now wrapped in `init()`,
   deferred via `document.readyState === 'loading' ? addEventListener('DOMContentLoaded', init) : init()`.
   Regression: `WidgetAssetsTest::test_js_defers_init_until_the_shell_dom_exists`. (This also
   corrected a latent SC-M02 ordering bug.)

Also folded in: `Settings::defaults()['widget_greeting']` is a plain literal rather than
`__()` (avoids the `_load_textdomain_just_in_time` `_doing_it_wrong` notice on `plugins_loaded`);
the default is translated at render in `WidgetPresentation::greeting()`, consistent with
`title()`.

## Files landed (16; squash `ceb5284`)

**New source**
- `src/ChatWidget/WidgetPresentation.php`
- `assets/js/settings-media.js`

**Modified source**
- `src/Core/Configuration/Settings.php`
- `src/ChatWidget/WidgetAssets.php`
- `src/Administration/Settings/SupportChatSettingsPage.php`
- `assets/js/chat-widget.js`
- `assets/css/chat-widget.css`
- `universal-support-chat.php` (version only)

**Tests**
- new `tests/unit/ChatWidget/WidgetPresentationTest.php`
- new `tests/integration/ChatWidget/WidgetShellRenderTest.php`
- new `tests/integration/Core/Configuration/SettingsAvatarValidationTest.php`
- updated `tests/unit/ChatWidget/WidgetAssetsTest.php` (CSS / JS string-scans)
- updated `tests/unit/Core/Configuration/SettingsTest.php`
- updated `tests/integration/Administration/Settings/SupportChatSettingsPageTest.php`
- updated `tests/unit/bootstrap.php` (minimal WP-function polyfills for the pure-PHP unit context)

**Docs**
- `docs/milestones/README.md` (SC-M05 status line)

## Tests and CI

**PR #48 CI — all green** (10 jobs, run
[`33308557095`](https://github.com/magpern/universal-support-chat/actions/runs/33308557095) on
head `59b50bf`): PHPCS, PHPStan level 5, unit ×3 (PHP 8.1 / 8.3 / 8.4), integration
WordPress-only floor (WP 6.9 / PHP 8.1) and current (WP 7.1 / PHP 8.3), interop (6.9 / 8.1)
and interop (7.1 / 8.3) against the CI-pinned Universal Telegram commit
(`9b4a6ef2bfc56b4bb514567c797d41c8a285727a`), and check-doc-links.

**Local gates (fresh test database)** — PHPCS clean; PHPStan level 5 clean; unit green on
PHP 8.1 / 8.3 / 8.4; integration WP-only green on both WP 7.1 / PHP 8.3 and WP 6.9 / PHP 8.1;
interop green on both variants; check-doc-links clean.

New automated coverage includes: nine-key fixed-shape `sanitize()` / `defaults()`; title and
greeting tag-stripping and length caps; **negative and non-image avatar id → `0`** (with a
real uploaded image); `WidgetPresentation` title / greeting / avatar-URL resolution; the shell
markup (circular launcher, `data-usc-icon` SVGs, `role="dialog"` **without** `aria-modal`,
`aria-describedby`, `aria-haspopup`, decorative avatar `alt=""`, `<script>` in the title
rendered escaped); the localized payload carrying `greeting` (tag-free) but **not** the title
or avatar URL and **no `telegram` string or availability chrome**; the Settings section and
its controls; the page-hook-scoped media enqueue; and JS string-scans for `.textContent`
greeting init, the `DOMContentLoaded` defer, the open/close focus moves, the `openSession`
close-during-bootstrap guard, no Tab trap, no `innerHTML`, and no external asset/font URLs.

## Browser QA evidence

Executed against **local disposable Docker test infrastructure only** (a throw-away WordPress
container + a Playwright 1.62 container + axe-core + Lighthouse), with **only
`universal-support-chat` active** (Universal Telegram not installed). Evidence is recorded on
PR #48: [QA-evidence comment](https://github.com/magpern/universal-support-chat/pull/48#issuecomment-5468371757).

- **Engines:** Chromium **and** Firefox — 22/22 automated checks pass: circular 56/52px
  launcher; `role="dialog"` with no `aria-modal`; greeting present in `#usc-chat-intro` before
  interaction; focus → close button on open and → launcher on Escape in both engines; no Tab
  trap; close-before-bootstrap keeps focus on the launcher; mobile full-screen sheet with no
  horizontal scroll; `prefers-reduced-motion` → all transitions `0s`; RTL flip; 79-char title
  + 499-char greeting without overflow; visitor → Hub reply → poll renders the reply as
  "Support team"; no `telegram` string anywhere; no availability chrome.
- **axe-core — widget scope, widget open: 0 violations.**
- **Lighthouse — accessibility, widget open: 100.**

The disposable QA infrastructure was torn down after the run; no artifact is committed to the
repository.

## Post-merge recommended human AT validation

The frozen plan v2 §9 / §13 lists a **VoiceOver + NVDA screen-reader smoke** as WP7 evidence.
This environment has no screen-reader host, so that smoke **was not run** and is **not** claimed
as passed. The ARIA structure (`role="dialog"` + `aria-labelledby` + `aria-describedby` with
text present before open), axe-core (0 widget violations) and Lighthouse (accessibility 100)
address the same surface **indirectly but do not substitute** for a human AT pass.

**Recommended, post-merge:** a person runs VoiceOver (macOS / Safari) **or** NVDA
(Windows / Firefox) against a page with the widget enabled and non-default title + greeting,
and confirms the open widget announces its **title** and **greeting**, **Escape** returns
focus to the launcher, and keyboard navigation remains **non-modal without a Tab trap**. The
copy-paste checklist is on PR #48:
<https://github.com/magpern/universal-support-chat/pull/48#issuecomment-5469273912>.
Record the screen-reader + browser + OS versions and attach the result as the completing WP7
evidence.

This limitation does not block the merge (already done) and does not gate any deployment; it
is a quality follow-up.

## Explicit non-implementation / unchanged

Per ADR-0016, plan v2 §12, and the PO-acceptance record — none of the following was touched:

- **Universal Telegram / any Telegram dependency** — the interop run used a read-only
  worktree of Universal Telegram pinned at the CI ref `9b4a6ef`; the UT repository is
  untouched. No Telegram bot identity, availability claim, or dependency is surfaced in the
  widget.
- **AI / RAG** — no drafts, suggestions, provider, prompt, or automation.
- **REST / conversation model** — no new route, field, permission, or message type; visitor
  isolation and the authenticated-only path are unchanged.
- **Schema / migration** — `universal_support_chat_db_version` stays **12**;
  `Migrator::target_version()` is untouched.
- **Capabilities** — no new capability.
- **Options** — no new option; the `universal_support_chat_settings` array gains three keys
  and stays fixed-shape (nine keys). The six pre-existing keys are unchanged.
- **CI / dependencies / build tooling** — no workflow change; no Composer dependency change;
  no npm / bundler / JS test runner / browser CI introduced.
- **Availability / presence chrome** — none (deferred to SC-M06).

## Non-authorization

This closure authorizes nothing operational. The feature is merged to `main` at
`ceb5284fe51c1f37a52895b4f43ed422376ef902` but has **not** been deployed to DEV or production.
No plugin was activated, deactivated, or updated on any live site; no `wp option` value was
changed on any live site; no live setting or data operation occurred; no Telegram message,
webhook, bot, group, topic, pairing, or credential action occurred; no GitHub Release or
version tag was created. Deploying to DEV (and later production) is a separate,
explicitly-authorized step.

## Documents

- [SC-M05 charter](../milestones/sc-m05-professional-widget-experience.md)
- [Plan v2 — `sc-m05-professional-widget-experience-plan-v2.md`](../plans/sc-m05-professional-widget-experience-plan-v2.md) — frozen; authorization line cites the PO-acceptance record.
- [ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md) — Accepted.
- [`docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`](../decisions/sc-adr-0016-widget-presentation-po-acceptance.md) — Approved.
- [Feature PR #48](https://github.com/magpern/universal-support-chat/pull/48)

## Next milestone

SC-M06 (Support Availability and Offline Tickets) and the SC-AI track remain per the locked
execution order in [`docs/milestones/README.md`](../milestones/README.md). SC-M05 introduces
no dependency for them beyond what SC-M02 already established.
