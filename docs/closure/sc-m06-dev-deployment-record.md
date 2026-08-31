# SC-M06 — DEV Deployment Record

## Status

**DEV deployed. Product Owner functional acceptance still outstanding.** Documentation-only
record.

Extends the [SC-M06 closure](sc-m06-support-availability-and-offline-tickets-closure.md)
(Closed — PASS WITH LIMITATIONS). This record adds nothing to scope: no runtime plugin
code, test, plugin-version, schema, settings, database data, CI, Compose, Universal
Telegram, production, tag, or release change is made by it. Production remains untouched.

It records that the SC-M06 feature is now **live on DEV** and passed post-deployment
technical health checks. It does **not** claim the plan v2 §18 functional acceptance
walkthrough, nor a Product Owner functional sign-off — both remain to be done (see
[Outstanding](#outstanding)).

## What this records

The SC-M06 "Support Availability and Offline Tickets" feature — merged to `main` in
[PR #53](https://github.com/magpern/universal-support-chat/pull/53) at
`f3b327b79185f02130571a8cdc074b77b8e094f9`, closed in
[PR #54](https://github.com/magpern/universal-support-chat/pull/54) at
`19f1948c4e14da9c95fe487c9f56c92cd1c51253` — is now live on **DEV**
(`https://dev.biopentra.eu`).

The DEV checkout was advanced to `origin/main` head
`4cdd213e0b9a5da1cf6802063b05196402a68b7f`, which is `f3b327b` (SC-M06) plus the SC-M06
closure record and **one out-of-milestone bug fix**:
[PR #55](https://github.com/magpern/universal-support-chat/pull/55)
(`fix(widget): stop the first visitor message rendering twice in the transcript`) — a
client-side de-duplication in `assets/js/chat-widget.js`, no server, schema, version, or
Telegram change. It is disclosed here because it rode the same DEV update; it is not part of
SC-M06's frozen scope and has no closure record of its own (a bug fix, tracked by Git
history).

## Deployment method

DEV deployment occurred **through the existing bind-mounted Support Chat checkout** — no new
mechanism and no Compose change. The dev VPS bind-mounts
`/opt/biopentra/dev/universal-support-chat` into the running WordPress container at
`/var/www/html/wp-content/plugins/universal-support-chat` (read-write); advancing that
checkout's Git ref is the deployment.

The checkout had been left on `b3bc9d9` (the SC-M05 DEV-deployment state) and was 5 commits
behind `origin/main`. It was advanced by a **Git fast-forward only** —
`git pull --ff-only origin main` (`b3bc9d9..4cdd213`, no merge commit, no conflict, working
tree clean apart from an untracked local `.claude/` directory that is not served).

The `wordpress` container was then **restarted** (`docker compose restart wordpress`) to
load the new PHP under a fresh opcache. The container was **restarted, not recreated** —
same container, same image `biopentra-wordpress:php8.3-redis`; `StartedAt` moved to
`2026-08-31T13:46:31Z`. No other service was touched.

| Item | Value |
|---|---|
| DEV checkout path | `/opt/biopentra/dev/universal-support-chat` |
| Final DEV branch | `main` |
| Previous DEV checkout SHA | `b3bc9d9b3d1c370324455535dc29bd4c0a79390d` (SC-M05 DEV state) |
| **Final DEV checkout SHA** | **`4cdd213e0b9a5da1cf6802063b05196402a68b7f`** (= `origin/main`) |
| Loaded plugin version | **`0.8.0`** (`Version:` header, `UNIVERSAL_SUPPORT_CHAT_VERSION`, `wp plugin list`) |
| `universal_support_chat_db_version` | **`12`** — unchanged (SC-M06 adds no migration) |
| WordPress container | restarted (not recreated); image unchanged; `StartedAt 2026-08-31T13:46:31Z` |
| `universal-telegram` bind mount | not touched |

## Health checks (post-deployment)

| Check | Result |
|---|---|
| `GET https://dev.biopentra.eu/` | **HTTP 200**; enqueues `chat-widget.css?ver=0.8.0` and `chat-widget.js?ver=0.8.0`; DOM carries `#usc-chat-root`, `usc-chat-launcher`, and the new `#usc-chat-online` / `#usc-chat-offline` availability regions |
| `GET /wp-admin/` (unauthenticated) | **HTTP 302 → wp-login** (expected) |
| Public host listeners (`ss -tuln`) | exactly `80`, `443`, `2222` |
| Support Chat plugin | **active**, version **`0.8.0`** |
| `universal_support_chat_db_version` | **`12`** |
| New PHP warnings / fatals in the WordPress web container | **none** — `docker compose logs wordpress` for the deployment window shows zero `PHP (Fatal\|Warning\|Notice\|Deprecated)` / `Uncaught` lines |
| Availability resolution (`AvailabilityService` via `wp eval`) | resolves **`available`**; `schedule_config_is_valid` = **yes**; `current_mode` = **`automatic`**; default schedule served as **Mon–Fri 12:00–15:00** through `Settings::defaults()` |
| Manual override option (`universal_support_chat_availability_override`) | **absent** — system is in `Automatic` |

## Integration state — verified unchanged (read-only)

- **Telegram dispatch setting:** `universal_support_chat_settings.telegram_dispatch_enabled`
  = `true` — unchanged; the stored option is unchanged (the four SC-M06 availability keys
  are served from `Settings::defaults()` until an operator saves the Settings page).
- **Channel peer** (`wp_universal_support_chat_channel_peers`, one row): `peer_id =
  universal-telegram`, `status = active`, `outbound_route_base =
  universal-telegram/v1/support-chat`, `created_at = 2026-08-28 20:05:46`,
  `last_rotated_at = NULL`, `revoked_at = NULL`, `expires_at = NULL` — unchanged. Universal
  Telegram's own peer row (`universal-support-chat`) is `active`, unchanged.
- **No Telegram traffic, webhook call, pairing action, credential action, or Universal
  Telegram change** occurred during or as a result of this deployment. No test that sends
  Telegram traffic was run.

## Site timezone note

The DEV WordPress site timezone is **UTC (`+00:00`)**. SC-M06 evaluates the schedule and
date exceptions in the site timezone (`wp_timezone()`), so for Sweden support hours the
operator must set Settings → General → Timezone to `Europe/Stockholm` before the default
Mon–Fri 12:00–15:00 window means local noon–15:00. This is operator configuration, not a
deployment defect.

## Outstanding

1. **Plan v2 §18 functional DEV acceptance — not yet performed.** Specifically:
   - configure Mon–Fri 12:00–15:00 (site tz) + one `closed` date exception, and confirm the
     widget's resolved state matches wall-clock expectations and that the start / message
     POST responses carry the resolved `availability`;
   - override: set `Force offline` (null expiry) → confirm Hub + Diagnostics show "until
     cleared"; set a short dated expiry → confirm auto-return to `Automatic`;
   - **Telegram (both):** *(a)* with the adapter absent or dispatch disabled, a visitor's
     offline message creates a `waiting_for_operator` conversation answerable from the Hub;
     *(b)* with dispatch enabled, the ticket is created and transitioned synchronously while
     any Telegram mirror stays worker-only (no Telegram call in the visitor request, cron
     off does not block ticket creation).
2. **Product Owner functional sign-off** of the DEV result — not given. When it is, this
   record's status line should be updated (or a follow-up acceptance record added), mirroring
   `sc-m05-dev-deployment-acceptance.md`.
3. **The two closure limitations remain open:** SC-M05-standard browser QA of the
   offline / online widget chrome, and a VoiceOver / NVDA screen-reader smoke of the offline
   state.

## Non-authorization

This record authorizes nothing further. It documents a DEV deployment that has already
happened. **Production is untouched.** No GitHub Release or version tag was created. No
runtime plugin code, test, plugin version, schema, database data, or settings changed, and
no Universal Telegram artifact was touched. A production deployment is a separate step
requiring its own explicit authorization.

## References

- [SC-M06 closure](sc-m06-support-availability-and-offline-tickets-closure.md)
- [SC-M06 charter](../milestones/sc-m06-support-availability-and-offline-tickets.md)
- [ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md) — Accepted
- [Plan v2 — `sc-m06-support-availability-and-offline-tickets-plan-v2.md`](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md) — §18 DEV acceptance steps
- [`docs/decisions/sc-adr-0017-availability-po-acceptance.md`](../decisions/sc-adr-0017-availability-po-acceptance.md) — implementation acceptance
- Implementation [PR #53](https://github.com/magpern/universal-support-chat/pull/53) (`f3b327b`) · closure [PR #54](https://github.com/magpern/universal-support-chat/pull/54) (`19f1948`) · out-of-milestone widget fix [PR #55](https://github.com/magpern/universal-support-chat/pull/55)
