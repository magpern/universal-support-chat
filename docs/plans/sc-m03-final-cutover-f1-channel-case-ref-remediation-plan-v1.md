# SC-M03 Final-Cutover — F1 `channel_case_ref` remediation plan v1 (Support Chat companion)

**Status: Proposed — awaiting Product Owner review. Documentation-only.** Primary plan (owns the
adapter wire change): Universal Telegram
`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`. Implementation
begins only after Product Owner acceptance of ADR-0011 and Universal Telegram ADR-0043.

## 1. Milestone charter and ADRs

- Milestone: SC-M03 §0d (final cutover, ADR-0010) — remediation of finding F1 from the
  disposable DEV rehearsal Tier 1 prerequisite validation.
- Introduces: **ADR-0011** (`channel_case_ref` is the Support Chat conversation UUID; corrects
  ADR-0010 §4). Relies unchanged on ADR-0005/0007 (Contract v1), ADR-0010 (handoff contract,
  cohort activation).
- Finding of record: `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`.

## 2. Repository findings at plan-drafting time (SC `ce46912`)

`ContractOperationDispatcher::resolve_conversation()` (`:545-552`) already resolves
`channel_case_ref` as this repo's `conversation_uuid` via `conversations->find_by_uuid()` — this
is the **correct, ratified** behaviour under ADR-0011. What is wrong is the *documentation* that
calls the field "the binding UUID":

| # | Location | Current text | Corrected text |
|---|---|---|---|
| C1 | `src/Persistence/Migrator.php` ~`:815`, `:833` (trailing SQL comment on `channel_case_ref CHAR(36)`) | "the binding UUID this call resolved to" | "the Support Chat conversation UUID this call resolved to" |
| C2 | `src/ChannelContract/HandoffMapRepository.php:81` (`@param $channel_case_ref`) | "The binding UUID this call resolved to." | "The Support Chat conversation UUID this call resolved to." |
| C3 | `src/ChannelContract/Rest/ContractOperationDispatcher.php:25-27` (class docblock) | "`channel_case_ref` is Support Chat's own `conversation_uuid` for this work package — no adapter binding/`ensure_channel_case` exists yet … a deliberate, documented interim convention" | "`channel_case_ref` is the Support Chat `conversation_uuid` (ratified by ADR-0011; the adapter sends `ChannelBinding::support_conversation_uuid()`, never its private `binding_uuid`)" |
| C4 | `src/ChannelContract/Rest/ContractOperationDispatcher.php:467`, `:540-541` (method docblocks) | "The binding UUID this call resolved to." / "Interim convention" | "The Support Chat conversation UUID this call resolved to." / cite ADR-0011 |

`docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md` line 76 is **not edited**
(ADR immutability) — ADR-0011 records the correction and, on acceptance, ADR-0010's Status field
gains the supersession note.

## 3. Assumptions and open questions

- **A-F1-S1**: `CHAR(36)` stores a v4 conversation UUID with no truncation. Confirmed by
  inspection (`Migrator.php:833`; v4 UUID = 36 chars). No DDL change, no `db_version` bump.
- **A-F1-S2**: `verify_step_11` asserts column *names* and the forbidden-column guard, not
  comments — unaffected. Confirmed (`Migrator.php:247`, `:854`).
- **A-F1-S3**: `legacy_handoff_map` is empty in every environment (cutover never run). Verify by
  `wp eval` count on DEV before merge; no data migration expected.

## 4. Architectural decisions

Per ADR-0011: `resolve_conversation()` behaviour is **unchanged**; `dispatch_with_provenance()`'s
`channel_case_ref` mismatch check (`:481`) is **unchanged**; only comments C1–C4 are corrected;
Support Chat interop-side fixtures that mint SC conversations for the adapter/cutover tests are
aligned so they no longer require the peer to send a binding UUID equal to the conversation UUID.

## 5. Directory, namespace, schema, API impact

