# SC-M03 Final-Cutover — F1 `channel_case_ref` identity-correction plan v1 (Support Chat companion)

**Status: Proposed — awaiting Product Owner review. Documentation-only.** Primary plan (owns the
adapter wire change and the fail-closed classification): Universal Telegram
`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`. Implementation
begins only after Product Owner acceptance of **ADR-0011** and Universal Telegram **ADR-0043**.
This freeze adds **no** Product Owner implementation acceptance.

## 1. Milestone charter and ADRs

- Milestone: SC-M03 §0d (final cutover, ADR-0010) — remediation of finding F1 from the
  disposable DEV rehearsal Tier 1 prerequisite validation (this repository's Tier 1 closure,
  merge `fcbfaa773ef63661b6d8ce42962f10bb174588f8`; Universal Telegram closure +
  characterization test, merge `98c602543bd67bc471e2a88468d175fb6e659b46`).
- Introduces: **ADR-0011** (`channel_case_ref` = Support Chat conversation UUID; provenance-map
  and fail-closed semantics; amends ADR-0010 §4). Relies unchanged on ADR-0005/0007 (Contract
  v1), ADR-0010 (handoff contract, cohort activation).

## 2. Repository findings at plan-drafting time (SC `ce4691241eb843485117b323516899df916fdaf7`)

`ContractOperationDispatcher::resolve_conversation()` (`src/ChannelContract/Rest/ContractOperationDispatcher.php:545-552`)
already resolves `channel_case_ref` as this repository's `conversation_uuid` via
`ConversationRepository::find_by_uuid()`, and already returns `null` → handler `404 not_found`
for a malformed or unknown ref. `dispatch_with_provenance()` (`:472`, insert `:509`) is already
called with `$conversation->uuid()` as `$channel_case_ref`, so the
`legacy_handoff_map.channel_case_ref` column and the `409 handoff_provenance_conflict` compare
(`:481`) already operate on the resolved Support Chat conversation UUID, never the raw wire
value. **This behaviour is correct under ADR-0011 and does not change.**

What is wrong is the *documentation* that calls the field "the binding UUID":

| # | Location | Current text | Corrected text |
|---|---|---|---|
| C1 | `src/Persistence/Migrator.php` `step_11_create_legacy_handoff_map_table()` trailing comment (~`:815`, `:833`) | "`channel_case_ref` is always populated (the binding UUID this call resolved to)" | "…(the Support Chat conversation UUID this call resolved to; see ADR-0011)" |
| C2 | `src/ChannelContract/HandoffMapRepository.php:81` (`@param $channel_case_ref`) | "The binding UUID this call resolved to." | "The Support Chat conversation UUID this call resolved to (ADR-0011)." |
| C3 | `src/ChannelContract/Rest/ContractOperationDispatcher.php:25-27` (class docblock) | "`channel_case_ref` is Support Chat's own `conversation_uuid` for this work package — no adapter binding/`ensure_channel_case` exists yet … a deliberate, documented interim convention" | "`channel_case_ref` is the Support Chat `conversation_uuid`, ratified by ADR-0011. The adapter sends `ChannelBinding::support_conversation_uuid()`; its private `binding_uuid` never crosses this contract." |
| C4 | `src/ChannelContract/Rest/ContractOperationDispatcher.php:467`, `:540-541` (method docblocks) | "The binding UUID this call resolved to." / "Interim convention: …" | "The Support Chat conversation UUID this call resolved to (ADR-0011)." |

`docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md` line 76 is **not edited**
(ADR immutability); ADR-0011 records the amendment and, on acceptance, ADR-0010's Status field
gains the note.

**Closed incident vocabulary.** ADR-0010 §4 references the closed incident vocabulary that
Universal Telegram ADR-0042 §4 owns. ADR-0011 and Universal Telegram ADR-0043 extend it by two
codes — `unresolved_case_reference` (SC `404 not_found` after active-binding selection) and
`handoff_rejected` (every other deterministic SC refusal: `400 invalid_body` /
`400 invalid_operator` / `400 unsupported_operation` / `409 already_claimed` /
`409 claimed_by_other` / `409 invalid_transition`). The dispatcher classification change is
Universal Telegram's (ADR-0043 §3, an exhaustive table with no generic retry fallback); this
repository already produces the `404`/`400`/`409` responses that trigger them and needs **no
runtime change** for them.

