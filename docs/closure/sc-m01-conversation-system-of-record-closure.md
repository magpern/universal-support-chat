# Closure Record — SC-M01 Conversation System of Record

## Final status

**PASS** (technical). Product Owner acceptance via merge of the SC-M01 implementation PR.

## What this closes

SC-M01 Conversation System of Record: Support Chat–owned conversation and message persistence, authenticated visitor ownership, visitor REST (start/mine/post/poll), encrypted message bodies via Support Chat vault, lifecycle/status domain services, retention/purge foundation (WP-Cron), inert Contract v1 discovery stub, migrations (`db_version` 1→3), tests, and documentation.

## Baseline

- Starting `origin/main` SHA: `bb147a2d7087174692c38d667c496ee7823ae0a4`
- Branch: `feature/sc-m01-conversation-system-of-record`
- Frozen plan: `docs/plans/sc-m01-conversation-system-of-record-plan-v1.md` (unchanged)
- Depends on: SC-M00 Closed (PASS)

## Runtime identity

| Field | Value |
|---|---|
| Namespace | `UniversalSupportChat\` |
| Composer | `magpern/universal-support-chat` |
| Plugin version | `0.1.0` (from `0.0.1`) |
| DB target | `3` (`universal_support_chat_db_version`; steps 2 conversations, 3 messages) |
| Manage capability | `universal_support_chat_manage` |
| REST namespace | `universal-support-chat/v1` |

## Schema

| Table | Purpose |
|---|---|
| `wp_*_universal_support_chat_conversations` | Ownership, status/lifecycle, assignment foundation columns |
| `wp_*_universal_support_chat_conversation_messages` | Encrypted visitor-visible messages (`usc1:` vault envelope) |

No Telegram topic/chat/message IDs, bot/destination IDs, or Universal Telegram table references.

## Visitor REST

| Method | Route | Purpose |
|---|---|---|
| POST | `/universal-support-chat/v1/conversations` | Start or resume |
| GET | `/universal-support-chat/v1/conversations/mine` | Active conversation for current visitor |
| POST | `/universal-support-chat/v1/conversations/{uuid}/messages` | Post visitor message |
| GET | `/universal-support-chat/v1/conversations/{uuid}` | Poll status + messages |
| GET | `/universal-support-chat/v1/channel-contract` | Inert Contract v1 discovery |

Auth: logged-in WordPress user + `X-WP-Nonce` (`wp_rest`). Uniform `404`/`not_found` for unknown/unauthorised conversation access.

## Retention

WP-Cron hook `universal_support_chat_conversation_retention_cleanup` (daily). Defaults: inactive 30d → resolve; archived body null 30d; purge 90d. Audited as `conversation.retention_cleanup`. Dry-run supported for diagnostics/tests.

## Explicit non-implementation

- No SC-M02 widget or Hub UI/replies
- No UT Adapter M1 / Telegram adapter code / channel delivery
- No SC-M03 migration/cutover
- No SC-M04–M06, SC-AI1, SC-AI2
- No WooCommerce or Universal Telegram runtime dependency
- No release, tag, or deployment

## Evidence summary

- Ownership/IDOR uniform-404 coverage
- Nonce/authentication rejection coverage
- Encryption-at-rest + vault-unavailable fail-closed
- Message ordering + idempotency
- Retention dry-run + purge + audit
- Structural no-Telegram/UT coupling tests
- SC-M00 regression (activation, audit, vault, migrator idempotency)

## Next milestone

**SC-M02 — Widget and WordPress Hub Replies** (implementation freeze → implementation → closure).
