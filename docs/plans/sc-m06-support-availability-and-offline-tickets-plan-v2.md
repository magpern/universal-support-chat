# SC-M06 — Support Availability and Offline Tickets — Implementation Plan v2

> Supersedes [`sc-m06-support-availability-and-offline-tickets-plan-v1.md`](sc-m06-support-availability-and-offline-tickets-plan-v1.md)
> (the foundation-freeze product-boundary stub, retained unedited). This v2 is the
> implementation-ready freeze and is committed together with, and depends on,
> [ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
> (**Proposed** in the same freeze). Per `docs/governance.md`, implementation begins only
> after a separate Product Owner implementation-acceptance record is merged and ADR-0017 is
> **Accepted**. Implementation reports cite this plan's freeze commit SHA.

## 1. References

- Charter: [`docs/milestones/sc-m06-support-availability-and-offline-tickets.md`](../milestones/sc-m06-support-availability-and-offline-tickets.md)
- Requirements: **R5** (support hours / live status), **R7** (offline human support) —
  [`docs/master-plan.md`](../master-plan.md).
- Introduces: [ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md).
- Relies on / respects: [ADR-0006](../adr/0006-optional-channel-and-adapter-failure-model.md)
  (fail-closed per channel), [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md)
  (Settings page + Diagnostics separation), [ADR-0016](../adr/0016-support-chat-widget-presentation-settings.md)
  (plain-text operator copy), [ADR-0012](../adr/0012-automatic-support-chat-to-telegram-dispatch.md)
  / [ADR-0014](../adr/0014-interactive-chat-delivery-class-and-immediate-dispatch.md)
  (post-commit content-free outbox; async worker), [ADR-0003](../adr/0003-security-privacy-and-visitor-isolation.md)
  (visitor isolation / classification), [ADR-0004](../adr/0004-migration-and-retention-principles.md)
  (retention ownership).
- Test strategy: [`docs/testing/test-strategy.md`](../testing/test-strategy.md).

## 2. Repository findings at plan-drafting time

Verified against the freeze baseline `b17f4713f88e9db24dd7942b1f7b0cf768263721`.

| Area | Finding |
|---|---|
| Visitor REST | `Conversations\Rest\ConversationsController` — routes use `permission_callback => __return_true` but every handler calls `authenticate_session()`, which requires `is_user_logged_in()` + a valid `wp_rest` nonce. **Offline tickets are for logged-in visitors**; logged-out visitors get a sign-in prompt from `ChatWidget\WidgetAssets` and never reach storage. |
| Existing endpoints | `POST /conversations` (start/resume, `start_idempotency_key` supported, reuses `find_active_for_owner`), `POST /conversations/{uuid}/messages` (message `idempotency_key` supported), `GET /conversations/{uuid}` (poll: status + messages), `GET /conversations/mine`. These are **sufficient** for offline-ticket storage and reply; the only gap is communicating availability state. |
| Status vocabulary | `Conversations\ConversationStatus` defines `new`, `open`, `waiting_for_visitor`, **`waiting_for_operator`** (defined, currently unreachable from the visitor path), `resolved`, `archived`. `map()`: `new → {open, archived}` — **`new → waiting_for_operator` is not currently legal**. `open → {waiting_for_visitor, waiting_for_operator, resolved, archived}` and `waiting_for_visitor → {open, resolved, archived}` are legal; `waiting_for_operator → {open, resolved, archived}` is legal (so operator reply `→ waiting_for_visitor` needs… see note). |
| Operator reply transition | `Administration\Conversations\HubActions` / `ConversationDetailPage` reply path — current behaviour transitions to `waiting_for_visitor`. The map today lists `waiting_for_operator → {open, resolved, archived}` **without** `waiting_for_visitor`. WP2 must confirm the exact edge the Hub reply uses and, if `waiting_for_operator → waiting_for_visitor` is not already legal, add that edge too (still a code constant, no schema change). *(This nuance is called out so the freeze is explicit; the intent is "operator reply behaves exactly as today".)* |
| Settings | `Core\Configuration\Settings` — sole owner of fixed-shape `universal_support_chat_settings` (9 keys). `defaults()` / `sanitize()` are fixed-shape; unknown keys dropped; `sanitize()` is authoritative regardless of submitted shape. Registered once in `Settings::register()`. |
| Settings page | `Administration\Settings\SupportChatSettingsPage` (ADR-0015) — Settings API, `Settings::OPTION_GROUP`, `option_page_capability_*` filter → `MANAGE`, sections General / Widget presentation / Conversation lifecycle / Telegram adapter / Data removal. Each control has a hidden companion so every key is always present in the POST. |
| Capability | `Core\Capabilities\CapabilityRegistrar::MANAGE = 'universal_support_chat_manage'`, granted to `administrator` only. Single capability. |
| Retention | `Conversations\RetentionCleanupHandler` — daily WP-Cron (`universal_support_chat_conversation_retention_cleanup`), `ensure_scheduled()` on `init`. inactive→resolved→archived→body-null→purge; reads the three retention keys. Conversations (any status) are covered. |
| Uninstall | `Core\Lifecycle\Uninstaller` — runs only at `WP_UNINSTALL_PLUGIN`, gated by `remove_data_on_uninstall`. Must be extended to delete the new override option. |
| Telegram path | ADR-0012/0014: `TelegramDispatch\DispatchEnqueuer::persist_and_enqueue()` writes the message row + a content-free outbox row in one transaction when `telegram_dispatch_enabled`; `DispatchWorker` (WP-Cron) does all Telegram I/O. `ConversationsController` already calls `persist_and_enqueue` when a `DispatchEnqueuer` is wired. **No request-path Telegram I/O today; SC-M06 keeps it that way.** |
| Widget | `ChatWidget\WidgetAssets` server-renders the shell + `wp_localize_script('uscChatWidget', …)` (includes `greeting`, `i18n`, `schemaOk`, `loggedIn`, `pollInterval` 4000). `WidgetPresentation` resolves title/greeting/avatar. `assets/js/chat-widget.js` renders dynamic text with `.textContent` only (enforced by an existing static test). Panel is a non-modal `role="dialog"` (ADR-0016). |
| Timezone | No timezone handling in the plugin today. `wp_timezone()` / `current_datetime()` is the source. |
| Doc-link CI | `bin/check-doc-links.php` via `composer run-script check-doc-links` (CI job `docs`); `docs/adr/README.md` says "next available number is **0017**". |
| Structural test | A unit test forbids `src/` directories for unauthorized boundaries; `docs/ARCHITECTURE.md` lists **Availability** as "Not authorized until SC-M06". Both must be updated so `src/Availability/` is permitted. |

## 3. Assumptions and open questions (not decisions)

- **Assumption:** the Hub operator-reply transition today is effectively
  `… → waiting_for_visitor`; WP2 verifies the exact current edge and preserves that
  behaviour, adding the map edge only if the current code relies on one not yet in `map()`.
- **Assumption:** DEV site timezone is a fixed IANA zone (not "UTC+offset"); `DateTimeZone`
  handles both, tests cover a DST zone (`Europe/Stockholm`).
- **Open (non-blocking) question for the Product Owner** — see §22. All have a recommended
  default already chosen in the frozen decisions; none blocks implementation.

## 4. Architectural decisions (with alternatives — cite ADR-0017)

All precedence, ownership, honesty, transaction, and transition-map decisions are frozen in
[ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
§§1–9 and its Alternatives section. This plan does not restate the alternatives; it realises
the decision. Plan-level design choices:

- **D1 — Pure resolver.** `Availability\AvailabilityResolver` is a pure class:
  `resolve(WeeklySchedule $schedule, ExceptionSet $exceptions, ?AvailabilityOverride $override, DateTimeImmutable $now): AvailabilityState`.
  No WordPress calls inside. A thin `Availability\AvailabilityService` loads the options,
  obtains `wp_timezone()` / `current_datetime()`, constructs the value objects, and
  delegates. This keeps the precedence logic exhaustively unit-testable without WP.
- **D2 — Value objects with total validation.** `WeeklySchedule` (7 weekday →
  `TimeInterval[]`), `TimeInterval` (`start`, `end` as minutes-since-midnight, half-open,
  `start < end`), `DateException` (`closed` | `TimeInterval[]`), `ExceptionSet`
  (`Y-m-d` → `DateException`). Each has a `fromArray()` that **throws
  `InvalidScheduleException` on any malformed element** and a `toArray()` for storage.
- **D3 — Atomic settings validation.** `Settings::sanitize()` for the availability keys is
  all-or-nothing: it attempts `WeeklySchedule::fromArray()` / `ExceptionSet::fromArray()`
  on the submitted value; **on any failure it keeps the previously stored valid value**
  (read from `get_option()` within `sanitize()`, as the existing retention `positive_int()`
  fallback already reads defaults) and registers a `settings_error`. Malformed input is
  **never** silently coerced to the default. (First install with no stored value ⇒ the
  documented default.)
- **D4 — Override as a separate autoloaded option.** `universal_support_chat_availability_override`
  (autoloaded), shape `{ mode: 'force_online'|'force_offline', expires_at: int|null,
  set_by: int, set_at: int }`. Absent option ⇒ `Automatic`. Read/normalise through a small
  `AvailabilityOverride` value object; a corrupt stored value is treated as absent (fail to
  `Automatic`) and cleared.
- **D5 — Dedicated override action.** `Availability\Admin\OverrideAction` on `admin_post_…`
  with its own `check_admin_referer()` + `current_user_can( MANAGE )`; sets or clears the
  option and writes an audit row; redirects back with a notice. **Not** a Settings-API field.
- **D6 — Server-authoritative availability in the request path.** `ConversationsController`
  gains an injected `AvailabilityService`. On `handle_post_message` (and `handle_start`),
  after resolving the visitor and before/around the message write, it resolves the state.
  When `unavailable`, the message create + outbox enqueue + `waiting_for_operator` transition
  run inside **one DB transaction** (`$wpdb->query('START TRANSACTION')` … `COMMIT` /
  `ROLLBACK`, matching the existing `DispatchEnqueuer::persist_and_enqueue` transaction
  style). All response envelopes gain `"availability": "available"|"unavailable"`.
- **D7 — Widget rendering.** `WidgetAssets` server-renders the resolved state + the offline
  copy into `uscChatWidget` (`availability`, `offlineMessage`, plus `i18n` additions for the
  "We're online" pill and the offline confirmation). `chat-widget.js` renders the offline
  message and confirmation with `.textContent`; shows the "online" pill only when
  `availability === 'available'`; updates its own state from the `availability` field on
  every poll and on the start/message POST responses.
- **D8 — Hub Waiting filter.** `ConversationInboxPage` gains an explicit **Waiting** filter
  entry: `status = waiting_for_operator` (plus a documented transitional `OR status = new`
  clause, removable once no legacy `new` rows remain), ordered `updated_at ASC`. Reuses the
  existing `ConversationRepository::list_for_hub()` with a small `waiting` pseudo-status or a
  dedicated repo method — **no inferred "unanswered" logic.**
- **D9 — Diagnostics block.** `DiagnosticsPage` gains a read-only "Availability" section:
  resolved state, mode, override expiry (`until cleared` when null), "schedule config valid:
  yes/no". Booleans / enum labels / one timestamp only — ADR-0015 §3 redaction boundary.

## 5. Availability state model

Precedence (ADR-0017 §3), evaluated by `AvailabilityResolver`:

```
1. override (non-expired force_online / force_offline)      -> that state
2. today's DateException:
       closed                                               -> unavailable
       special hours -> now within any exception interval?  -> available / unavailable
3. Automatic + no exception:
       now within any WeeklySchedule interval for weekday?   -> available / unavailable
4. otherwise / any evaluation failure                        -> unavailable  (fail-safe)
```

- "now" and "today"/"weekday" are computed in the site timezone (`$now` passed in already
  localised).
- Intervals are half-open `[start, end)`; the boundary minute `end` is **not** available.
- A `null`-expiry override is never "expired"; a non-null `expires_at <= now` override is
  treated as absent (and reaped — §7).
- The resolver never mutates input and never touches WordPress.

## 6. Schedule representation and validation

Stored under `universal_support_chat_settings`:

| Key | Shape | Default |
|---|---|---|
| `availability_schedule` | `{ "mon": [ {"start":"HH:MM","end":"HH:MM"}, … ], "tue": […], … "sun": [] }` — 7 lowercase weekday keys, each a (possibly empty) list of intervals | `mon`–`fri`: `[{"12:00","15:00"}]`; `sat`,`sun`: `[]` |
| `availability_exceptions` | `{ "YYYY-MM-DD": "closed" | [ {"start":"HH:MM","end":"HH:MM"}, … ], … }` | `{}` |
| `availability_offline_message` | plain string, `sanitize_textarea_field`, ≤ 500 chars | `The support team is offline right now. Leave your message here and we'll reply in this chat when we're back.` |
| `availability_online_indicator` | bool — show the subtle "We're online" pill when truly available | `true` |

**Validation (atomic, D3):** `WeeklySchedule::fromArray()` / `ExceptionSet::fromArray()`
reject: unknown weekday keys, non-list interval containers, missing/extra interval fields,
`start`/`end` not matching `^\d{2}:\d{2}$` or out of `00:00`–`23:59`, `end <= start`,
exception date not `Y-m-d` / not a real calendar date, exception value neither `"closed"`
nor a valid interval list. **Any** failure ⇒ the whole Availability-section update is
rejected, the prior stored valid value is kept, and a `settings_error` explains which field
failed. Overlapping intervals are allowed (union). Empty weekday list = closed that day.

**Runtime corruption:** if a stored schedule/exception value fails to parse at evaluation
time, `AvailabilityService` catches it, the resolver returns fail-safe `unavailable`, and a
transient/flag drives an admin-only Diagnostics warning. The stored value is **not**
rewritten.

## 7. Manual override model and expiry / clear semantics

- Option `universal_support_chat_availability_override` (autoloaded), written only by
  `OverrideAction` (D5). Shape in D4.
- **Set:** operator picks `Force online` or `Force offline` and either "until I clear it"
  (`expires_at = null`) or a date-time (`expires_at` = unix seconds, must be in the future;
  a past value is rejected by the action).
- **Null expiry** is valid and persistent — surfaced verbatim in the Hub control ("Forced
  offline — until cleared") and in Diagnostics.
- **Expiry (non-null, past):** treated as absent by the resolver; reaped by
  `AvailabilityService::reap_expired_override()` on next read **and** by a lightweight
  hook on the existing daily retention cron (no new cron event) — reaping deletes the
  option and writes an `availability.override_expired` audit row.
- **Clear:** `OverrideAction` deletes the option, writes `availability.override_cleared`,
  returns the system to `Automatic`.
- The override never affects schedule/exception config and vice versa.

## 8. Visitor widget behaviour

| Server-resolved state | Widget |
|---|---|
| `available` | Unchanged chat. If `availability_online_indicator` is on, a subtle "We're online" pill in the header — shown **only** while `availability === 'available'`. Greeting unchanged. No response-time text. |
| `unavailable` | Widget still opens (launcher unchanged). Header shows no "online" pill. The offline message (`availability_offline_message`, `.textContent`) appears in the intro area. The composer stays enabled; submitting creates/continues the conversation. After a successful send, an in-widget confirmation (`role="status"` region) states the message was received and will be answered here later — **no ETA**. The visitor's message and any later operator replies appear in the transcript via the existing 4 s poll. |
| schedule/clock config invalid | Identical to `unavailable` (fail-safe). **No error is shown to the visitor.** An admin-only warning is raised on Diagnostics. |

- State transitions in the widget are driven by the `availability` field on every poll
  response and on the start/message POST responses, so a widget opened just before 15:00
  flips to offline immediately after the next poll or the visitor's next send — it can never
  keep showing "online" after the server has resolved otherwise.
- ADR-0016 dialog semantics, focus handling, mobile full-screen sheet, RTL mirroring, and
  `prefers-reduced-motion` are unchanged.

## 9. Offline-ticket behaviour

**Definition:** an offline ticket is an existing authenticated visitor conversation/message,
created or continued via the existing endpoints, submitted while the server resolves
`unavailable`. Not a new type, table, or unauthenticated form.

- **Visitor sees:** offline copy on open; after send, an in-widget confirmation ("Message
  received — we'll reply here when we're back"); the message in the transcript.
- **Stored:** a normal `Conversation` (existing `POST /conversations` if none active, else
  the active one reused via `find_active_for_owner`) + a visitor-direction
  `ConversationMessage` via existing `POST /conversations/{uuid}/messages`. **No new table,
  no new column.**
- **Exact transition rule (frozen):** when the server resolves `unavailable` at
  message-acceptance time, it commits — **as one DB transaction** — (a) the visitor
  `ConversationMessage`, (b) the content-free ADR-0012 dispatch-outbox row **iff**
  `telegram_dispatch_enabled` (i.e. keep using `DispatchEnqueuer::persist_and_enqueue`),
  and (c) `ConversationRepository::transition($conversation, waiting_for_operator)`. If the
  transition fails, the whole transaction rolls back; a committed message is never left in
  the prior status. Source `new`, `open`, or `waiting_for_visitor` (incl. already-active)
  all end at `waiting_for_operator`.
- **Transition-map change (frozen):** add exactly the edge `new → waiting_for_operator` to
  `ConversationStatus::map()` (code constant; no schema / `db_version` change). Chosen over
  a synthetic `new → open → waiting_for_operator` sequence (ADR-0017 Alternatives).
- **`available` path:** unchanged (`new` / `waiting_for_visitor` → `open`).
- **Operator reply:** unchanged from today — preserve the exact current Hub-reply
  transition (expected `waiting_for_operator → waiting_for_visitor`; WP2 verifies and adds
  that edge to `map()` only if the current reply code needs it and it is missing).
- **Duplicate / idempotency:** reuse the existing `start_idempotency_key` and message
  `idempotency_key`; an active conversation is reused, so re-submitting does not spawn
  duplicate tickets. No new idempotency surface.
- **Operators find it — exact Waiting query (frozen):** status **`= waiting_for_operator`**
  (plus a documented transitional `OR status = new` for any pre-upgrade `new` rows,
  removable later); ordered `updated_at ASC` (oldest waiting first). Rendered as an explicit
  "Waiting" filter in `ConversationInboxPage`. No inferred "last message unanswered" logic.
- **Visitor sees replies:** the existing poll / `GET /conversations/{uuid}` already delivers
  operator replies — unchanged.

## 10. Administration UX

- **Settings** (`SupportChatSettingsPage`, ADR-0015): a new **"Availability"** section with
  the weekly schedule (per-weekday interval rows), the exceptions editor (date + closed / hours),
  the offline message textarea, and the "Show 'We're online' indicator" checkbox. Capability:
  existing `MANAGE` via the existing option-page-capability filter. Every field has a hidden
  companion so the section always round-trips (D3 preserves the prior value on rejection).
- **Manual override** (`OverrideAction`, D5): a compact control block on the **Hub** (the
  operator's daily surface) — current mode, "Force online" / "Force offline" with an
  optional expiry, and "Clear override". A read-only mirror of the current mode also appears
  in the Settings Availability section with a link to the Hub control.
- **Default-safe values:** schedule Mon–Fri 12:00–15:00 site tz; no exceptions; mode
  `Automatic`; the frozen default offline message (no ETA); online indicator on.
- **Diagnostics** (`DiagnosticsPage`, D9): read-only "Availability" — resolved state, mode,
  override expiry (`until cleared` when null), "schedule config valid: yes/no".
- **Audit events** (all `INTERNAL`, via `AuditLogger`): `availability.schedule_updated`,
  `availability.exceptions_updated`, `availability.override_set`,
  `availability.override_cleared`, `availability.override_expired`. Context carries only
  safe fields (actor id, new mode, expiry presence) — no schedule contents beyond a change
  marker.

## 11. Directory, namespace, schema, API impact

- **New namespace `UniversalSupportChat\Availability`** — `AvailabilityResolver`,
  `AvailabilityService`, `AvailabilityState` (enum-like), `WeeklySchedule`, `TimeInterval`,
  `DateException`, `ExceptionSet`, `AvailabilityOverride`, `InvalidScheduleException`,
  `Admin\OverrideAction`. `docs/ARCHITECTURE.md` Availability row + the structural
  unauthorized-boundary test updated to permit it.
- **`src/Conversations/ConversationStatus.php`** — one new map edge
  (`new → waiting_for_operator`), possibly one more (`waiting_for_operator →
  waiting_for_visitor`) only if WP2 finds the current reply path needs it.
- **`src/Core/Configuration/Settings.php`** — four additive keys in `defaults()` /
  `sanitize()` + updated array-shape docblocks (PHPStan level for this file); atomic
  validation helper.
- **`src/Core/Lifecycle/Uninstaller.php`** — delete `universal_support_chat_availability_override`.
- **`src/Conversations/Rest/ConversationsController.php`** — inject `AvailabilityService`;
  `availability` field on all response envelopes; the one-transaction unavailable path.
- **`src/ChatWidget/WidgetAssets.php`** + **`assets/js/chat-widget.js`** +
  **`assets/css/chat-widget.css`** — offline copy, online pill, confirmation, state refresh.
- **`src/Administration/Settings/SupportChatSettingsPage.php`**,
  **`src/Administration/Diagnostics/DiagnosticsPage.php`**,
  **`src/Administration/Conversations/ConversationInboxPage.php`** (+ Hub override block).
- **`src/Core/Plugin.php`** — wire `AvailabilityService`, `OverrideAction`, the reap hook,
  and the `AvailabilityService` into `ConversationsController` and `WidgetAssets`.
- **Schema:** none. `universal_support_chat_db_version` stays **12**. `Migrator` untouched.
- **REST:** no new route. `availability` field added to existing responses only. The public
  unauthenticated availability endpoint is **not** added.
- **Version constant:** `UNIVERSAL_SUPPORT_CHAT_VERSION` bump (patch/minor, e.g.
  `0.7.0 → 0.8.0`) for the asset cache-bust only — decided at implementation, no release/tag.

## 12. Security and privacy impact

Per ADR-0017 "Security and privacy impact". Summary: no new capability; no capability
relaxation (override action does its own `MANAGE` + nonce check); no new visitor PII (offline
path stores exactly what the online path stores); operator offline copy is plain-text
sanitised in and `esc_html()` / `.textContent` out (ADR-0016); Diagnostics shows safe
aggregates only (ADR-0015 §3); audit events are `INTERNAL` and never exported (ADR-0003);
fail-closed availability (ADR-0006). Visitor isolation and the authenticated-only visitor
REST boundary (ADR-0003) are unchanged.

## 13. Test and CI impact

New unit suites under `tests/unit/Availability/`; new integration suites under
`tests/integration/Availability/`, `tests/integration/Conversations/` (offline path),
`tests/integration/Administration/` (Settings section, Diagnostics block, Hub Waiting,
Override action). Widget static test extended (offline copy uses `.textContent`; no
`innerHTML`). Browser QA added to the existing SC-M05 harness (axe, Lighthouse, Chromium +
Firefox). All existing CI gates run: PHPCS, PHPStan, unit (PHP 8.1 + 8.3), integration
(WP 6.9/PHP 8.1 and WP 7.1/PHP 8.3), interop (both variants), doc-links. Interop must stay
green — SC-M06 makes no Contract v1 or adapter change, so the dual-plugin suite is expected
unaffected; it is still run to prove it.

## 14. Work packages (execution order, with stop points)

| WP | Scope | Stop point |
|---|---|---|
| **WP1** | `Availability` value objects + `InvalidScheduleException` + `AvailabilityResolver` (pure). Precedence `override → exception → schedule → fail-safe`. | Green `tests/unit/Availability` truth-table suite (incl. DST, boundary minute, closed/special exceptions, empty & malformed config ⇒ fail-safe). |
| **WP2** | Add `new → waiting_for_operator` map edge (and verify/keep the operator-reply edge). `AvailabilityService` (loads options, `wp_timezone()`, reap). Atomic one-transaction message + outbox + transition path in `ConversationsController`; `availability` field on all envelopes. | Integration: unavailable message from `new`/`open`/`waiting_for_visitor` ⇒ `waiting_for_operator`; forced transition failure ⇒ full rollback; available path unchanged; operator reply unchanged; POST responses carry `availability`. |
| **WP3** | Settings: four additive keys, `defaults()`/`sanitize()` + docblocks, atomic validation preserving the prior valid value, `settings_error` on rejection. "Availability" section on `SupportChatSettingsPage`. | Integration: valid round-trip; malformed schedule/exception rejected **and prior valid value preserved** (never normalised to default); first-install default applied. |
| **WP4** | `AvailabilityOverride` value object + `universal_support_chat_availability_override` option + `Admin\OverrideAction` (`admin_post`, nonce + `MANAGE`) + expiry reaping (read-path + retention-cron hook) + `Uninstaller` deletion + audit events. Hub override control block. | Integration: set force-offline/online; null-expiry persists & is shown "until cleared"; past non-null expiry reaped + audited; clear returns to `Automatic`; action rejects bad nonce / missing `MANAGE`; `Uninstaller` removes the option only with `remove_data_on_uninstall`. |
| **WP5** | Widget: server-render `availability` + `offlineMessage` + i18n; `chat-widget.js` offline intro, online pill (only when available), post-send confirmation, state refresh from poll + POST responses; CSS. | Widget static test (no `innerHTML`, offline copy via `.textContent`); browser QA — offline state, boundary refresh, axe 0 violations in widget scope, Lighthouse a11y 100, Chromium + Firefox, mobile sheet, RTL, reduced-motion. |
| **WP6** | Hub **Waiting** filter (`= waiting_for_operator` [+ transitional `new`], `updated_at ASC`) in `ConversationInboxPage`; repo method/pseudo-status. | Integration: exact membership + ascending order; no duplicate/inferred rows. |
| **WP7** | Diagnostics "Availability" block (safe aggregates only) + runtime-corruption admin warning. | Integration: block renders state/mode/expiry/validity; redaction test — no schedule contents, identifiers, timestamps beyond the one expiry, or raw errors. |
| **WP8** | Wire everything in `Plugin.php`; version-constant bump; `docs/ARCHITECTURE.md` + structural test; full local gate run; open the implementation PR. | All CI green; PR opened, unmarked for merge. |

Each WP is independently revertible; WP2's map edge and transaction path are the only
runtime-behaviour-visible changes before WP5.

## 15. Failure modes and fail-safe decisions

| Condition | Behaviour |
|---|---|
| Stored schedule unparseable at runtime | Resolver ⇒ `unavailable`; admin-only Diagnostics warning; stored value **not** rewritten. |
| Empty schedule (all weekdays `[]`, no exception, `Automatic`) | `unavailable` (valid config, simply "no hours"). |
| Timezone resolution failure | `unavailable`. |
| Override option corrupt / unknown mode | Treated as absent ⇒ `Automatic`; option cleared; no crash. |
| Non-null override expiry in the past | Treated as absent; reaped + `availability.override_expired` audit. |
| Server clock skew | Server clock is authoritative; no compensation. |
| Forced `waiting_for_operator` transition fails during an offline send | Whole message+outbox+transition transaction rolls back; visitor gets a normal transient error and can retry; no orphan message, no wrong status. |
| Telegram adapter absent / disabled / mismatched / failing | Ticket created, transitioned, and Hub-visible regardless; any mirror is worker-only and cannot block. |
| WP-Cron not running | Availability is still evaluated per-request (no cron dependency for evaluation); only the lazy override reap is delayed — the read-path reap still catches it on next resolve. |
| Malformed Availability-section submission | Whole section update rejected; prior valid config preserved; `settings_error` shown. |

## 16. Migration / upgrade / default behaviour

- Additive option keys; an option array lacking them resolves to the documented defaults via
  `Settings::sanitize()`. The **default schedule Mon–Fri 12:00–15:00 site tz begins to apply
  on upgrade** — the intended R5 default, fully operator-editable. Sites wanting the previous
  always-available behaviour set `Force online` or a 24×7 schedule.
- Override option absent until first set ⇒ `Automatic`.
- `ConversationStatus::map()` gains one edge — pure code, no stored row touched; every
  existing row stays valid.
- `universal_support_chat_db_version` stays **12**; `Migrator` untouched; no table change.
- Downgrade to a pre-SC-M06 build: availability stops being evaluated, the new edge
  disappears; existing `waiting_for_operator` conversations remain handled by pre-existing
  edges.
- Uninstall: the override option is removed only under the existing opted-in
  `remove_data_on_uninstall` path.

## 17. Comprehensive test matrix

### Automated — unit (`tests/unit/Availability/`)

- Resolver truth table: in-hours; exactly at `start` (available); exactly at `end`
  (unavailable — half-open); one minute before/after each boundary; weekday with no
  intervals; every precedence combination (override vs exception vs schedule).
- Exceptions: `closed` on an otherwise-in-hours weekday ⇒ `unavailable`; special-hours
  exception replaces the weekday's intervals (in and out of the exception window).
- Override: `force_online` outside hours ⇒ `available`; `force_offline` in hours ⇒
  `unavailable`; `null` expiry never expires; non-null past expiry ⇒ treated as absent;
  future expiry ⇒ active.
- Fail-safe: empty schedule ⇒ `unavailable`; malformed array passed to `fromArray()` ⇒
  `InvalidScheduleException`; resolver given a "could not build" signal ⇒ `unavailable`.
- DST: `Europe/Stockholm` spring-forward (02:00→03:00) and fall-back (03:00→02:00) days —
  an interval spanning the skipped/duplicated hour resolves without exception and with the
  intuitive wall-clock result.
- Value objects: `TimeInterval` rejects `end <= start`, non-`HH:MM`, out-of-range;
  `ExceptionSet` rejects non-date keys and bad values; `toArray()`/`fromArray()` round-trip.

### Automated — integration

- **Settings:** valid schedule/exceptions/offline-message round-trip; malformed submission
  ⇒ whole section rejected **and** the previously stored valid value is intact (explicitly
  asserted — not the default); first install ⇒ documented default; offline message
  tag-stripped and length-capped.
- **Offline path:** with dispatch **disabled** — unavailable visitor message from each of
  `new` / `open` / `waiting_for_visitor` ⇒ conversation ends `waiting_for_operator`, message
  stored, response `availability = unavailable`; idempotent resubmit ⇒ no duplicate
  conversation or message. With dispatch **enabled** — same, plus exactly one content-free
  outbox row committed in the same transaction, and **no** Telegram API call in the request
  (worker not run); forcing the transition to fail ⇒ message row, outbox row, and status
  change all absent (full rollback).
- **Available path unchanged:** in-hours visitor message from `new` ⇒ `open` (not
  `waiting_for_operator`); response `availability = available`.
- **Operator reply unchanged:** from `waiting_for_operator`, Hub reply ⇒
  `waiting_for_visitor`, visible to the visitor on poll.
- **Override action:** set force-offline (null + dated expiry); `MANAGE`-less user ⇒ denied;
  bad nonce ⇒ denied; clear ⇒ `Automatic`; past dated expiry ⇒ reaped on next resolve and
  on the retention cron, with `availability.override_expired` audit.
- **Hub Waiting:** seed conversations across all statuses ⇒ Waiting filter shows exactly the
  `waiting_for_operator` (+ transitional `new`) rows, `updated_at ASC`.
- **Diagnostics:** Availability block shows state/mode/expiry/validity; redaction test — no
  schedule interval contents, no conversation identifiers, no raw exception text.
- **Uninstaller:** override option deleted iff `remove_data_on_uninstall` is on.
- **Poll response:** `GET /conversations/{uuid}` carries `availability`, and it flips when
  the clock/override crosses a boundary between polls (injected clock).
- **Interop:** the existing dual-plugin suite still green on both WP/PHP variants
  (no Contract/adapter change).

### Automated — widget static

- No `innerHTML` anywhere in `chat-widget.js`; offline message + confirmation rendered via
  `.textContent`; the "online" pill element is only added when `availability === 'available'`.

### Manual / browser QA

- Operator sets Mon–Fri 12:00–15:00; open the widget at 14:59 and again at 15:01 — offline
  state appears without a reload (after the next poll); the "online" pill disappears.
- `Force offline` during hours ⇒ widget offline immediately after next poll; `Force online`
  outside hours ⇒ widget online.
- Override with a 30-minute expiry ⇒ auto-returns to `Automatic`.
- Visitor leaves an offline message; operator opens the **Waiting** view, replies from the
  Hub; visitor sees the reply on poll.
- Telegram adapter deactivated mid-flow ⇒ offline ticket + Hub reply unaffected.
- Screen reader announces the offline notice and the post-send confirmation (`role="status"`);
  mobile full-screen sheet; RTL; `prefers-reduced-motion`.
- **Human assistive-technology (VoiceOver/NVDA) smoke is a recommendation, reported
  separately from automated results — not claimed as passed unless actually run.**

## 18. DEV vs production acceptance (kept separate)

### DEV acceptance (after the implementation PR, on a deliberate operator decision — not part of this task)

1. Deploy via the existing bind-mounted checkout; confirm `db_version` still `12`, no new
   web-container PHP warnings/fatals, homepage + wp-admin health checks pass.
2. Configure Mon–Fri 12:00–15:00 (site tz) and one `closed` date exception; verify the
   widget's resolved state matches wall-clock expectations and that the start/message POST
   responses carry the resolved `availability` (a widget opened before a boundary corrects
   itself — no stale "online").
3. Override: set `Force offline` with a null expiry, confirm Hub + Diagnostics show "until
   cleared"; set a short dated expiry, confirm auto-return to `Automatic`.
4. **Telegram — both required:**
   - *(a)* With the adapter **absent or dispatch disabled**: a visitor's offline message
     creates a `waiting_for_operator` conversation, an operator answers it from the Hub
     Waiting view, and the visitor sees the reply — end to end, no adapter involved.
   - *(b)* With dispatch **enabled**: the same offline message still creates and transitions
     the ticket synchronously in the request, while any Telegram mirror is performed **only**
     by the async `DispatchWorker` (WP-Cron) — verified by confirming no Telegram API call
     occurs in the visitor request and that disabling cron does not block ticket creation or
     the `waiting_for_operator` transition.
5. Confirm the Telegram dispatch setting and the active `universal-telegram` peer are
   unchanged; no webhook, pairing, credential, or Universal Telegram change.

### Production acceptance

Explicitly out of scope for SC-M06. A separate, later Product Owner decision; no release or
tag is created by this milestone's documentation or implementation.

## 19. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A regression re-introduces an untrue "online" claim | ADR-0017 §5 honesty clause; widget static test asserts the pill only renders when `available`; manual boundary test. |
| An override left `Force offline` forever | `null` expiry is deliberate and allowed, but surfaced prominently in Hub + Diagnostics + audit; a dated expiry is one click away. |
| DST edge confusion | `DateTimeZone`/`DateTimeImmutable` only; explicit spring/fall unit tests. |
| Scope creep into ETA / SLA copy | Explicit exclusion in §20 and ADR-0017 §5 (needs a new ADR). |
| Atomic-validation bug silently normalises a bad schedule to default | Dedicated integration test asserts the *prior* value survives a rejected submission. |
| Transaction path leaves an orphan message on transition failure | Dedicated rollback integration test with a forced-failure transition. |
| Coupling to Universal Telegram | No UT class/SQL/route/credential referenced; the offline path uses only the existing `DispatchEnqueuer` seam; interop suite proves no adapter regression. |

## 20. Out of scope

- Promised response-time / ETA / SLA copy or timers (needs a new ADR).
- Live operator-presence signal.
- Per-operator or per-team schedules.
- Telegram `/support` command or any Telegram availability control (adapter-owned, R5).
- A public unauthenticated availability REST endpoint.
- Any new REST route, DB table, column, or `db_version` change.
- Visitor email / phone / subject capture.
- AI / RAG / embeddings / automated answers.
- Contract v1 changes; any Universal Telegram change.
- Changes to retention behaviour beyond confirming offline tickets follow the existing
  retention path.
- DEV or production deployment; a plugin release or tag; a closure record (all later,
  separate acts).

## 21. Definition of done (matches charter acceptance criteria)

- **R7 / charter:** a visitor's offline message creates a durable Support Chat
  `waiting_for_operator` conversation **with Telegram uninstalled**, answerable from the Hub;
  proven by automated integration + DEV acceptance §18(a).
- **R5 / charter:** Support Chat owns and evaluates the weekly schedule, date exceptions,
  and the `Automatic / Force online / Force offline` override in the site timezone, with the
  frozen precedence; the Hub manages the waiting conversations through an explicit Waiting
  view.
- **Charter:** with an adapter connected, notify (the existing ADR-0012 mirror) may occur
  but is never on the ticket-creation critical path; proven by DEV acceptance §18(b) and the
  dispatch-enabled integration test.
- No widget copy makes an untrue availability or response-time claim.
- All CI gates green (PHPCS, PHPStan, unit ×2 PHP, integration ×2 variants, interop ×2
  variants, doc-links); browser QA to the SC-M05 standard; human AT smoke reported
  separately.
- No schema / `db_version` / capability / Contract / Universal Telegram / AI change; no
  release, tag, DEV, or production change as part of the milestone's implementation PR.

## 22. Product Owner decisions

All of the following are **already frozen** by ADR-0017 and the PO authorization; they are
listed here for the acceptance record with the decision taken:

| # | Question | Decision (frozen) |
|---|---|---|
| 1 | Default schedule | **Monday–Friday, 12:00–15:00, site timezone.** |
| 2 | Positive "We're online" indicator | **Yes — subtle, shown only when truly `available`** (`availability_online_indicator`, default on). |
| 3 | Public unauthenticated availability endpoint | **No — not in SC-M06.** |
| 4 | Offline message variants | **One global message**, not per-exception / per-out-of-hours. |
| 5 | Default offline message | `The support team is offline right now. Leave your message here and we'll reply in this chat when we're back.` |
| 6 | Override "until cleared" (null expiry) | **Allowed as a first-class persistent state**, visible in Hub + Diagnostics; only expired non-null overrides are lazily reaped. |
| 7 | Capability | **Reuse `CapabilityRegistrar::MANAGE`** — no new capability. |
| 8 | Schema | **No schema / `db_version` change.** |

No open decision blocks implementation.