## 3. Assumptions and open questions

- **A-F1-S1**: `CHAR(36)` stores a v4 conversation UUID without truncation (36 chars). Confirmed
  (`Migrator.php` step 11). No DDL change, no `db_version` bump.
- **A-F1-S2**: `verify_step_11` asserts column *names* and the forbidden-column guard, not
  comments — unaffected. Confirmed.
- **A-F1-S3**: `legacy_handoff_map` is empty in every environment (cutover never run). Verify by
  `wp eval` count on DEV before merge; no data migration expected.

## 4. Architectural decisions

Per ADR-0011: `resolve_conversation()` and `dispatch_with_provenance()` behaviour, and the
`legacy_handoff_map` shape, are **unchanged**. Only comments C1–C4 are corrected, and this
repository's interop-side fixtures that mint Support Chat conversations for the adapter/cutover
tests are aligned so no test requires the peer to send a binding UUID equal to the conversation
UUID. **No SC-side binding→conversation lookup table, direct Universal Telegram SQL, shared map,
or fallback interpreting a UT binding UUID as an SC identifier is added** (rejected by ADR-0011).

## 5. Directory, namespace, schema, API impact

- **Changed**: 3 source files (`Migrator.php`, `HandoffMapRepository.php`,
  `ContractOperationDispatcher.php`) — comments only — plus interop fixture helpers under
  `tests/`.
- **Schema / migration**: none. `universal_support_chat_db_version` stays `11`. **Proof no data
  migration is needed**: no column added or changed (`channel_case_ref CHAR(36)` fits a
  conversation UUID); `legacy_handoff_map` empty everywhere; the new incident code is a Universal
  Telegram row value, not an SC column.
- **Wire / discovery**: unchanged.

## 6. Compatibility treatment for existing `prepared` and future `active` bindings

Support Chat holds no binding rows. For every Contract call, `resolve_conversation()` maps the
incoming `channel_case_ref` to a conversation via `find_by_uuid()`. Once Universal Telegram sends
`support_conversation_uuid()` (its `prepared` and `active` bindings all carry it), resolution
succeeds for every binding state. No SC-side compatibility shim, dual-read, or backfill.

## 7. Failure classification and fail-closed behaviour (SC side)

- A malformed `channel_case_ref` (fails the v4 UUID regex) or an unknown one →
  `resolve_conversation()` returns `null` → handler returns `error( 404, 'not_found' )`; **no
  domain write, no `legacy_handoff_map` write.** Unchanged, already fail-closed.
- A provenance mismatch on `(source_bot_id, source_update_id)` → `409
  handoff_provenance_conflict`, rollback, no writes. Unchanged.
- Universal Telegram's `CutoverReplayDispatcher` converts the `404` into a durable
  `unresolved_case_reference` incident, and the deterministic `400`/`409` refusals into a
  `handoff_rejected` incident (its concern, ADR-0043 §3); Support Chat emits no incident and
  writes no `legacy_handoff_map` row for any of them (`resolve_conversation()` `null` and every
  `4xx`/`409` path return before `$domain_work` / the map insert).

## 8. Test and CI impact

- Interop fixture helpers (`InteropTestCase` conversation minting; cutover/adapter interop
  cases) updated so the Universal Telegram peer's binding carries a **distinct** `binding_uuid`
  and the handoff is asserted to resolve via `support_conversation_uuid`. The primary rewrite of
  `CutoverHandoffIntegrationTest` and the new fail-closed test (T8) live in the Universal
  Telegram repo; this repo asserts the SC-side halves: message/assignment/channel-status effects
  on the resolved conversation, `legacy_handoff_map.channel_case_ref` == conversation UUID, `409`
  on mismatch, `404` on an absent conversation, no forbidden columns.
