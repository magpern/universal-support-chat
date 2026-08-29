# ADR-0015: Operator-facing Support Chat Settings page, and separation of read-only Diagnostics

## Status

**Proposed** — documentation freeze. Surfaces configuration the plugin **already owns**
through the WordPress Settings API and reorganises the existing admin surface. **No** new
option, **no** new capability, **no** schema change (`universal_support_chat_db_version`
stays at `12`), **no** default change, **no** retention or uninstall semantics change, **no**
Contract v1 change, **no** Universal Telegram change, **no** message-delivery / queue /
priority change. No plugin version tag, GitHub Release, DEV change, or production change is
part of this decision. Implementation is authorized only from the merged freeze baseline, per
the companion plan `docs/plans/sc-operator-settings-and-diagnostics-plan-v1.md`.

## Context

`UniversalSupportChat\Core\Configuration\Settings` is the sole owner of the single option
array `universal_support_chat_settings` and already registers it with the Settings API
(group `universal_support_chat_settings_group`). It holds six keys, every one of which is
already read by live runtime code:

| Key | Default | Read by |
|---|---|---|
| `widget_enabled` | `true` | `ChatWidget\WidgetAssets` (enqueue + shell gate) |
| `conversation_inactive_days` | `30` | `Conversations\RetentionCleanupHandler` |
| `conversation_archived_body_days` | `30` | `Conversations\RetentionCleanupHandler` |
| `conversation_purge_days` | `90` | `Conversations\RetentionCleanupHandler` |
| `telegram_dispatch_enabled` | `false` | `TelegramDispatch\DispatchEnqueuer::is_enabled()`, `TelegramDispatchService::is_enabled()` |
| `remove_data_on_uninstall` | `false` | `Core\Lifecycle\Uninstaller` (only at `WP_UNINSTALL_PLUGIN`) |

**No form anywhere renders these.** The only screen an operator sees under
`Settings → Support Chat` is `Administration\Diagnostics\DiagnosticsPage` — a read-only
status table (plugin version, schema availability, credential-vault self-check, recent audit
row count) registered at `add_options_page( … 'universal-support-chat' … )`. There is no way
to toggle the widget, toggle Telegram dispatch, or adjust retention without WP-CLI or a
direct `update_option`.

The current admin menu surface is:

- Top-level **Support Chat** — `Administration\Hub\HubPage` (slug
  `universal-support-chat-hub`), the operator inbox / conversation detail / reply / notes.
- `Settings → Support Chat` — `DiagnosticsPage` (slug `universal-support-chat`), read-only.
- `Settings → Support Chat Pairing` — `ChannelContract\Admin\PairingPage` (slug
  `universal-support-chat-pairing`) — the Contract v1 pairing admin, unaffected here.
- Plugins-screen row links (`Administration\PluginActionLinks`): **Conversations** →
  `admin.php?page=universal-support-chat-hub`; **Settings** →
  `options-general.php?page=universal-support-chat` (i.e. today the "Settings" link opens the
  read-only Diagnostics table).

The single capability `CapabilityRegistrar::MANAGE = 'universal_support_chat_manage'` gates
every Support Chat admin screen and is granted to `administrator`.

A read-only Telegram signal already exists **in-process**, owned by Support Chat, with no
Universal Telegram database access: `ChannelContract\Auth\PeerRepository::find_by_peer_id(
'universal-telegram' )` → `PeerRecord::pairing_state()` / `is_usable()`; and
`TelegramDispatch\DispatchOutboxRepository::count_by_state()` (its own source comment:
"operational diagnostics only").

The problem: an operator has no usable settings screen, and the screen that *is* labelled
"Settings" is a diagnostics table. This ADR fixes the admin information architecture without
adding product surface.

## Decision

### 1. Admin structure — one product home, no new top-level menu

All three screens live under the **existing** top-level `Support Chat` menu
(`HubPage::SLUG`). No new top-level menu is registered.

```
Support Chat  (existing top-level — HubPage)
├── Conversations   → universal-support-chat-hub          (explicit first-submenu label)
├── Settings        → universal-support-chat-settings     (NEW — real operator settings)
└── Diagnostics     → universal-support-chat-diagnostics  (existing read-only material, reparented)
```

