# SC-M05 — DEV Deployment and Acceptance Record

## Status

**DEV deployed. Product Owner accepted the DEV result.** Documentation-only record.

Extends the [SC-M05 closure](sc-m05-professional-widget-experience-closure.md) (Closed —
PASS WITH LIMITATIONS). This record adds nothing to scope: no runtime plugin code, test,
plugin-version, schema, settings, database data, CI, Docker configuration, service state,
Universal Telegram, production, tag, or release change is made by it. (The DEV checkout was
briefly used to stage this documentation branch; that is a Git-branch operation, not a change
to any of the above.) Production remains untouched.

## What this records

The SC-M05 "Professional Widget Experience" feature — merged to `main` in
[PR #48](https://github.com/magpern/universal-support-chat/pull/48) at
`ceb5284fe51c1f37a52895b4f43ed422376ef902`, closed in
[PR #49](https://github.com/magpern/universal-support-chat/pull/49) at
`b3bc9d9b3d1c370324455535dc29bd4c0a79390d` — is now live on **DEV**
(`https://dev.biopentra.eu`), and the Product Owner has manually verified and approved the
DEV result.

## Deployment method

DEV deployment occurred **through the existing bind-mounted Support Chat checkout** — no new
mechanism, container action, or Compose change. The dev VPS bind-mounts
`/opt/biopentra/dev/universal-support-chat` into the running WordPress container at
`/var/www/html/wp-content/plugins/universal-support-chat` (read-write); advancing that
checkout's Git ref is the deployment.

The checkout had been left on the SC-M05 closure feature branch after PR #49's squash merge.
The checkout was realigned by a Git-only fast-forward — `git checkout main` followed by
`git merge --ff-only origin/main` (`cf558d1..b3bc9d9`, no merge commit, no conflict). The
served runtime contents were byte-identical before and after; no request landed during the
brief branch switch. (`git checkout` did rewrite the bind-mounted files — the inode changed —
but the file contents stayed identical.)

| Item | Value |
|---|---|
| DEV checkout path | `/opt/biopentra/dev/universal-support-chat` |
| Final DEV branch | `main` |
| **Final DEV checkout SHA** | **`b3bc9d9b3d1c370324455535dc29bd4c0a79390d`** (= `origin/main`) |
| Loaded plugin version | **`0.7.0`** (`Version:` header, `UNIVERSAL_SUPPORT_CHAT_VERSION`, and `wp plugin list`) |
| `universal_support_chat_db_version` | **`12`** — unchanged |
| Bind mount | live — host and container resolve the plugin file to the same inode and the same SHA-256 |
| WordPress container | not recreated (`RestartCount=0`; started 2026-08-28) |

## Health checks (read-only, post-deployment)

| Check | Result |
|---|---|
| Homepage `GET https://dev.biopentra.eu/` | **HTTP 200**; page enqueues `chat-widget.css?ver=0.7.0` and `chat-widget.js?ver=0.7.0`, with the widget shell (`#usc-chat-root`, `usc-chat-launcher`) in the DOM |
| `GET /wp-admin/` (unauthenticated) | **HTTP 302 → wp-login** (expected) |
| `GET /wp-login.php` | **HTTP 200** |
| Support Chat plugin | **active**, version **`0.7.0`** |
| `universal_support_chat_db_version` | **`12`** |
| New PHP warnings/fatals in the WordPress web container | **none** — `docker logs wordpress` for the deployment window shows zero `PHP (Fatal|Warning|Notice|Deprecated)` / `Uncaught` lines |

## Integration state — verified unchanged (read-only)

- **Telegram dispatch setting:** `universal_support_chat_settings.telegram_dispatch_enabled`
  = `true` — identical before and after; the stored option is unchanged (still the six
  operational keys; the three SC-M05 presentation keys are served from
  `Settings::defaults()` until an operator saves the Settings page).
- **Channel peer** (`wp_universal_support_chat_channel_peers`, one row): `peer_id =
  universal-telegram`, `status = active`, `outbound_route_base =
  universal-telegram/v1/support-chat`, `created_at = 2026-08-28 20:05:46`, `last_used_at =
  2026-08-28 20:39:09`, `last_rotated_at = NULL`, `revoked_at = NULL`, `expires_at = NULL` —
  byte-for-byte unchanged.
- **No Telegram traffic, webhook call, pairing action, credential action, or Universal
  Telegram change** occurred during or as a result of this deployment. No test that sends
  Telegram traffic was run. `legacy-chat purge` was not run.

## Product Owner acceptance

The Product Owner **manually verified the Professional Widget Experience on DEV** — the
circular launcher and icon morph, the panel title and greeting, the Settings-page widget
presentation controls, and the non-modal dialog behaviour — and **approved the DEV result**.

Acceptance is of the DEV deployment outcome. It does not authorize a production deployment, a
release, or a tag; those remain separate, explicitly-authorized steps.

## Outstanding follow-up (unchanged from the closure)

The plan §9 **VoiceOver / NVDA screen-reader smoke** remains a **recommended follow-up**. No
screen-reader evidence has been supplied, so it is **not** claimed as passed, and the axe
(0 widget violations) / Lighthouse (accessibility 100) automated results do **not** substitute
for it. Checklist:
<https://github.com/magpern/universal-support-chat/pull/48#issuecomment-5469273912>. When a
person runs it, record the screen-reader + browser + OS versions and attach the result as the
completing WP7 evidence.

## Non-authorization

This record authorizes nothing further. It documents a DEV deployment that has already
happened and a Product Owner acceptance that has already been given. **Production is
untouched.** No GitHub Release or version tag was created. No runtime plugin code, test,
plugin version, schema, database data, settings, CI configuration, Docker configuration, or
service state changed, and no Universal Telegram artifact was touched. (The DEV checkout was
briefly used to stage this documentation branch and was returned to `main` @ `b3bc9d9`; that
is a Git-branch operation only.) A production deployment is a separate step requiring its own
explicit authorization.

## References

- [SC-M05 closure](sc-m05-professional-widget-experience-closure.md)
- [SC-M05 charter](../milestones/sc-m05-professional-widget-experience.md)
- [ADR-0016 — Support Chat widget presentation settings](../adr/0016-support-chat-widget-presentation-settings.md) — Accepted
- [Plan v2 — `sc-m05-professional-widget-experience-plan-v2.md`](../plans/sc-m05-professional-widget-experience-plan-v2.md)
- [`docs/decisions/sc-adr-0016-widget-presentation-po-acceptance.md`](../decisions/sc-adr-0016-widget-presentation-po-acceptance.md) — implementation acceptance
- Implementation [PR #48](https://github.com/magpern/universal-support-chat/pull/48) (`ceb5284`) · closure [PR #49](https://github.com/magpern/universal-support-chat/pull/49) (`b3bc9d9`)
