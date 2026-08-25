# Closure Record — SC-M02 Widget and WordPress Hub Replies

## Final status

**PASS** (technical). Product Owner acceptance via merge of the SC-M02 implementation PR.

## What this closes

SC-M02: minimal accessible visitor chat widget for authenticated users; WordPress Hub inbox/detail with capability-gated reply and internal notes; Hub replies appear to visitors as **Support team**; reuse of SC-M01 SoR/encryption/ownership; notes schema (`db_version` 4); tests and documentation.

## Baseline

- Starting `origin/main` SHA: `7421aea2dc0be926b5bbdbd12b004b603ea3d176`
- Branch: `feature/sc-m02-widget-and-hub-replies`
- Frozen plan: `docs/plans/sc-m02-widget-and-hub-replies-plan-v1.md` (unchanged)
- Depends on: SC-M01 Closed (PASS)

## Runtime identity

| Field | Value |
|---|---|
| Plugin version | `0.2.0` (from `0.1.0`) |
| DB target | `4` (step 4: conversation notes) |
| Hub capability | `universal_support_chat_manage` |
| Hub menu slug | `universal-support-chat-hub` |
| Widget assets | `assets/js/chat-widget.js`, `assets/css/chat-widget.css` |

## Surfaces

### Visitor widget
- Enqueued on front-end when `widget_enabled` (default true).
- Logged-in visitors: start/resume + post + poll via SC-M01 REST with `X-WP-Nonce`.
- Logged-out: truthful sign-in prompt only (no anonymous conversation).
- Transcript rendered via `textContent` only; polling cleaned up on close/`pagehide`.

### Hub
- Top-level **Support Chat** admin menu.
- Inbox list (metadata only) + detail transcript (server-side decrypt).
- Reply form (`admin-post` `usc_hub_reply`) writes `direction=operator`; visitor `author_label` = `Support team`.
- Internal notes (`usc_hub_add_note`) encrypted; never on visitor REST.
- CSRF via `check_admin_referer`; capability on every mutation; audits without plaintext bodies.

## Schema

- New table: `universal_support_chat_conversation_notes` (encrypted Hub-only notes).
- No Telegram/channel columns.

## Explicit non-implementation / deferred

- SC-M05 professional launcher/greeting/avatar polish
- SC-M06 availability / offline tickets
- Assignment/claiming UX (foundation columns remain; no claim/CAS UI in SC-M02)
- UT Adapter M1, SC-M03 migration/cutover, AI
- Release, tag, deployment

## Security model

Authenticated visitor ownership preserved; Hub capability + CSRF; encryption at rest for messages and notes; uniform visitor REST 404 behavior unchanged; no UT/Telegram coupling.

## Next milestone

**UT Adapter M1** (Universal Telegram repository), then **SC-M03** controlled migration/cutover. Do not start SC-M03 before UT Adapter M1 is available.