- `HubPage::add_menu()` adds an explicit `add_submenu_page( self::SLUG, …,
  'Conversations', … self::SLUG, … )` so the first child reads "Conversations", not the
  auto-cloned "Support Chat".
- `DiagnosticsPage` is reparented from `add_options_page()` to
  `add_submenu_page( HubPage::SLUG, … )`; its slug changes
  `universal-support-chat` → `universal-support-chat-diagnostics`.
- Plugins-screen links after this change: **Conversations** → Hub (unchanged); **Settings**
  → the new `universal-support-chat-settings` page.
- The `Support Chat Pairing` options page is out of scope and unchanged.

### 2. Settings page — existing configuration only

A new `Administration\Settings\SupportChatSettingsPage` (slug
`universal-support-chat-settings`), rendered with the standard WordPress Settings API
(`settings_fields()` + `do_settings_sections()` + `submit_button()`, POST to `options.php`),
gated by `CapabilityRegistrar::MANAGE`, titled **"Support Chat Settings"**.

It exposes **exactly** the six existing keys and nothing else, in four clearly separated
sections:

| Section | Field label | Control | Stored key |
|---|---|---|---|
| General | Enable chat widget | checkbox + hidden `0` companion | `widget_enabled` |
| Conversation lifecycle | Days of inactivity before a conversation is closed | number input, `min=1` | `conversation_inactive_days` |
| Conversation lifecycle | Days after archiving before message bodies are cleared | number input, `min=1` | `conversation_archived_body_days` |
| Conversation lifecycle | Days before a closed conversation is permanently purged | number input, `min=1` | `conversation_purge_days` |
| Telegram adapter | Mirror new messages to the Telegram adapter | checkbox + hidden `0` companion | `telegram_dispatch_enabled` |
| Data removal | Remove all Support Chat data when the plugin is uninstalled | checkbox + hidden `0` companion | `remove_data_on_uninstall` |

- **Option group / capability.** The page reuses `Settings::OPTION_GROUP`
  (`universal_support_chat_settings_group`) and `Settings::OPTION_NAME`. It does **not**
  call `register_setting()` a second time (that stays in `Settings::register()`).
  `SupportChatSettingsPage::register()` adds the
  `option_page_capability_universal_support_chat_settings_group` filter **immediately, in
  `register()` itself — not inside an `admin_init` callback** — returning
  `CapabilityRegistrar::MANAGE`, so `options.php` authorises the save with `MANAGE` rather
  than the default `manage_options`. `admin_init` is used only to register the sections and
  fields.
- **Nonce.** Standard Settings API handling — `settings_fields()` emits the core nonce for
  action `universal_support_chat_settings_group-options`; `options.php` verifies it. No
  custom nonce.
- **Validation / sanitisation / defaults are unchanged.** `Settings::sanitize()` and
  `Settings::defaults()` are not modified. Booleans coerce via `! empty()`; retention values
  coerce via the existing `positive_int()` (non-numeric or `< 1` falls back to that field's
  current default).
- **Every key is always present in the submitted form.** Each checkbox has a hidden `0`
  companion immediately before it; retention values are explicit number inputs. There is
  **no** hidden "preserve" field. Submitting the form unchanged re-persists a byte-identical
  array — no key is dropped, no boolean silently flips.
- **`remove_data_on_uninstall` is a visible, labelled, final "Data removal" setting**, not
  an invisible passenger. Default off. Its help text states plainly that enabling it causes
  permanent deletion of Support Chat plugin data **only if the plugin is later uninstalled**,
  and that saving the form, updating the plugin, or deactivating it never deletes anything.
  Its sole consumer — `Core\Lifecycle\Uninstaller` at `WP_UNINSTALL_PLUGIN` — is unchanged.
  No confirmation dialogue, no JavaScript.
- **Navigation.** The page links out to Conversations; the Telegram adapter section links to
  Diagnostics for the fuller status view.

### 3. Diagnostics — retained, read-only, separately named

`DiagnosticsPage` keeps its existing content and read-only nature (no `<form>`, no
`<input>`), now under the name **Diagnostics**:

- **Retained:** plugin version; schema availability (and, when unavailable, the
  `MigrationFailureCode` enum label only); credential-vault self-check (`ok` / `fail-closed`
  from the existing encrypt/decrypt probe); recent audit row count.