- **Changed**: 3 source files (`Migrator.php`, `HandoffMapRepository.php`,
  `ContractOperationDispatcher.php`) — comments only — plus interop fixture helpers under
  `tests/`.
- **Schema**: none. `universal_support_chat_db_version` stays `11`.
- **Wire / discovery**: unchanged.

## 6. Security and privacy impact

None. See ADR-0011 §Security and privacy impact. The value on the wire is the same conversation
UUID Support Chat already writes to its own audit events; no new field is logged or stored.

## 7. Test and CI impact

- Interop fixture helpers (`InteropTestCase` and cutover/adapter interop cases) updated so the
  Universal Telegram peer's binding carries a **distinct** `binding_uuid` and the handoff is
  asserted to resolve by `support_conversation_uuid`. The primary rewrite of
  `CutoverHandoffIntegrationTest` lives in the Universal Telegram repo.
- `ContractOperationDispatcherTest` / handoff-map tests: assert the stored `channel_case_ref`
  equals the conversation UUID (already true; make it explicit).
- **CI**: `ci.yml` jobs (`docs`, phpcs, static-analysis, unit 8.1/8.3/8.4,
  integration-wp-only-{floor,current}) stay green. The `docs` job (`bin/check-doc-links.php`)
  must pass on the new ADR + plan + registry links.
- No new CI job.

## 8. Work packages in execution order

1. **WP-F1-S-1 (docs, this commit)** — ADR-0011, this plan, `docs/adr/README.md`,
   `docs/plans/README.md`, `docs/decisions/README.md` + PO decision item 7,
   `docs/milestones/sc-m03-controlled-migration-and-cutover.md` §0d note. Merge after `docs` CI
   green. **No code.**
2. **WP-F1-S-2** — comment corrections C1–C4; interop fixture alignment. No `db_version` bump.
   Merge only after Product Owner acceptance of ADR-0011 and Universal Telegram ADR-0043.
3. **WP-F1-S-3** — on acceptance, add the Status-field supersession note to ADR-0010 (Status
   field only, per the immutability rule).
4. Implementation report cites this plan's freeze SHA. Tier 1 re-attempt is a separate
   Product-Owner-authorized step.

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A reviewer reads the ADR-0010 §4 line 76 comment and believes the field is still "the binding UUID" | ADR-0011 is linked from ADR-0010's Status field on acceptance; C1–C4 corrected in code; the plan enumerates every stale location |
| Comment-only change is skipped as "cosmetic" and the contradiction persists | WP-F1-S-2 is a required DoD item; `docs` CI + review gate |
| Someone treats this plan as authorizing Tier 1 | Plan states Tier 1 re-attempt needs a separate Approval A addendum; no acceptance record created here |

## 10. Out of scope

- Any schema change, `db_version` bump, or `ALTER` to `legacy_handoff_map`.
- Any new Contract v1 operation, route, field, or `ensure_channel_case` service.
- Any change to `resolve_conversation()` or `dispatch_with_provenance()` behaviour.
- Editing ADR-0010's immutable sections (only its Status field changes, on acceptance).
- Executing or re-executing Tier 1 or Tier 2 of the DEV rehearsal.
- Any production or DEV cutover, migration, quiescence, activation, route switch, deployment,
  release, tag, or rollback.

## 11. Definition of done

- ADR-0011 and Universal Telegram ADR-0043 accepted.
- Comments C1–C4 corrected; no location still describes `channel_case_ref` as "the binding UUID".
- `db_version` unchanged (11); `verify_step_11` green.
- Interop fixtures no longer require `binding_uuid == conversation_uuid`; SC-side handoff-map
  assertions explicit.
- `docs` and all `ci.yml` jobs green.
- ADR-0010 Status-field supersession note added.
- Implementation report cites this plan's freeze SHA.
- **No Tier 1 / Tier 2 acceptance record is created by this work.** Tier 2 stays blocked on B1,
  B2, and F1.
