# Closure — ADR-0015: Operator Settings page and Diagnostics separation

## Status

**Complete and merged to `main`. Not deployed.** Documentation-only closure record. No DEV or
production deployment, no setting change, no Telegram action, no release, no tag, no database
or data operation.

## Gates and SHAs

| Gate | SHA / URL |
|---|---|
| Design freeze (ADR-0015 + plan v1) | `f978ea5e46223215af2e2b27cf48a0facf81f28f` — [PR #42](https://github.com/magpern/universal-support-chat/pull/42) |
| Product Owner implementation acceptance | `9a304cdb24d34a94558e7a7c58ef908d010b7aaf` — [PR #43](https://github.com/magpern/universal-support-chat/pull/43) (`docs/decisions/sc-adr-0015-operator-settings-diagnostics-po-acceptance.md`) |
| Implementation PR | [PR #44](https://github.com/magpern/universal-support-chat/pull/44) |
| Implementation branch head (reviewed) | `04180f70cafb5dd1735052477f136878837a40a1` |
| **Squash-merge commit on `main`** | **`b56ea231a5cfd124cffe4b22d8e168742bcad283`** (merged 2026-08-29) |

ADR-0015 is **Accepted**; plan `docs/plans/sc-operator-settings-and-diagnostics-plan-v1.md` is
frozen and its authorization line points at the PO-acceptance record above.

## What shipped

### Admin structure (ADR-0015 §1)

The existing top-level **Support Chat** menu (`HubPage::SLUG = universal-support-chat-hub`,
`add_menu_page`, position 58) now owns exactly three submenus, all gated by the existing
`CapabilityRegistrar::MANAGE` (`universal_support_chat_manage`):

| Submenu | Slug | Change |
|---|---|---|
| **Conversations** | `universal-support-chat-hub` | `HubPage::add_menu()` adds an explicit `add_submenu_page( self::SLUG, … )` so the auto-cloned first child reads "Conversations", not "Support Chat". Hub inbox / detail / reply / notes are otherwise unchanged. |
| **Settings** | `universal-support-chat-settings` | New `Administration\Settings\SupportChatSettingsPage`. |
| **Diagnostics** | `universal-support-chat-diagnostics` | `Administration\Diagnostics\DiagnosticsPage` reparented from `add_options_page` to `add_submenu_page( HubPage::SLUG, … )`; slug changed from `universal-support-chat`; renamed "Diagnostics". |

No new top-level WordPress menu. `Settings → Support Chat Pairing`
(`ChannelContract\Admin\PairingPage`) is untouched.

### Settings page (ADR-0015 §2)

`SupportChatSettingsPage` renders a standard WordPress Settings API form (`settings_fields()` +
`do_settings_sections()` + `submit_button()`, POST to `options.php`) titled **"Support Chat
Settings"**, `MANAGE`-gated, reusing the existing `Settings::OPTION_GROUP`
(`universal_support_chat_settings_group`) and `Settings::OPTION_NAME`
(`universal_support_chat_settings`). It does **not** call `register_setting()` a second time.

**Exactly the six settings the plugin already owned**, in four sections:

| Section | Field | Stored key | Control |
|---|---|---|---|
| General | Enable chat widget | `widget_enabled` | checkbox + hidden `0` companion |
| Conversation lifecycle | Days of inactivity before a conversation is closed | `conversation_inactive_days` | number, `min=1` |
| Conversation lifecycle | Days after archiving before message bodies are cleared | `conversation_archived_body_days` | number, `min=1` |
| Conversation lifecycle | Days before a closed conversation is permanently purged | `conversation_purge_days` | number, `min=1` |
| Telegram adapter | Mirror new messages to the Telegram adapter | `telegram_dispatch_enabled` | checkbox + hidden `0` companion |
| Data removal | Remove all Support Chat data when the plugin is uninstalled | `remove_data_on_uninstall` | checkbox + hidden `0` companion |

- **Every checkbox has a hidden `<input type="hidden" … value="0">` companion** immediately
  before it, so the key is always present in the POST and an unchecked box submits `'0'`
  rather than an absent key. There is **no** hidden "preserve" field.
- **`remove_data_on_uninstall` is visible, in its own final "Data removal" section**, unchecked
  by default, under an explicit warning: it permanently deletes Support Chat plugin data
  **only if and when the plugin is later uninstalled**; deactivation, plugin updates, and
  **saving this page** never delete anything.
- **Capability filter registered synchronously in `register()`, not on `admin_init`**:
  `add_filter( 'option_page_capability_universal_support_chat_settings_group', fn() =>
  CapabilityRegistrar::MANAGE )` so `options.php` authorises the save with `MANAGE` (tightening
  from the WordPress default `manage_options`). `admin_init` only registers the sections and
  fields.
- **`Settings::sanitize()`, `Settings::defaults()`, and `positive_int()` are unchanged** — the
  file is not in the diff. Booleans coerce via `! empty()`; retention values coerce via the
  existing `positive_int()` (non-numeric or `< 1` → that field's current default). Submitting
  the form unchanged re-persists a byte-identical array.
- A small read-only Telegram status line (`Dispatch: enabled|disabled`, `Adapter pairing:
  not paired|paired|paired (disabled)|pairing expired|pairing revoked`) built only from
  `Settings::get()` and the Support-Chat-owned `channel_peers` row via
  `PeerRepository::find_by_peer_id( 'universal-telegram' )`. No pairing / credential / bot /
  destination / transport controls. Links to Diagnostics; a "View conversations →" link points
  at the Hub.

### Diagnostics (ADR-0015 §3)

`DiagnosticsPage` stays **read-only** — the rendered output contains no `<form>`, `<input>`,
or `<button>`.

**Retained:** plugin version, schema availability (with the `MigrationFailureCode` enum label
only when unavailable), credential-vault self-check (`ok` / `fail-closed`), recent audit row
count (last 5).

**Safe aggregates added:**
- Telegram dispatch `enabled` / `disabled` (from `Settings::get()`).
- Telegram adapter pairing state (`PeerRecord::pairing_state()`) and `usable` yes/no
  (`PeerRecord::is_usable()`), from the Support-Chat-owned `channel_peers` row — **no Universal
  Telegram database access**.
- Dispatch-outbox row counts by state (`DispatchOutboxRepository::count_by_state()` —
  documented "operational diagnostics only"), rendered as e.g. `pending: 3`.

**Redaction boundary (test-enforced):** the page never renders credentials, public keys, key
IDs, raw key material, URLs / routes (`outbound_route_base`), webhook data, tokens, message or
note content, conversation IDs or UUIDs, Telegram-native identifiers, peer row IDs, peer
timestamps, raw exception text, or stack traces. Only booleans, fixed enum labels, integer
counts, and the version string reach the page. `DiagnosticsPageTest` builds a peer carrying a
public key, key ID, outbound route, required capability, and an expiry, plus an outbox row
with a conversation UUID, renders the page, and asserts none of those strings — nor
`Exception` / `#0 ` markers — appear. A "Open Settings →" link points at the Settings page.

### Legacy URL and Plugins-row links (ADR-0015 §4)

- New `Administration\Compat\LegacySettingsRedirect` (`LEGACY_SLUG = 'universal-support-chat'`),
  registered on `admin_init`:
  - **Pure** `resolve_target( string $pagenow, string $page ): ?string` — no output, no
    redirect, no `exit`, no state change. Returns
    `admin_url( 'admin.php?page=universal-support-chat-diagnostics' )` **only** when
    `$pagenow === 'options-general.php'`, `$page === LEGACY_SLUG`, and
    `current_user_can( CapabilityRegistrar::MANAGE )`; `null` otherwise — an unauthorised user
    falls through to WordPress's own "not allowed" screen (no capability bypass), and the
    redirect target's own request yields `null` (loop-safe).
  - **Thin** `maybe_redirect()` — frozen as: `return` on `null`; otherwise
    `if ( wp_safe_redirect( $url, 302 ) ) { exit; }`. `exit` is reached only when
    `wp_safe_redirect()` returns truthy. `302`, never `301`.
  - The retired slug is **not** re-registered as an admin page.
- `PluginActionLinks`: the Plugins-screen **Settings** link now targets
  `admin.php?page=universal-support-chat-settings`; the **Conversations** link
  (`admin.php?page=universal-support-chat-hub`) is unchanged. `MANAGE`-gated as before.

### Composition

`Core\Plugin::init()` change is wiring only: it constructs `SupportChatSettingsPage`
(`$settings`, `$peers`), passes `$settings` / `$peers` / `$dispatch_outbox` into
`DiagnosticsPage`, constructs `LegacySettingsRedirect`, and registers the Hub before the
Settings and Diagnostics submenus.

### Unchanged (per ADR-0015 and the PO-acceptance record)

`Settings::sanitize()` / `defaults()`; database schema; `universal_support_chat_db_version`
(stays **12**); option names and defaults; capabilities; dispatch / pairing / Contract v1 /
widget / conversation behaviour; the ADR-0014 Amendment 1 **asynchronous dispatch
implementation** (no file under `src/TelegramDispatch/` or `src/ChannelContract/` was
touched); plugin version (`0.6.0`); Composer dependencies; Universal Telegram. No AI / RAG
work. No migration / cutover / legacy-engine work.

## Files landed (14; squash `b56ea23`)

**New source**
- `src/Administration/Settings/SupportChatSettingsPage.php`
- `src/Administration/Compat/LegacySettingsRedirect.php`

**Modified source**
- `src/Administration/Diagnostics/DiagnosticsPage.php`
- `src/Administration/Hub/HubPage.php`
- `src/Administration/PluginActionLinks.php`
- `src/Core/Plugin.php`

**Tests** — new `tests/integration/Administration/AdminMenuStructureTest.php`,
`.../Settings/SupportChatSettingsPageTest.php`, `.../Compat/LegacySettingsRedirectTest.php`,
`.../Diagnostics/DiagnosticsPageTest.php`; updated
`tests/integration/Administration/PluginActionLinksTest.php`,
`tests/unit/Core/Configuration/SettingsTest.php`.

**Docs** — `docs/ARCHITECTURE.md` (Administration boundary row), `docs/milestones/sc-m05-professional-widget-experience.md` (planning note).

## CI and admin evidence

**PR #44 CI — all green** (10 checks, run
[`33266700821`](https://github.com/magpern/universal-support-chat/actions/runs/33266700821)):
PHPCS, PHPStan, unit ×3 (PHP 8.1 / 8.3 / 8.4), integration WordPress-only floor (WP 6.9 /
PHP 8.1) and current (WP 7.1 / PHP 8.3), interop (6.9, 8.1) and interop (7.1, 8.3) — both
against the CI-pinned Universal Telegram commit — and check-doc-links. Head
`04180f70…` unchanged between review and merge; no review threads, no comments.

**Local gate (fresh test database):** PHPCS clean; PHPStan clean; unit `OK (68 tests, 191
assertions)` on PHP 8.1 / 8.3 / 8.4; integration `OK (155 tests, 605 assertions)` on both
WP 7.1 / PHP 8.3 and WP 6.9 / PHP 8.1; check-doc-links clean.

**New test coverage:** `SupportChatSettingsPageTest` (17) — submenu under the Hub with
`MANAGE`, capability filter present immediately after `register()` (before `admin_init`) and
returning `MANAGE`, fields registered only after `admin_init`, `settings_fields()` nonce +
option group + all six keys rendered, a hidden `0` before every checkbox, no hidden preserve
field, Data-removal section label + warning, checkbox reflects stored value / defaults off,
saving the option (even with `remove_data_on_uninstall => '1'`) drops no table and runs no
uninstall path, capless user `wp_die`n, Telegram panel shows dispatch + pairing and leaks no
key / key ID / route. `DiagnosticsPageTest` (11) — new slug + Hub parent, no form/input, safe
aggregates render, redaction assertions, unavailable-schema enum label.
`LegacySettingsRedirectTest` (8) — `resolve_target()` for the authorised legacy URL,
unauthorised user, unrelated requests, and the loop-target request; a `wp_redirect`-filter
wrapper test that returns `false` and proves `maybe_redirect()` returns without `exit`.
`AdminMenuStructureTest` (7) — exactly one Support Chat top-level, three submenus in order,
no new top-level, `MANAGE` on Settings/Diagnostics, legacy slug not registered, capability
filter live.

**Admin-view evidence** was generated from the WP 7.1 / PHP 8.3 integration environment
(administrator user; dispatch on; adapter paired; three queued outbox rows) by rendering each
page's real `render()` output:

- **Menu:** `[universal-support-chat-hub] Support Chat` → `Conversations`
  (`universal-support-chat-hub`), `Settings` (`universal-support-chat-settings`), `Diagnostics`
  (`universal-support-chat-diagnostics`), all `cap=universal_support_chat_manage`.
- **Settings:** the four sections render with the widget + dispatch checkboxes each preceded by
  a `value="0"` hidden input, retention inputs at `30 / 30 / 90` with descriptions, the
  Telegram status line (`Dispatch: enabled`, `Adapter pairing: paired`), and the Data-removal
  section showing the full warning paragraph and an **unchecked** `remove_data_on_uninstall`
  checkbox. The form carries `option_page = universal_support_chat_settings_group` and the
  `_wpnonce`.
- **Diagnostics:** `Plugin version 0.6.0`, `Schema available yes`, `Vault self-check ok`,
  `Recent audit rows (last 5) 1`, `Telegram dispatch enabled`, `Telegram adapter pairing
  paired`, `Telegram adapter usable yes`, `Dispatch outbox (rows by state) pending: 3`, plus
  an "Open Settings →" link — no form, no input.

The generated evidence lives with the implementation session's working files; the composed
review page could not be published as a hosted artifact and is not part of the repository.

## Documents

- [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) — Accepted.
- [`docs/plans/sc-operator-settings-and-diagnostics-plan-v1.md`](../plans/sc-operator-settings-and-diagnostics-plan-v1.md) — frozen; authorization line references the PO-acceptance record.
- [`docs/decisions/sc-adr-0015-operator-settings-diagnostics-po-acceptance.md`](../decisions/sc-adr-0015-operator-settings-diagnostics-po-acceptance.md) — Approved.

## Non-authorization

This closure authorizes nothing operational. The feature is merged to `main` at
`b56ea231a5cfd124cffe4b22d8e168742bcad283` but has **not** been deployed to DEV or production.
No plugin was activated, deactivated, or updated on any live site; no `wp option` value was
changed on any live site; no Telegram message, webhook, bot, group, topic, destination,
pairing, or credential action occurred; no GitHub Release or version tag was created; no
database or data operation was performed. Deploying to DEV (and later production) is a separate,
explicitly-authorized step.
