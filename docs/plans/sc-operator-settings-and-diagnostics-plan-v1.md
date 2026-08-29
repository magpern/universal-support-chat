# Plan: Operator-facing Support Chat Settings page, and separation of read-only Diagnostics (v1)

Realises [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md). Frozen
code-free at `main` `f978ea5e46223215af2e2b27cf48a0facf81f28f` (PR #42). The frozen design
below is unchanged.

**Implementation authorized** — 2026-08-29, by Product Owner implementation acceptance
[`docs/decisions/sc-adr-0015-operator-settings-diagnostics-po-acceptance.md`](../decisions/sc-adr-0015-operator-settings-diagnostics-po-acceptance.md),
for the five work packages in §10 only, exactly within this plan's frozen scope. Implementation
begins from `main` after that acceptance record merges; the implementation branch and PR must
cite both the freeze commit above and the acceptance record's merge commit.

## 1. Charter and ADR references

- **ADR introduced:** [ADR-0015 — Operator-facing Support Chat Settings page, and separation
  of read-only Diagnostics](../adr/0015-operator-settings-page-and-diagnostics-separation.md).
- **ADRs relied on (unchanged):** ADR-0002 (identity / ownership boundaries — adapters own
  bots/credentials/routes/identifiers), ADR-0003 (security, privacy, visitor isolation),
  ADR-0005 (Contract v1 — not touched), ADR-0006 (adapter failure model — "fail closed for
  the channel only"), ADR-0012 (SC-owned Telegram dispatch outbox — read-only here),
  ADR-0014 + Amendment 1 (`interactive_chat` delivery class, async expedited worker — read-only
  here).
- **Product boundary:** the Administration boundary (`UniversalSupportChat\Administration`),
  already Implemented for Diagnostics + Hub. This plan is a post-freeze feature, not a new
  milestone; it adds no boundary and no capability. It gives the operator-facing toggles that
  [SC-M05](../milestones/sc-m05-professional-widget-experience.md) and SC-M06 assume a home
  for, without doing that milestone work.

## 2. Repository findings (at plan-drafting time)

Verified against `origin/main` @ `c9ea0d2`. The commits since the ADR-0014 Amendment 1 work
(`530e84a`, `4bf012a`, `515c4fe`, `c9ea0d2`) touched only
`AdapterContractClient`, `Plugin`, `DispatchEnqueuer`, `DispatchWorker`,
`TelegramDispatchService`, and docs — **none** of the files this plan changes.

- **`src/Core/Configuration/Settings.php`** — sole owner of option
  `universal_support_chat_settings`; `OPTION_GROUP = 'universal_support_chat_settings_group'`.
  `register()` calls `register_setting()` (invoked from `Plugin::init()`). `defaults()`:
  `remove_data_on_uninstall=false`, `conversation_inactive_days=30`,
  `conversation_archived_body_days=30`, `conversation_purge_days=90`, `widget_enabled=true`,
  `telegram_dispatch_enabled=false`. `sanitize()` coerces booleans via `! empty()`;
  `widget_enabled` / `telegram_dispatch_enabled` fall back to their **default** when the input
  key is absent; retention values coerce via `positive_int()` (non-numeric or `<= 0` →
  field default). **No form renders this option today.**
- **`src/Administration/Diagnostics/DiagnosticsPage.php`** — `SLUG = 'universal-support-chat'`;
  `add_options_page( 'Universal Support Chat', 'Support Chat', CapabilityRegistrar::MANAGE,
  self::SLUG, [render] )` on `admin_menu`. `render()` guards `current_user_can( MANAGE )`,
  runs a vault encrypt/decrypt probe, and prints a `widefat` table: plugin version, schema
  available, vault self-check, recent audit row count (`count( $this->audit_repo->recent( 5 ) )`).
  Ctor: `SchemaHealth $schema_health, AuditLogRepository $audit_repo, CredentialVault $vault`.
- **`src/Administration/Hub/HubPage.php`** — `SLUG = 'universal-support-chat-hub'`;
  `add_menu_page( 'Support Chat Hub', 'Support Chat', MANAGE, self::SLUG, [render],
  'dashicons-format-chat', 58 )`. No explicit child submenu, so WordPress auto-creates a first
  child labelled "Support Chat".
- **`src/Administration/PluginActionLinks.php`** — row-specific
  `plugin_action_links_{basename}` filter. Prepends, for a `MANAGE` user:
  `usc-conversations` → `admin.php?page=` . `HubPage::SLUG`; `usc-settings` →
  `options-general.php?page=` . `DiagnosticsPage::SLUG`.
- **`src/Core/Capabilities/CapabilityRegistrar.php`** — `MANAGE =
  'universal_support_chat_manage'`, granted to `administrator`.
- **`src/ChatWidget/WidgetAssets.php`** — reads `Settings::get()['widget_enabled']` to gate
  enqueue + shell.
- **`src/TelegramDispatch/DispatchEnqueuer.php`** — `is_enabled()` →
  `Settings::get()['telegram_dispatch_enabled']`.
- **`src/TelegramDispatch/DispatchOutboxRepository.php`** — `count_by_state(): array<string,int>`
  ("operational diagnostics only"; SchemaHealth-gated; returns `state => count`, states
  `pending` / `delivering` / `delivered` / `failed` / `abandoned` / `suppressed`).
- **`src/ChannelContract/Auth/PeerRepository.php`** — `find_by_peer_id( string ): ?PeerRecord`
  (SchemaHealth-gated). **`PeerRecord`**: `pairing_state(): string` (`revoked` / `expired` /
  `paired_disabled` / `active`), `is_usable(): bool` (`active` && not expired). SC-owned
  `channel_peers` table — no Universal Telegram DB access.
- **`src/Persistence/Migrator.php`** — `target_version()` returns `12`.
- **`src/Core/Plugin.php`** — hand-wired composition root; already builds `$settings`,
  `$peers` (`PeerRepository`), `$dispatch_outbox` (`DispatchOutboxRepository`) and calls
  `$settings->register()`; registers `DiagnosticsPage`, `PluginActionLinks`, `HubPage`, etc.
- **Existing tests:** `tests/unit/Core/Configuration/SettingsTest.php`,
  `tests/integration/Administration/PluginActionLinksTest.php`,
  `tests/integration/Core/Capabilities/CapabilityRegistrarTest.php`,
  `tests/unit/ChatWidget/WidgetAssetsTest.php`, `tests/unit/Core/PluginTest.php`. No
  `DiagnosticsPage` test exists yet.

## 3. Assumptions and open questions

**Assumptions (carried as decisions unless a reviewer objects):**

- The WordPress Settings API works from an `admin.php?page=` submenu page: `settings_fields()`
  renders the group's nonce + `option_page` hidden field; the form POSTs to `options.php`,
  which is menu-independent. Verified by WordPress core behaviour.
- `options.php` resolves the option-page capability through the
  `option_page_capability_{group}` filter; registering that filter at page-construction time
  (in `register()`) guarantees it is present before `options.php` runs.
- Retention coercion feedback (surfacing "your value was clamped") is **not** required for
  this scope; the existing silent `positive_int()` behaviour is documented in field help text
  instead, keeping `Settings.php` untouched.
- `count_by_state()` returning the `suppressed` count is acceptable to display — it is a pure
  aggregate integer with no identifier content (ADR-0015 redaction boundary permits counts).

**Open questions for review:**

- Should the Diagnostics "recent audit rows" line keep its current `recent(5)` cap semantics
  (display "last 5"), or is a small additive `AuditLogRepository` count method wanted? This
  plan keeps the cap semantics (no repo change) unless a reviewer asks otherwise.
- Section ordering on the Settings page: proposed **General → Conversation lifecycle →
  Telegram adapter → Data removal** (destructive setting last). Confirm.

## 4. Architectural decisions (with alternatives / tradeoffs)

All decisions are frozen in [ADR-0015 §1–§4](../adr/0015-operator-settings-page-and-diagnostics-separation.md);
summarised here with the plan-level rationale.

1. **One product home under the existing top-level `Support Chat` menu**
   (Conversations / Settings / Diagnostics as submenus). Alternative — two `add_options_page`
   entries under WordPress `Settings` — rejected: splits the product and adds a `Settings`-menu
   row. Alternative — new top-level menu — rejected: out of scope and needless.
2. **Settings page reuses `Settings::OPTION_GROUP` / `OPTION_NAME`; does not re-register the
   setting.** The `option_page_capability_universal_support_chat_settings_group` filter is
   added in `SupportChatSettingsPage::register()` (not `admin_init`), returning
   `CapabilityRegistrar::MANAGE`. Alternative — filter in `admin_init` — rejected as
   ordering-fragile.
3. **`Settings.php` is not modified.** Validation/sanitisation/defaults stay exactly as
   today. Tradeoff: no inline "value clamped" notice; mitigated by field help text.
4. **Every option key is an explicit control** (checkbox + hidden `0` companion, or number
   input). No hidden "preserve" field. Alternative — hidden preserve field for
   `remove_data_on_uninstall` — rejected on review: a destructive setting must not travel
   invisibly through a form.
5. **`remove_data_on_uninstall` gets a visible "Data removal" section, last, with an explicit
   warning.** Semantics unchanged (read only at `WP_UNINSTALL_PLUGIN`).
6. **Diagnostics stays read-only, is reparented and renamed, and gains only safe aggregates**
   (dispatch flag, peer pairing state / usability, outbox state counts). Redaction boundary
   frozen in ADR-0015 §3. Alternative — a "healthy/unhealthy" Telegram probe — rejected: no
   such probe exists; only objective in-process facts are shown.
7. **Legacy URL compatibility via a pure `resolve_target()` + a thin `maybe_redirect()`
   handler** (ADR-0015 §4). The `302` never becomes a `301`; `exit` runs only when
   `wp_safe_redirect()` returns truthy, which keeps the handler unit-testable.

## 5. Directory, namespace, schema, and API impact (scoped)

**New files**

| Path | Purpose |
|---|---|
| `src/Administration/Settings/SupportChatSettingsPage.php` | Submenu page under `HubPage::SLUG`; Settings API form; `option_page_capability_*` filter in `register()`; four sections; explicit controls + hidden `0` companions; Telegram read-only panel; nav links. Ctor deps: `Settings`, `PeerRepository`. |
| `src/Administration/Compat/LegacySettingsRedirect.php` | Pure `resolve_target( string $pagenow, string $page ): ?string` + `admin_init` handler `maybe_redirect()` (frozen body, ADR-0015 §4). `LEGACY_SLUG = 'universal-support-chat'`. |

**Modified files**

| Path | Change |
|---|---|
| `src/Administration/Diagnostics/DiagnosticsPage.php` | `SLUG` → `universal-support-chat-diagnostics`; `add_options_page` → `add_submenu_page( HubPage::SLUG, __( 'Diagnostics' ), __( 'Diagnostics' ), MANAGE, self::SLUG, [render] )`; ctor gains `Settings`, `PeerRepository`, `DispatchOutboxRepository`; `render()` adds dispatch flag, pairing state + usability, outbox state counts, `MigrationFailureCode` label on unavailable schema, and an "Open Settings →" link. No `<form>`/`<input>`. |
| `src/Administration/Hub/HubPage.php` | `add_menu()` adds explicit `add_submenu_page( self::SLUG, __( 'Conversations' ), __( 'Conversations' ), MANAGE, self::SLUG, [render] )` so the first child is "Conversations". |
| `src/Administration/PluginActionLinks.php` | `usc-settings` link → `admin.php?page=` . `SupportChatSettingsPage::SLUG`; swap the `DiagnosticsPage` import for `SupportChatSettingsPage`. `usc-conversations` unchanged. |
| `src/Core/Plugin.php` | Construct `SupportChatSettingsPage( $settings, $peers )` and `LegacySettingsRedirect()` and `->register()` them; pass `$settings, $peers, $dispatch_outbox` into `DiagnosticsPage`. |
| `languages/universal-support-chat.pot` | New UI strings. |

**Schema:** none. `universal_support_chat_db_version` stays at `12`. No option added.

**API / contract:** none. No REST route, no Contract v1 operation, no capability added.

**Docs (index / reference only):** `docs/adr/README.md` (reserved table + index +
"next available" → 0016), `docs/plans/README.md` (post-freeze feature plans row),
`docs/ARCHITECTURE.md` (Administration boundary row), `docs/milestones/sc-m05-*.md`
(planning-only note).

## 6. Field-by-field form contract

Page slug `universal-support-chat-settings`; `<h1>` and page title **"Support Chat
Settings"**; menu label **"Settings"**; capability `CapabilityRegistrar::MANAGE`; form
`<form method="post" action="options.php">` + `settings_fields( Settings::OPTION_GROUP )` +
`do_settings_sections( self::SLUG )` + `submit_button()`. Sections/fields registered on
`admin_init`; the `option_page_capability_*` filter registered in `register()`.

| # | Section | Label | Control | Stored key | Default | Sanitisation (unchanged) |
|---|---|---|---|---|---|---|
| 1 | General | Enable chat widget | checkbox + hidden `0` | `widget_enabled` | `true` | `! empty()` |
| 2 | Conversation lifecycle | Days of inactivity before a conversation is closed | `number`, `min=1`, `step=1` | `conversation_inactive_days` | `30` | `positive_int()` |
| 3 | Conversation lifecycle | Days after archiving before message bodies are cleared | `number`, `min=1`, `step=1` | `conversation_archived_body_days` | `30` | `positive_int()` |
| 4 | Conversation lifecycle | Days before a closed conversation is permanently purged | `number`, `min=1`, `step=1` | `conversation_purge_days` | `90` | `positive_int()` |
| 5 | Telegram adapter | Mirror new messages to the Telegram adapter | checkbox + hidden `0` | `telegram_dispatch_enabled` | `false` | `! empty()` |
| — | Telegram adapter | *(read-only status panel — §7 below, not a field)* | — | — | — | — |
| 6 | Data removal | Remove all Support Chat data when the plugin is uninstalled | checkbox + hidden `0` | `remove_data_on_uninstall` | `false` | `! empty()` |

- Each lifecycle field carries one line of help text describing the period. Section intros
  give a plain-language summary. Field help notes that a non-positive value is restored to the
  current setting on save.
- **Section 6 warning copy** (exact intent): *"When enabled, all Support Chat plugin data —
  conversations, messages, notes, the audit log, pairing keys, and these settings — is
  permanently deleted, but only if and when the plugin is later uninstalled from the Plugins
  screen. Deactivating or updating the plugin never deletes anything. Leave this off unless
  you are deliberately decommissioning Support Chat."* Unchecked by default; reflects the
  stored value; saving never triggers deletion.
- **Save feedback:** Settings API default — `options.php` sets `settings_updated`;
  `settings_errors()` renders "Settings saved." at the top of the page.
- **Telegram read-only status panel** (inside the Telegram adapter section, below field 5):
  - `Dispatch:` `enabled` / `disabled` (from `Settings::get()`).
  - `Adapter pairing:` `not paired` / `paired` / `paired (disabled)` / `pairing expired` /
    `pairing revoked` — from `PeerRepository::find_by_peer_id( 'universal-telegram' )` →
    `PeerRecord::pairing_state()`.
  - A "Full adapter diagnostics →" link to the Diagnostics page.
  - No credentials, tokens, routes, webhook data, identifiers, timestamps, content, or raw
    errors. No pairing / credential / bot / destination / binding / transport control — the
    mirror toggle is the only control.

## 7. Diagnostics content and redaction boundary

`DiagnosticsPage` (slug `universal-support-chat-diagnostics`, submenu of `HubPage::SLUG`),
read-only, no `<form>`, no `<input>`, `current_user_can( MANAGE )` guard retained.

**Retained:** plugin version; schema available (yes/no; `MigrationFailureCode` enum label
when unavailable); vault self-check (`ok` / `fail-closed`); recent audit rows
(`count( recent( 5 ) )`, labelled "last 5").

**Added (safe aggregates):** Telegram dispatch `enabled`/`disabled`; adapter pairing state +
`usable: yes/no` (`PeerRecord::pairing_state()` / `is_usable()`); dispatch-outbox counts per
state (`DispatchOutboxRepository::count_by_state()`).

**Never rendered:** credentials, public keys, key IDs, raw key material, URLs / routes
(`outbound_route_base`), webhook data, tokens, message / note content, conversation IDs or
UUIDs, Telegram-native identifiers, peer row IDs, peer timestamps, raw exception text, stack
traces, `mark_failed` / failure-reason strings. Only booleans, fixed enum labels, integer
counts, and the version string.

## 8. Security and privacy impact

- **Capability:** no new capability; the `option_page_capability_*` filter tightens the save
  path from `manage_options` to `MANAGE`. Page render and the `LegacySettingsRedirect`
  decision both check `current_user_can( MANAGE )`.
- **Legacy redirect:** `resolve_target()` returns `null` for a non-`MANAGE` user (WordPress
  renders its own denial — no bypass) and for the redirect-target request (loop-safe). `302`,
  never `301`.
- **No new persisted data.** The form writes the existing option via the existing
  `Settings::sanitize()`. Diagnostics adds only aggregates from Support-Chat-owned
  repositories; **no Universal Telegram database access** (ADR-0002).
- **Redaction boundary** (§7) is explicit and test-enforced.
- **No destructive action on save / deactivate / update** — `remove_data_on_uninstall` is
  read only at `WP_UNINSTALL_PLUGIN`.
- Respects ADR-0003 (visitor isolation — no visitor data on either admin page beyond existing
  counts) and ADR-0006 (channel failure is surfaced as a status label, not an error dump).

## 9. Test and CI impact

New / updated tests (run in the existing PHPUnit unit + integration suites; no CI config
change):

- **NEW `tests/integration/Administration/Settings/SupportChatSettingsPageTest.php`:**
  - submenu registered under `HubPage::SLUG` with `MANAGE`;
  - **capability filter timing:** immediately after `->register()` (with `admin_init` not yet
    fired) `has_filter( 'option_page_capability_universal_support_chat_settings_group' )` is
    truthy and `apply_filters( …, 'manage_options' )` returns `CapabilityRegistrar::MANAGE`;
  - `add_settings_section` / `add_settings_field` are absent before `admin_init`, present
    after;
  - render contains the `settings_fields` nonce + `option_page` field and an input for **all
    six** keys, one per section (General / Conversation lifecycle / Telegram adapter / Data
    removal);
  - every checkbox (`widget_enabled`, `telegram_dispatch_enabled`,
    `remove_data_on_uninstall`) has a hidden `0` sibling immediately before it; **no** hidden
    "preserve" field;
  - the Data removal section renders the warning copy and the exact label "Remove all Support
    Chat data when the plugin is uninstalled"; checkbox unchecked when stored value is
    `false`, checked when `true`;
  - **save behaviour:** applying `Settings::sanitize()` to a POST-shaped array with
    `remove_data_on_uninstall` absent/`0` yields stored `false`; `1` yields `true`; **no**
    `Uninstaller` / deletion code path runs on save (spy / assert no table dropped);
  - capless user is `wp_die`n by `render()`;
  - Telegram panel prints the dispatch + pairing labels and its output contains **none** of
    `public_key`, `key_id`, `outbound_route`, `token`, `expires_at`, a conversation UUID.
- **NEW `tests/integration/Administration/Compat/LegacySettingsRedirectTest.php`** — primary
  coverage is the pure `resolve_target()`:
  - authorised legacy URL (`MANAGE` user, `resolve_target( 'options-general.php',
    'universal-support-chat' )`) → returns
    `admin_url( 'admin.php?page=universal-support-chat-diagnostics' )`;
  - unauthorised user, same args → `null`;
  - unrelated request (other `page`, or legacy slug on a different `$pagenow`) → `null`;
  - loop-target request (`resolve_target( 'admin.php',
    'universal-support-chat-diagnostics' )`) → `null`;
  - `LegacySettingsRedirect::LEGACY_SLUG === 'universal-support-chat'`; the legacy slug is not
    a registered admin page;
  - **narrow wrapper test:** install a temporary `wp_redirect` filter that captures
    location + status and **returns `false`**; with `resolve_target()` forced to return a
    URL, call `maybe_redirect()`; assert the filter saw that URL + status `302`. Because the
    filter returns `false`, `wp_safe_redirect()` returns `false`, the `if` is not entered,
    `exit` is never reached, the test process continues. Filter removed in teardown.
- **UPDATE `tests/integration/Administration/PluginActionLinksTest.php`:**
  `test_settings_link_targets_*` now expects
  `admin.php?page=universal-support-chat-settings` and asserts the link does **not** contain
  `options-general.php?page=universal-support-chat`; Conversations assertion unchanged.
- **NEW `tests/integration/Administration/Diagnostics/DiagnosticsPageTest.php`:** new slug +
  submenu parent `HubPage::SLUG`; output has no `<form>` / `<input>`; redaction assertions
  (no key / route / token / uuid / timestamp / stack-trace substrings); added rows render
  from stubbed `Settings` / `PeerRepository` / `DispatchOutboxRepository`; capless user
  denied.
- **UPDATE `tests/unit/Core/Configuration/SettingsTest.php`:** existing asserts stay green
  (compatibility evidence). Add: `widget_enabled => '0'` → `false`; key omitted → default
  `true`; `remove_data_on_uninstall => '0'` → `false`, `=> '1'` → `true` (justifies the
  explicit checkbox + hidden `0` companion).
- **UPDATE `tests/unit/Core/PluginTest.php`:** composition smoke — `SupportChatSettingsPage`
  and `LegacySettingsRedirect` construct and register; `init()` does not fatal.
- Existing `WidgetAssetsTest`, `CapabilityRegistrarTest` unaffected; run to prove no
  regression.

## 10. Work packages (execution order)

- **WP1 — Real Settings page.** `SupportChatSettingsPage` under `HubPage::SLUG`; four
  sections; explicit control + hidden `0` companion for every one of the six keys (no hidden
  preserve field); `remove_data_on_uninstall` "Data removal" section + warning copy;
  `option_page_capability_*` filter in `register()`; save feedback; wire into
  `Plugin::init()`. Tests: `SupportChatSettingsPageTest` (incl. Data-removal render / save /
  default and capability-filter timing), `SettingsTest` additions, `PluginTest` smoke.
- **WP2 — Diagnostics reparent + safe status.** Slug rename, `add_submenu_page` under the
  Hub, inject `Settings` / `PeerRepository` / `DispatchOutboxRepository`, render dispatch
  flag + pairing state/usability + outbox state counts + schema failure label, "Open Settings
  →" link. Tests: `DiagnosticsPageTest` (redaction, slug/parent, new rows, read-only).
- **WP3 — Navigation + legacy-URL compatibility.** `HubPage` explicit "Conversations" child
  label; `PluginActionLinks` Settings retarget (+ assert no legacy URL); Settings↔Conversations
  and Diagnostics→Settings links; `LegacySettingsRedirect` (pure `resolve_target()` + frozen
  `maybe_redirect()` body) wired into `Plugin::init()`. Tests: `LegacySettingsRedirectTest`,
  `PluginActionLinksTest` update.
- **WP4 — Telegram read-only panel on the Settings page.** Dispatch + pairing lines + link to
  Diagnostics; over-claim guard tests (no key / route / token / "healthy").
- **WP5 — Docs.** ADR-0015 index entries; `docs/plans/README.md` row; `docs/ARCHITECTURE.md`
  Administration boundary row; `docs/milestones/sc-m05-*.md` planning-only note; changelog
  note on the Diagnostics slug move and the legacy-URL 302. *(This freeze commit already
  performs the index/reference updates; WP5 covers anything the implementation must add,
  e.g. the changelog entry.)*

## 11. Risks and mitigations

| Risk | Mitigation |
|---|---|
| `option_page_capability_*` filter registered too late → `options.php` denies a `MANAGE` (non-`admin`) user's save | Register the filter in `register()`, not `admin_init`; dedicated test asserts it exists immediately after `register()`. |
| An unchecked checkbox omits its key → `Settings::sanitize()` resets it to **default** (not the stored value), silently flipping e.g. a deliberately-disabled widget back on | Hidden `0` companion before every checkbox guarantees the key is always present; `SettingsTest` covers the omitted-key vs `'0'` cases. |
| Destructive `remove_data_on_uninstall` surfaced in UI alarms operators or is misread as "delete now" | Explicit warning copy stating deletion happens only at uninstall; default off; placed last in its own section; test asserts the copy renders. |
| Legacy redirect loops or bypasses capability | `resolve_target()` is pure and returns `null` for the target request and for non-`MANAGE` users; unit tests cover both; `302` not `301`. |
| Diagnostics accidentally leaks an identifier via a new row | Redaction boundary frozen in ADR-0015 §3; `DiagnosticsPageTest` greps rendered output for forbidden substrings; only booleans / enum labels / counts / version are added. |
| Bookmarks to `options-general.php?page=universal-support-chat` break | `LegacySettingsRedirect` keeps the URL working (302 → Diagnostics for `MANAGE`); changelog documents the move. |
| Submenu registration order (parent vs child) | All registrations run on `admin_menu`; `add_submenu_page` stores into `$submenu[$parent]` regardless of order and WordPress assembles the menu afterwards. `PluginTest` smoke + manual check. |
| A future reviewer expects `Settings.php` validation feedback | Documented as a deliberate out-of-scope choice (§3 open question); `Settings.php` stays untouched to keep the compatibility guarantee absolute. |

## 12. Out of scope (explicit)

- **No new option, option key, schema table/column, migration step, or `db_version` change.**
  If source inspection during implementation shows any of the six settings cannot be safely
  represented without a schema change, **stop and report** — do not expand scope.
- **No change to `Settings::defaults()`, `Settings::sanitize()`, or `positive_int()`.**
- **No change to uninstall behaviour** — `remove_data_on_uninstall` is made visible only; its
  key, default, and sole consumer (`Uninstaller` at `WP_UNINSTALL_PLUGIN`) are untouched. No
  confirmation dialogue, no JS, no deletion on save/deactivate/update.
- **No new top-level WordPress menu.**
- **No AI / prompts / models / drafts / RAG / knowledge-base configuration.**
- **No support-hours, availability, assignment/routing, ticketing, launcher redesign, or
  widget styling work** (SC-M05 / SC-M06 territory). The widget setting exposed here is the
  existing `widget_enabled` boolean only.
- **No new message-delivery semantics, queue/priority changes, Contract v1 changes, or
  Universal Telegram modifications.** `TelegramDispatch*`, `AdapterContractClient`, and the
  Contract controllers are read-only inputs to the status panels.
- **No migration / cutover / legacy-engine work** (already retired, ADR-0013). The
  legacy-URL redirect is admin routing only.
- **No `PairingPage` change**, no pairing/credential/bot/destination/binding/transport
  control added anywhere.
- **No DEV change, no production change, no version bump, no branch/PR/tag/release/deploy**
  as part of this plan freeze.

## 13. Definition of done

- ADR-0015 Accepted; this plan frozen; both referenced from the indexes.
- `Support Chat` menu shows **Conversations**, **Settings**, **Diagnostics** as submenus; no
  top-level menu added; no `Settings → Support Chat` menu entry remains.
- `Support Chat → Settings` renders a Settings API form exposing exactly the six existing
  keys across the four sections, with a clearly separated **Data removal** section (default
  off, warning copy). Saving shows "Settings saved."; a `wp option get
  universal_support_chat_settings` diff after an unchanged save is empty.
- A user with `universal_support_chat_manage` but not `manage_options` can open and save the
  Settings page; a user without `MANAGE` is denied.
- `Support Chat → Diagnostics` is read-only, carries the retained rows plus the safe Telegram
  aggregates, and its rendered output contains none of the forbidden substrings.
- `options-general.php?page=universal-support-chat` → `302` to the Diagnostics page for a
  `MANAGE` user; WordPress's standard permission screen for anyone else; no redirect loop.
- Plugins-screen **Settings** link opens `universal-support-chat-settings`; **Conversations**
  link unchanged.
- Toggling `remove_data_on_uninstall` on, saving, then deactivating and reactivating the
  plugin leaves every table and row intact.
- Full existing quality gate green, plus all tests in §9.
- `universal_support_chat_db_version` still `12`; no schema object created/altered/dropped.