- **Added — safe aggregates only:**
  - Telegram dispatch `enabled` / `disabled` (from `Settings::get()`).
  - Telegram adapter pairing state and usability, from
    `PeerRepository::find_by_peer_id( 'universal-telegram' )` →
    `PeerRecord::pairing_state()` (`not paired` / `paired` / `paired (disabled)` /
    `pairing expired` / `pairing revoked`) and `is_usable()`.
  - Dispatch-outbox row counts grouped by state, from
    `DispatchOutboxRepository::count_by_state()`.
- **Redaction boundary — Diagnostics never renders:** credentials, public keys, key IDs,
  raw key material, URLs / routes (`outbound_route_base`), webhook data, tokens, message or
  note content, conversation IDs or UUIDs, Telegram-native identifiers, peer row IDs, peer
  timestamps, raw exception text, stack traces, or `mark_failed` / delivery failure-reason
  strings. Only booleans, fixed enum labels, integer counts, and the version string reach
  the page.
- Diagnostics links to the Settings page.

### 4. Legacy Settings-URL compatibility

The previously deployed Plugins-row "Settings" link points at
`options-general.php?page=universal-support-chat`. That URL must keep working after the slug
move. A new `Administration\Compat\LegacySettingsRedirect`, registered on `admin_init`:

- **`resolve_target( string $pagenow, string $page ): ?string`** is **pure** — no output,
  no redirect, no `exit`, no state change. It returns
  `admin_url( 'admin.php?page=universal-support-chat-diagnostics' )` **only** when
  `$pagenow === 'options-general.php'`, `$page === self::LEGACY_SLUG`
  (`'universal-support-chat'`), and `current_user_can( CapabilityRegistrar::MANAGE )`;
  otherwise `null` (an unauthorised user gets `null`, so WordPress renders its own
  permission screen — **no capability bypass**; the redirect target request itself yields
  `null`, so no loop can form).
- **`maybe_redirect()`** is frozen as:

  ```php
  $url = $this->resolve_target( $pagenow, $page );

  if ( null === $url ) {
      return;
  }

  if ( wp_safe_redirect( $url, 302 ) ) {
      exit;
  }
  ```

  A `302` (temporary) is used, never a `301`. `exit` runs only when `wp_safe_redirect()`
  returns truthy.
- The old slug is **not** re-registered as an admin page.

## Alternatives

- **Keep both screens under the WordPress `Settings` menu (two `add_options_page` entries).**
  Rejected: it splits the product across the `Settings` menu and the top-level `Support
  Chat` menu and adds a second `Settings`-menu row. One operational home is clearer for a
  premium plugin, and the top-level `Support Chat` menu already exists, so nothing new is
  added.
- **Add a new top-level "Support Chat Settings" menu.** Rejected: forbidden by the scope of
  this change and by the desire to keep the admin surface minimal; a submenu of the existing
  menu is the simplest native arrangement.
- **Carry `remove_data_on_uninstall` through the form as a hidden field to "preserve" it.**
  Rejected on review: a premium operator interface must not move a destructive setting
  invisibly through a form. Every key becomes an explicit control instead.
- **Register the `option_page_capability_*` filter inside `admin_init`.** Rejected: fragile
  ordering relative to `options.php`. Registering it in `register()` at page-construction
  time removes the ordering risk entirely.
- **Turn the old `options-general.php?page=universal-support-chat` URL into a 404 / "invalid
  page" screen and document the move.** Rejected: the URL is in a shipped build's Plugins-row
  link; breaking it is a visible regression. A capability-checked 302 to Diagnostics
  preserves it.
- **Add a live Telegram health probe ("healthy" / "unhealthy").** Rejected: the plugin has
  no such probe. Diagnostics reports only objectively available in-process facts — pairing
  state, usability, dispatch flag, outbox counts — and avoids the word "healthy".
- **Expose a retention "dry-run" or a manual purge button.** Rejected: new product surface,
  out of scope.

## Consequences

- An operator can enable/disable the widget, enable/disable Telegram mirroring, and adjust
  the three retention periods from a real screen, using native Settings API conventions.
- The read-only status material is still available, now honestly named "Diagnostics" and
  co-located with the rest of the product under one menu.
- `remove_data_on_uninstall` becomes discoverable and clearly explained instead of being an
  undocumented option only reachable via WP-CLI.
