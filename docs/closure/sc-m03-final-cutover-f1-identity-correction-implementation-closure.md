# SC-M03 Final-Cutover — F1 `channel_case_ref` Identity-Correction Implementation — Closure (Support Chat)

## Status

**F1 runtime correction implemented and merged — 2026-08-27.** Primary closure record in the
Universal Telegram repository:
<https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md>.
**No DEV, production, or operational cutover / rehearsal action occurred or is authorized.**

| Repo | PR | Base (pre-merge `main`) | Merge commit |
|---|---|---|---|
| Universal Support Chat | [#26](https://github.com/magpern/universal-support-chat/pull/26) | `4c0650db65f0e911ba6422eaf6fc85fc91d26c6b` | `9144cb1e2362c2be8d4c74f1461bba7ffe236575` |
| Universal Telegram | [#53](https://github.com/magpern/universal-telegram/pull/53) | `3ae0407916c5d3a0f6acd0ee802a3e45ec0c18ae` | `7d4cc4fecb97f862721cea0fec427ade26b46ea7` |

## Support Chat scope — comment corrections only

**Documentation / comments only. No runtime, schema, `universal_support_chat_db_version`,
Contract, or plugin-version change.** `ContractOperationDispatcher::resolve_conversation()` and
`dispatch_with_provenance()` already resolve and persist the Support Chat conversation UUID
correctly; only the surrounding comments still called `channel_case_ref` "the binding UUID".

- `src/Persistence/Migrator.php` — `step_11_create_legacy_handoff_map_table()` docblock.
- `src/ChannelContract/HandoffMapRepository.php` — `insert()` / `find()` docblocks.
- `src/ChannelContract/Rest/ContractOperationDispatcher.php` — class docblock (dropped the
  "interim convention" wording), `resolve_conversation()` docblock (cites ADR-0011, notes the
  fail-closed `404`).

No SC-side binding→conversation resolver, shared map, lookup, or fallback was added — rejected
by ADR-0011. No UUID-equality rule was introduced.

## Corrected identity (ADR-0011 / ADR-0043)

Contract v1 `channel_case_ref` is the Support Chat conversation/case UUID on every approved
path. Universal Telegram now sends `ChannelBinding::support_conversation_uuid()`; its UT-local
`binding_uuid` is **absent from every Contract v1 wire body** and stays a private
binding-row identity. No new Contract operation, route, field, schema change, or `db_version`
bump on either side.

## Fail-closed replay classification (Universal Telegram side, recorded here for cross-reference)

`CutoverReplayDispatcher::finish()` is now exhaustive and fail-closed (new
`CutoverReplayFailureClassifier`, ADR-0043 §3):

- `404 not_found` → durable UT-only incident `unresolved_case_reference`.
- Specified deterministic rejections (`400 invalid_body` / `invalid_operator` /
  `unsupported_operation`; `409 already_claimed` / `claimed_by_other` / `invalid_transition`;
  `sc_contract_unsupported_operation`) **and any unrecognised `ok:false` reason** → durable
  UT-only incident `handoff_rejected`.
- `409 handoff_provenance_conflict` → unchanged.
- Only the frozen explicit transient set (`503 request_failed`, `401 contract_auth_failed`,
  client not-paired / unavailable / discovery-incompatible / signing-unavailable /
  transport-failed, and caught `\Throwable`) remains retryable — no generic fallback.

On the Support Chat side this is unchanged and already correct: a malformed or unknown
`channel_case_ref` → `resolve_conversation()` returns `null` → `404 not_found`, no domain write,
no `legacy_handoff_map` row. Support Chat emits no incident of its own.

## Verification

| Item | Result |
|---|---|
| Per-repo CI on PR #26 (`4c0650d` → `9144cb1`), incl. `docs` | all jobs green |
| Per-repo CI on PR #53 (`3ae0407` → `7d4cc4f`) | all jobs green |
| Post-merge dual-plugin interop — merged UT `main` (`7d4cc4f`) + SC PR #26 branch (`7222d01`), WP 7.1 / PHP 8.3, fresh DB | OK (47 tests, 722 assertions) |
| Post-merge dual-plugin interop — same, WP 6.9 / PHP 8.1, fresh DB | OK (47 tests, 722 assertions) |
| Support Chat — unit / wp-only integration / interop (local, pre-merge) | OK (88 / 122 / 18) |
| Support Chat — phpcs / phpstan | clean / `[OK] No errors` |
| `universal_support_chat_db_version` | unchanged (**11**); `verify_step_11` green |

## Ordered merge

Universal Telegram PR #53 CI green → merged first (`3ae0407` → `7d4cc4f`). UT `origin/main`
fetched fresh; the dual-plugin interop suite re-run against merged UT `main` plus the Support
Chat PR #26 branch, both supported WP/PHP variants, fresh disposable database each — OK (47
tests, 722 assertions) each. Only then was Support Chat PR #26 merged (`4c0650d` → `9144cb1`);
SC `origin/main` then fetched fresh at `9144cb1`.

## Rehearsal status — unchanged

Tier 1 remains **unexecuted** and cannot be accepted until a Tier 1 re-attempt passes its
real-binding handoff path; a re-attempt needs a **separate Approval A addendum** under DEV
rehearsal runbook **v2**. Tier 2 remains **unexecuted and blocked on B1 and B2**. No Tier 1 /
Tier 2 acceptance record is created by this work.

## Next authorized step

Draft and freeze DEV rehearsal runbook **v2** plus a separate Tier 1 Approval A addendum. Do
**not** execute Tier 1. Nothing further is authorized.