- **CI**: `ci.yml` jobs (`docs` = `bin/check-doc-links.php` via
  `composer run-script check-doc-links`, phpcs, static-analysis, unit 8.1/8.3/8.4,
  integration-wp-only-{floor,current}) stay green. The `docs` job must pass on the new ADR +
  plan + registry links.
- No new CI job.

## 9. Handling of old erroneous `channel_case_ref` values in evidence

`legacy_handoff_map` is empty in every environment (A-F1-S3) — no stored SC row carries a
binding-UUID `channel_case_ref`. The halted Tier 1 evidence bundle is disposable scratchpad,
retained unchanged as the F1 record; runbook-v2 evidence supersedes it. No SC data to rewrite.

## 10. Work packages in execution order

1. **WP-F1-S-1 (docs, this freeze)** — ADR-0011, this plan, `docs/adr/README.md`,
   `docs/plans/README.md`, `docs/decisions/README.md` + PO decision item 7,
   `docs/milestones/sc-m03-controlled-migration-and-cutover.md` §0d note. Merge after `docs` CI
   green. **No code. No PO implementation acceptance.**
2. **WP-F1-S-2** — comment corrections C1–C4; interop fixture alignment; SC-side test halves.
   No `db_version` bump. Merge only after Product Owner acceptance of ADR-0011 and Universal
   Telegram ADR-0043 and the implementation acceptance text.
3. **WP-F1-S-3** — on acceptance, add the Status-field amendment note to ADR-0010 (Status field
   only).
4. Implementation report cites this plan's freeze SHA.

## 10a. Tier 1 rerun gate

Tier 1 **cannot be accepted until** the correction is implemented in both repos and its
real-binding handoff path passes green in the interop harness. A Tier 1 re-attempt requires a
separate Approval A addendum and runs only under DEV rehearsal runbook v2. Tier 2's B1/B2
blockers and unexecuted status are preserved; Tier 2 is additionally blocked on F1.

## 11. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A reviewer reads ADR-0010 §4 line 76 and believes the field is "the binding UUID" | ADR-0011 linked from ADR-0010's Status field on acceptance; C1–C4 corrected; plan enumerates every stale location |
| Comment-only change dismissed as cosmetic, contradiction persists | WP-F1-S-2 is a DoD item; `docs` CI + review gate |
| Someone treats this plan as authorizing Tier 1 or F1 implementation | §10a + the acceptance text in the Universal Telegram primary plan §15; no acceptance record created here |
| Fixture alignment weakens the `409` / `404` assertions | SC-side test halves explicitly assert both |

## 12. Out of scope

- Any schema change, `db_version` bump, or `ALTER` to `legacy_handoff_map`.
- Any new Contract v1 operation, route, field, `ensure_channel_case` service, or SC-side
  binding→conversation resolver.
- Any change to `resolve_conversation()` or `dispatch_with_provenance()` behaviour.
- Editing ADR-0010's immutable sections (only its Status field changes, on acceptance) or the
  frozen rehearsal plan v1 or the Tier 1 closure.
- Executing or re-executing Tier 1 or Tier 2 of the DEV rehearsal.
- Any production or DEV cutover, migration, quiescence, activation, route switch, deployment,
  release, tag, or rollback.
- Any Product Owner implementation acceptance (separate later action).

## 13. Definition of done

- ADR-0011 and Universal Telegram ADR-0043 accepted.
- Comments C1–C4 corrected; no location still calls `channel_case_ref` "the binding UUID".
- `db_version` unchanged (11); `verify_step_11` green.
- Interop fixtures no longer require `binding_uuid == conversation_uuid`; SC-side handoff-map /
  `409` / `404` assertions explicit.
- `docs` and all `ci.yml` jobs green.
- ADR-0010 Status-field amendment note added.
- Implementation report cites this plan's freeze SHA.
- **No Tier 1 / Tier 2 acceptance record created.** Tier 2 stays blocked on B1, B2, and F1.