- The Plugins-row "Settings" link finally opens a settings screen; the old link URL still
  resolves (302 → Diagnostics) for authorised users.
- One new admin page class, one reparented page, one small compat class, one relabelled Hub
  submenu, and one retargeted Plugins-row link. No runtime data path changes.
- Any bookmark or external deep link to `options-general.php?page=universal-support-chat`
  now lands on Diagnostics rather than a settings-looking table — which is what that URL
  actually showed before.

## Security and privacy impact

- **No new capability; no capability relaxation.** Every screen and the save path are gated
  by `CapabilityRegistrar::MANAGE`. The `option_page_capability_*` filter *tightens* the
  save from the WordPress default `manage_options` to `MANAGE` (same holder set today, but
  explicit and correct).
- **No new data stored or exposed.** The Settings form reads and writes the existing option
  through the existing `Settings::sanitize()`. Diagnostics gains only booleans, fixed enum
  labels, and integer counts, all from Support-Chat-owned in-process repositories — never
  from Universal Telegram's database.
- **Redaction boundary is explicit** (Decision §3) and covered by tests: no credentials,
  keys, key IDs, routes, tokens, webhook data, content, identifiers, timestamps, or raw
  error strings on the Diagnostics page.
- **The legacy redirect cannot be used to bypass authorisation** — `resolve_target()`
  returns `null` for a user without `MANAGE`, leaving WordPress to render its standard
  denial. It is loop-safe by construction.
- **No destructive action on save.** `remove_data_on_uninstall` is only read by the
  uninstall routine; toggling it and saving, or deactivating/updating the plugin, deletes
  nothing.

## Affected Documents/Milestones

- `docs/plans/sc-operator-settings-and-diagnostics-plan-v1.md` — this change's implementation
  plan (companion to this ADR).
- `docs/ARCHITECTURE.md` — the Administration boundary row is updated to note the Settings
  page alongside Diagnostics and the Hub.
- `docs/adr/README.md`, `docs/plans/README.md` — index entries.
- `docs/milestones/sc-m05-professional-widget-experience.md` — a planning-only note that the
  operator-facing widget/dispatch/retention toggles now have a real settings home
  (SC-M05's widget UX work is otherwise unaffected and not started here).
- ADR-0012, ADR-0014 — unaffected; the Telegram dispatch outbox, the `interactive_chat`
  delivery class, and the async expedited worker are untouched. This ADR only *reads*
  `telegram_dispatch_enabled` and the outbox state counts for display.
- ADR-0003 (security/privacy), ADR-0006 (adapter failure model) — the redaction boundary and
  the "fail closed for the channel only" posture are respected, not changed.

## Compatibility/Migration Impact

- **No schema version change.** `universal_support_chat_db_version` stays at `12`. No table
  is created, altered, dropped, or reinterpreted.
- **No option, default, or sanitisation change.** `Settings::OPTION_NAME`,
  `Settings::OPTION_GROUP`, `Settings::defaults()`, `Settings::sanitize()`, and
  `positive_int()` are byte-identical. Installs with no option row keep
  `30 / 30 / 90 / widget on / dispatch off / uninstall-wipe off`.
- **No behaviour change until an operator deliberately saves.** No option write happens on
  page load. `WidgetAssets`, `DispatchEnqueuer`, `TelegramDispatchService`,
  `RetentionCleanupHandler`, and `Uninstaller` read exactly what they read today.
- **`remove_data_on_uninstall` semantics unchanged** — read only at `WP_UNINSTALL_PLUGIN`;
  never on save, deactivate, or update.
- **Menu / navigation changes are pure routing.** `DiagnosticsPage` keeps its content (slug
  renamed `universal-support-chat` → `universal-support-chat-diagnostics`, parent changed
  to the Hub); `PluginActionLinks` retargets one URL; `HubPage` relabels its first child.
  No route handler, REST endpoint, or Contract surface changes.
- **The legacy Settings URL keeps resolving** — `options-general.php?page=universal-support-chat`
  issues a capability-checked `302` to the Diagnostics page for a `MANAGE` user, and
  WordPress's standard permission screen for anyone else. It is never turned into an admin
  error page.
- **No plugin version tag, GitHub Release, DEV change, or production change** is part of this
  decision. A downgrade to a pre-ADR-0015 build simply restores the previous menu layout;
  the option array on disk is identical either way.
