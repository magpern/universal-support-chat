# Closure — SC-M06: Support Availability and Offline Tickets

## Status

**Closed (PASS WITH LIMITATIONS). Merged to `main`. Not deployed by this record.**

Documentation-only closure record. No runtime code, test, plugin-version, schema, settings,
CI, dependency, Universal Telegram, DEV, production, deployment, tag, or release change is
made by this record.

The accepted limitations are two post-merge validation activities this environment could
not run and that are **not** claimed as passed:

1. **Browser QA to the SC-M05 standard** (Playwright + axe-core + Lighthouse against the
   honest offline / online widget chrome). Not executed here; recommended as part of, or
   immediately before, DEV acceptance.
2. **Human assistive-technology smoke** (VoiceOver / NVDA) of the offline widget state and
   its `role="status"` announcement — the same limitation shape carried by SC-M05.

Neither limitation blocks the merge (already done). DEV acceptance and production remain
separate, explicitly-authorized later steps (frozen plan v2 §18).

## What this closes

SC-M06 ([charter](../milestones/sc-m06-support-availability-and-offline-tickets.md),
requirements **R5** / **R7**), realising
[ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
(Accepted) exactly within the frozen scope of
[plan v2](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md) §16 (WP1–WP8):

- Support Chat is the **sole availability authority** — weekly schedule, date exceptions,
  and the `Automatic / Force online / Force offline` manual override are owned and evaluated
  in-process, in the **WordPress site timezone**, with the frozen precedence
  **manual override → date exception → weekly schedule → fail-safe `unavailable`**;
- **honest visitor widget behaviour** — never an untrue "online" or response-time claim; a
  plain offline notice and a post-send confirmation; the "We're online" pill only when
  genuinely available;
- **offline ticket = existing authenticated conversation** — a visitor message accepted
  while the server resolves `unavailable` is committed, in **one transaction**, together
  with any existing content-free ADR-0012 dispatch-outbox row and the conversation
  transition to `waiting_for_operator`; one new `ConversationStatus` map edge
  **`new → waiting_for_operator`** (a code constant — no schema, no `db_version` change);
- **Hub Waiting view**, **Diagnostics availability block**, **audit events**, additive
  Settings keys with **atomic all-or-nothing validation**, a dedicated autoloaded override
  option changed only through a nonce + `MANAGE` `admin_post` action;
- repository-code-only version increase **`0.7.0 → 0.8.0`**;
- the plan §17 automated test matrix.

## Gates and SHAs

| Gate | SHA / URL |
|---|---|
| SC-M06 documentation freeze (plan v2 + ADR-0017 as Proposed) | `cdfcd5ada3de635365d9051c42b6b7da80c82b16` — [PR #51](https://github.com/magpern/universal-support-chat/pull/51) |
| Product Owner implementation acceptance (ADR-0017 → Accepted) | `e7518bbed703ecf2b57766ee61f11a8b785461b1` — [PR #52](https://github.com/magpern/universal-support-chat/pull/52) (`docs/decisions/sc-adr-0017-availability-po-acceptance.md`) |
| Implementation PR | [PR #53](https://github.com/magpern/universal-support-chat/pull/53) |
| Implementation branch | `feature/sc-m06-support-availability-and-offline-tickets` |
| First implementation push (WP1–WP8, reviewed) | `fd9aaef63f3e5c290e71c52f0c44d5395eac2ca7` |
| Review-fix round 1 head (reviewed) | `94e2e3cc38411c0f4b2f88358175ae91bf62886b` |
| Review-fix round 2 head (reviewed, merged content) | `d9f76468dee2959083c04cf56d91aa43629a0275` |
| **Squash-merge commit on `main`** | **`f3b327b79185f02130571a8cdc074b77b8e094f9`** |

Both baselines are verified ancestors of `origin/main` (`cdfcd5a` and `e7518bb` precede the
merge commit `f3b327b`). ADR-0017 is **Accepted**; plan v2 is frozen and its authorization
line cites the PO-acceptance record. Plan v2 supersedes the original product-boundary stub
[plan v1](../plans/sc-m06-support-availability-and-offline-tickets-plan-v1.md) (retained
unedited); the plan was not revised again during the milestone.

## What shipped

### Availability domain (WP1)

New `src/Availability/` namespace (the structural-boundary unit test and `docs/ARCHITECTURE.md`
were updated to authorize it):

- `AvailabilityState` — `AVAILABLE` / `UNAVAILABLE` enum.
- `TimeInterval` — half-open `[start, end)` minutes-since-midnight; strict `HH:MM` parsing;
  `start < end` enforced.
- `WeeklySchedule` — seven weekday keys (`mon`–`sun`), each a list of intervals;
  `default_schedule()` = **Mon–Fri 12:00–15:00**; wholly-blank interval rows tolerated on
  input; `is_open_at(DateTimeImmutable)`.
- `DateException` — `closed()` / `special_hours(intervals)`; `ExceptionSet` parses both the
  canonical `{date: value}` map and the Settings-form "rows" shape, **rejecting a duplicate
  exception date and an unrecognised `mode`** rather than silently discarding.
- `AvailabilityOverride` — `{ mode, expires_at|null, set_by, set_at }`; a **null expiry is a
  first-class "until cleared" state**; `is_active(int $now)`.
- `InvalidScheduleException` — the single validation failure type.
- `AvailabilityResolver` — pure `resolve()` implementing the frozen precedence; never throws,
  no WordPress calls; fully unit-tested including DST spring-forward / fall-back.
- `AvailabilityService` — the one WordPress-facing seam: loads schedule / exceptions from
  `Settings`, the override from its own autoloaded option, obtains "now" via
  `current_datetime()`, **reaps an expired non-null override** (delete + audit) on read, and
  falls back to `unavailable` on unparseable stored config **without rewriting the stored
  value**.

### Offline ticket — one-transaction commit and the new map edge (WP1b)

- `src/Conversations/ConversationStatus.php` — exactly one new edge:
  `NEW → { OPEN, WAITING_FOR_OPERATOR, ARCHIVED }` (adds `WAITING_FOR_OPERATOR`).
- `src/Conversations/Rest/ConversationsController.php` — takes an optional
  `AvailabilityService`; when the server resolves `unavailable` at message-accept time, the
  visitor message row, any existing ADR-0012 outbox row, **and** the transition to
  `waiting_for_operator` are committed as **one unit of work** (via
  `DispatchEnqueuer::persist_and_enqueue()` with a new optional `?callable $within_transaction`
  parameter when dispatch is wired, else an inline transaction), rolling back fully if the
  transition fails. `handle_start`, `handle_mine`, `handle_poll` and `handle_post_message`
  responses carry the freshly resolved `availability`.
- `src/TelegramDispatch/DispatchEnqueuer.php` — the new callback runs inside the forced
  transaction after the message (+ outbox when enabled) is staged; a `false` return rolls
  the whole unit back. No Telegram I/O in the visitor request — all of it stays in the
  post-commit WP-Cron worker (ADR-0014).
- `src/Conversations/ConversationRepository.php` — `list_waiting()`:
  `status IN (waiting_for_operator, new)` ordered `updated_at ASC, id ASC`.

### Settings — additive keys and atomic validation (WP3)

`src/Core/Configuration/Settings.php` — `defaults()` / `get()` / `sanitize()` extended with
four keys, fixed-shape preserved:

| Key | Rule | Default |
|---|---|---|
| `availability_schedule` | parsed by `WeeklySchedule::from_array()` → canonical map | Mon–Fri 12:00–15:00 |
| `availability_exceptions` | parsed by `ExceptionSet::from_array()` → canonical `{date: value}` map | `[]` |
| `availability_offline_message` | plain text (`sanitize_textarea_field()`) | `Settings::DEFAULT_OFFLINE_MESSAGE` |
| `availability_online_indicator` | boolean | off |

**Atomic all-or-nothing validation** — `sanitize_availability_section()` validates the
schedule and the exceptions **together**. If either fails to parse, **both** prior stored
values are preserved and exactly one `add_settings_error()` is raised; malformed input is
never normalised to the default and never partially applied. Runtime-unparseable stored
config is handled separately by `AvailabilityService` (fail-safe `unavailable`, value not
rewritten, Diagnostics warning).

### Manual override option and admin action (WP2)

- `AvailabilityService::OVERRIDE_OPTION` = `universal_support_chat_availability_override`
  (autoloaded); absent ⇒ `Automatic`.
- `src/Availability/Admin/OverrideAction.php` — capability (`MANAGE`) + nonce checked;
  set / clear via `admin_post`; a past expiry is rejected; records
  `availability.override_set` / `availability.override_cleared`.
- Expired non-null overrides are reaped **both** lazily on read **and** by a cheap tick on
  the existing daily `RetentionCleanupHandler` cron job (which now takes an optional
  `AvailabilityService`, skips the tick on a dry run, and records
  `availability.override_expired`). No dedicated cron event was added.
- `src/Core/Lifecycle/Uninstaller.php` — the option is deleted only under
  `remove_data_on_uninstall`.

### Administration surfaces (WP3 / WP5 / WP6)

- `src/Administration/Settings/SupportChatSettingsPage.php` — a new **"Availability"**
  section (schedule, date exceptions, offline message, online-indicator toggle) on the
  existing ADR-0015 Settings page; hooks `updated_option` / `added_option` to record
  `availability.schedule_updated` / `availability.exceptions_updated` **only on an actual
  stored-value change** (not on a rejected submission or an unrelated-field save), with a
  content-free context (a bare "changed" marker — no schedule contents, dates, copy, or
  identifiers). Actor is `operator` + the real user id when a user is logged in, `system` + `0`
  when there is none (e.g. a WP-CLI or programmatic save).
- `src/Administration/Conversations/ConversationInboxPage.php` — an explicit Hub **"Waiting"**
  filter (`?status=waiting` → `list_waiting()`), plus a compact override set / clear control
  and the availability notice.
- `src/Administration/Diagnostics/DiagnosticsPage.php` — a read-only **"Availability"** block
  (resolved state, active mode, override expiry / "until cleared", schedule-config-valid
  yes/no) and an admin-only fail-safe warning. Safe aggregates only.

### Widget (WP4)

- `src/ChatWidget/WidgetAssets.php` — server-renders `availability`, `offlineMessage`,
  `showOnlinePill` and `i18n.online` / `i18n.offlineConfirm` into the `uscChatWidget`
  payload; the shell gains an `#usc-chat-online` pill region and an `#usc-chat-offline`
  notice region (both empty + hidden until state is applied).
- `assets/js/chat-widget.js` — `applyAvailability(state)` renders the offline notice and the
  post-send confirmation via `.textContent`, keeps the offline confirmation sticky, shows
  the "We're online" pill **only** when the resolved state is genuinely available, and
  re-applies availability from every poll and POST response so a widget opened before a
  schedule boundary corrects itself.
- `assets/css/chat-widget.css` — `.usc-chat__online` / `.usc-chat__offline` styling; ADR-0016
  dialog / focus / mobile / RTL / reduced-motion behaviour untouched.

### Version (WP6)

`universal-support-chat.php` — `Version: 0.8.0` header and
`define( 'UNIVERSAL_SUPPORT_CHAT_VERSION', '0.8.0' )` (from `0.7.0`), for asset
cache-busting. **In repository code only** — no `git` tag, no GitHub Release.

## Review rounds carried in the merged content

Round-1 review raised three defects; round-2 raised two more. All are resolved in the merged
squash `f3b327b`:

1. **Non-atomic Availability-section validation** — schedule and exceptions were validated
   independently; now combined so either failure preserves **both** prior values and raises
   one settings error.
2. **Silent data loss in date exceptions** — `ExceptionSet::rows_to_map()` overwrote earlier
   rows sharing a date and let an unknown `mode` fall through; now both are rejected
   explicitly.
3. **Missing frozen audit events** — `availability.schedule_updated` /
   `availability.exceptions_updated` added.
4. **Override not reaped by cron** — `RetentionCleanupHandler` now gives the existing daily
   job a tick that reaps an expired override even when nothing renders the widget, Hub, or
   Diagnostics.
5. **Settings-audit actor** — recorded as `system` / `0` when there is no logged-in user,
   not a fabricated `operator` / `0`.

## Files landed (36; squash `f3b327b`)

**New source (10)** — `src/Availability/AvailabilityState.php`, `TimeInterval.php`,
`WeeklySchedule.php`, `DateException.php`, `ExceptionSet.php`, `AvailabilityOverride.php`,
`InvalidScheduleException.php`, `AvailabilityResolver.php`, `AvailabilityService.php`,
`src/Availability/Admin/OverrideAction.php`.

**Modified source (10)** — `src/Core/Plugin.php`, `src/Core/Configuration/Settings.php`,
`src/Core/Lifecycle/Uninstaller.php`, `src/Conversations/ConversationStatus.php`,
`src/Conversations/ConversationRepository.php`,
`src/Conversations/Rest/ConversationsController.php`,
`src/Conversations/RetentionCleanupHandler.php`,
`src/TelegramDispatch/DispatchEnqueuer.php`, `src/ChatWidget/WidgetAssets.php`,
`src/Administration/Settings/SupportChatSettingsPage.php`,
`src/Administration/Conversations/ConversationInboxPage.php`,
`src/Administration/Diagnostics/DiagnosticsPage.php`. (Assets:
`assets/js/chat-widget.js`, `assets/css/chat-widget.css`. Version:
`universal-support-chat.php`.)

**Tests** — new `tests/unit/Availability/AvailabilityResolverTest.php`,
`tests/unit/Availability/AvailabilityValueObjectsTest.php`,
`tests/integration/Availability/AvailabilityServiceTest.php`,
`tests/integration/Conversations/OfflineTicketTest.php`,
`tests/integration/Administration/AvailabilityAdminTest.php`; updated
`tests/integration/Conversations/RetentionCleanupHandlerTest.php`,
`tests/integration/ChatWidget/WidgetShellRenderTest.php`,
`tests/unit/ChatWidget/WidgetAssetsTest.php`,
`tests/unit/Core/Configuration/SettingsTest.php`,
`tests/unit/Core/StructuralBoundariesTest.php`.

**Docs** — `docs/ARCHITECTURE.md` (Availability boundary row).

## Tests and CI

**PR #53 CI — all green** on the merged head `d9f7646` (10 jobs, runs
[`33364276201`](https://github.com/magpern/universal-support-chat/actions/runs/33364276201)
and
[`33364279757`](https://github.com/magpern/universal-support-chat/actions/runs/33364279757)):
PHPCS, PHPStan level 5, unit ×3 (PHP 8.1 / 8.3 / 8.4), integration WordPress-only floor
(WP 6.9 / PHP 8.1) and current (WP 7.1 / PHP 8.3), interop (6.9 / 8.1) and interop
(7.1 / 8.3) against the CI-pinned Universal Telegram commit
(`9b4a6ef2bfc56b4bb514567c797d41c8a285727a`), and check-doc-links.

**Local gates (fresh test database)** — PHPCS clean; PHPStan level 5 clean; unit green on
PHP 8.1 and 8.4 (135 tests); integration WP-only green on both WP 6.9 / PHP 8.1 and
WP 7.1 / PHP 8.3 (207 tests, 752 assertions each); check-doc-links clean. Interop is
validated only by CI against the pinned Universal Telegram SHA — a local interop run is not
representative because the local Universal Telegram checkout is ahead of that pin (baseline
`main` fails it identically locally).

New automated coverage includes: the resolver truth table (in-hours, out-of-hours, boundary
minute, DST both directions, `closed` exception beats an in-hours schedule, special-hours
exception replaces the day, empty schedule ⇒ fail-safe, malformed **stored** schedule ⇒
fail-safe `unavailable` without rewriting the value); override precedence, null-expiry
persistence, past-expiry reaped on read **and** by the scheduled cron path (proven without
any widget / Hub / Diagnostics render), and clear; the one-transaction
message + outbox + transition commit and its full rollback on a forced transition failure;
the `new` / `open` / `waiting_for_visitor` → `waiting_for_operator` edge on a visitor
message while `unavailable` (and no transition change while `available`); offline ticket
creation with the adapter absent (R7) and idempotent re-submit; the POST / poll responses
carrying `availability`; atomic Settings validation (mixed-case: bad schedule + good
exceptions and vice versa both reject the whole section and preserve both priors);
exception-row duplicate-date and unknown-mode rejection; the two settings audit events
firing only on a real change and with the correct `system` / `operator` actor; Diagnostics
redaction; `Uninstaller` removes the override option; the widget offline copy path uses
`.textContent`.

## Post-merge recommended validation (the accepted limitations)

### Browser QA to the SC-M05 standard

The frozen plan v2 §17 / §21 lists **browser QA to the SC-M05 standard** (Playwright +
axe-core + Lighthouse against a disposable WordPress container, `universal-support-chat`
only) covering the offline notice, the post-send confirmation, the "We're online" pill
appearing only when available, the `role="status"` region, mobile sheet, RTL and
reduced-motion. This environment did not run it and it is **not** claimed as passed. The
existing `role="status"` `aria-live="polite"` region and the automated string-scans address
the same surface indirectly but do not substitute for it.

**Recommended, post-merge:** run the SC-M05-style disposable browser QA (or fold it into the
DEV-acceptance session) and attach the evidence.

### Human assistive-technology smoke

Plan v2 §13 lists a **VoiceOver + NVDA screen-reader smoke** of the offline widget state.
This environment has no screen-reader host, so it **was not run** and is **not** claimed as
passed.

**Recommended, post-merge:** a person runs VoiceOver (macOS / Safari) **or** NVDA
(Windows / Firefox) against a page with the widget enabled while the resolved state is
`unavailable`, and confirms the offline notice and the post-send confirmation are announced
via the live region and that the panel stays non-modal without a Tab trap. Record the
screen-reader + browser + OS versions.

Neither limitation blocks the merge (already done) or gates any deployment decision; both
are quality follow-ups, most naturally completed alongside DEV acceptance.

## Explicit non-implementation / unchanged

Per ADR-0017, plan v2 §20, and the PO-acceptance record — none of the following was touched:

- **Schema / migration** — `universal_support_chat_db_version` stays **12**;
  `Migrator::target_version()` is untouched. The `ConversationStatus` map gains one edge, a
  pure code constant; no stored data is touched and existing rows in any status stay valid.
- **REST** — no new route (no public availability endpoint); an `availability` field was
  added to existing authenticated conversation/message responses only; visitor isolation and
  the authenticated-only path are unchanged.
- **Capabilities** — no new capability; `MANAGE` gates schedule edits and override changes.
- **Options** — one new autoloaded option (`universal_support_chat_availability_override`)
  and four additive keys on the fixed-shape `universal_support_chat_settings`; existing keys
  unchanged.
- **Telegram / Universal Telegram** — no new Telegram mechanism; where ADR-0012 dispatch is
  already enabled, an offline message is mirrored by the **existing** `DispatchWorker`
  exactly as any other committed visitor message, entirely in WP-Cron. The interop run used
  a read-only checkout of Universal Telegram pinned at the CI ref `9b4a6ef`; that repository
  is untouched. No Contract v1 change.
- **AI / RAG** — none.
- **Presence / SLA / ETA** — no live operator-presence signal, no promised-response-time or
  SLA copy or timers, no per-operator or per-team schedules.
- **Visitor contact capture** — none beyond the existing authenticated WP identity.
- **CI / dependencies / build tooling** — no workflow change; no Composer, npm, or
  browser-CI change.
- **Retention** — unchanged beyond giving the existing daily job the override-reaping tick;
  offline tickets follow the existing retention / uninstall paths.

## Non-authorization

This closure authorizes nothing operational. The feature is merged to `main` at
`f3b327b79185f02130571a8cdc074b77b8e094f9` but has **not** been deployed to DEV or
production. No plugin was activated, deactivated, or updated on any live site; no
`wp option` value was changed on any live site; no live setting or data operation occurred;
no Telegram message, webhook, bot, group, topic, pairing, or credential action occurred; no
GitHub Release or version tag was created. Deploying to DEV (with the plan v2 §18 acceptance
steps, including both the adapter-absent and dispatch-enabled Telegram checks) and later
production are separate, explicitly-authorized steps.

## Documents

- [SC-M06 charter](../milestones/sc-m06-support-availability-and-offline-tickets.md)
- [Plan v2 — `sc-m06-support-availability-and-offline-tickets-plan-v2.md`](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md) — frozen; authorization line cites the PO-acceptance record.
- [Plan v1 — `sc-m06-support-availability-and-offline-tickets-plan-v1.md`](../plans/sc-m06-support-availability-and-offline-tickets-plan-v1.md) — original product-boundary stub, superseded, retained unedited.
- [ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md) — Accepted.
- [`docs/decisions/sc-adr-0017-availability-po-acceptance.md`](../decisions/sc-adr-0017-availability-po-acceptance.md) — Approved.
- [Feature PR #53](https://github.com/magpern/universal-support-chat/pull/53)

## Next milestone

Per the locked execution order in [`docs/milestones/README.md`](../milestones/README.md):
SC-AI1, then SC-AI2 (SC-M06 recommended before SC-AI2). SC-M06 introduces no new dependency
for them beyond what SC-M02 established. DEV deployment and Product Owner acceptance of
SC-M06 remain outstanding and will be recorded separately (as SC-M05's DEV acceptance was).
