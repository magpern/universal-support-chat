# ADR-0011: Contract v1 `channel_case_ref` is the Support Chat conversation UUID; provenance-map and fail-closed semantics (F1 correction to ADR-0010 §4)

## Status

**Accepted** — 2026-08-27, by the Product Owner. The verbatim authorization is recorded in
`docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md` decision item 7 ("F1
implementation acceptance — recorded"), with a companion record in Universal Telegram
(`docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-approval.md`).
Acceptance PRs: universal-support-chat #25, universal-telegram #52. Acceptance authorizes only
implementation of the frozen F1 remediation work packages; it authorizes no schema,
`universal_support_chat_db_version`, or plugin-version change, no new Contract operation, and no
DEV, production, or operational cutover / rehearsal action. Documentation-only; no code or schema
change is made by this ADR itself. Proposed 2026-08-27.

**Amends ADR-0010 §4** (handoff contract). The Status-field amendment note on ADR-0010
("§4 `channel_case_ref` identity and closed-vocabulary semantics amended by ADR-0011" — a
Status-field-only change per the immutability rule; ADR-0010's Context, Decision, Alternatives,
Consequences, and other sections are not edited) is applied as work package WP-F1-S-3 of the
F1 implementation, per the companion remediation plan.

Universal Telegram companion: **UT ADR-0043** (pins this ADR; owns the adapter-side wire change
and the `CutoverReplayDispatcher` fail-closed classification).

## Context

The SC-M03 final-cutover disposable DEV rehearsal Tier 1 prerequisite validation was executed
against the accepted baselines (Universal Telegram `31519ee3ae297369118bf2deda6eae05d13a3d8b`,
Universal Support Chat `ce4691241eb843485117b323516899df916fdaf7`) and **halted at the UT→SC
deferred-update handoff phase by finding F1**. Records of the halt:

- Universal Telegram Tier 1 closure + characterization test — merge
  `98c602543bd67bc471e2a88468d175fb6e659b46` (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`,
  `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php`).
- This repository's Tier 1 closure — merge `fcbfaa773ef63661b6d8ce42962f10bb174588f8`
  (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`).

### F1, from source

**Two distinct identifiers exist, and they are not equal for any real binding.**

| Identity | Owner | Field (verified at the pinned SHAs) |
|---|---|---|
| **Binding identity** — one UT-owned row binding a Telegram forum topic to a Support Chat case | Universal Telegram | `wp_universal_telegram_support_chat_bindings.binding_uuid` `CHAR(36) NOT NULL`, `UNIQUE KEY binding_uuid` — `ChannelBinding::binding_uuid()`. Minted `wp_generate_uuid4()` at creation. |
| **Case identity** — the Support Chat conversation / case | Universal Support Chat | `universal_support_chat_conversations.uuid` — `Conversation::uuid()`, resolved via `ConversationRepository::find_by_uuid()`. |
| The same value, stored on the UT binding row for its own reference | Universal Telegram | `…_support_chat_bindings.support_conversation_uuid` `CHAR(36) NOT NULL`, `UNIQUE KEY support_conversation_uuid` — `ChannelBinding::support_conversation_uuid()`. |

Every real binding-creation path mints an **independent** `binding_uuid`:

- Universal Telegram `LegacyBindingImportServiceV1::import_one()` —
  `$this->bindings->create( wp_generate_uuid4(), (string) $candidate['support_conversation_uuid'], 'legacy-bind:' . …, … STATUS_PREPARED )`.
  The candidate's `support_conversation_uuid` originates from **this repository's** WP5
  `legacy-bind run` output — a real Support Chat conversation UUID.
- Universal Telegram `EnsureChannelCaseService::ensure()` —
  `$binding_uuid = wp_generate_uuid4();` then `create( $binding_uuid, $conversation_uuid, … )`,
  where `$conversation_uuid` is the Support Chat `ensure` request's argument.

**The wire disagreed with the resolver.** Universal Telegram sent `$binding->binding_uuid()` as
the Contract v1 `channel_case_ref` at every adapter→Support Chat call site
(`CutoverReplayDispatcher.php:135,189-192,214`; `InboundAdapterBridge.php:118-165`;
`SupportChatContractClient` param docs "Opaque binding UUID"). This repository's
`ContractOperationDispatcher::resolve_conversation()`
(`src/ChannelContract/Rest/ContractOperationDispatcher.php:545-552`) validates the ref as a v4
UUID then returns `$this->conversations->find_by_uuid( $ref )` — i.e. it treats `channel_case_ref`
as **this repository's `conversation_uuid`**. Its class docblock (`:25-27`) states this as a
"deliberate, documented interim convention … no adapter binding/`ensure_channel_case` exists
yet". That premise was silently outgrown: Universal Telegram's UT Adapter M1 built
`EnsureChannelCaseService`, the `ChannelBinding` value object, and the two-column split — but
this repository was never updated to match, and no code enforced the "interim" equality.

**Consequence for a real cohort.** `replay-deferred-updates` sends the opaque `binding_uuid`;
`resolve_conversation()` returns `null`; the handler returns `$this->error( 404, 'not_found' )`;
Universal Telegram's `CutoverReplayDispatcher::finish()` classifies any non-
`handoff_provenance_conflict` failure as `OUTCOME_RETRY_TRANSIENT` (not an incident); the
deferred row is left unresolved; the widened backlog predicate never empties; `replaying → idle`
and `cutover confirm-complete` are blocked indefinitely **with no classified outcome**. This is
both an identity defect and a fail-closed defect.

**Contradiction inside ADR-0010 itself.** ADR-0010 §4 (line 76), mirrored in
`src/Persistence/Migrator.php` (`step_11_create_legacy_handoff_map_table()` trailing comment) and
`src/ChannelContract/HandoffMapRepository.php:81`, calls `channel_case_ref` *"the binding UUID
this call resolved to"* — which is what the wire sent, but not what the resolver resolves it as.

**What is already correct in this repository.** `dispatch_with_provenance()` is invoked with
`$conversation->uuid()` — the **resolved** conversation UUID — as its `$channel_case_ref`
argument (`ingest_operator_reply` `:163`, and the sibling handlers), so the `legacy_handoff_map`
row's `channel_case_ref` column, and the `409 handoff_provenance_conflict` comparison
(`:481`), already operate on the Support Chat conversation UUID, never on the raw wire value.
No behaviour change to `resolve_conversation()`, `dispatch_with_provenance()`, or the map is
required — only the *input* to resolution (the wire value) must become the conversation UUID,
which is Universal Telegram's responsibility (UT ADR-0043).

## Decision

**`channel_case_ref` denotes the Support Chat conversation / case UUID — the SC-owned case
identity — in every Contract v1 operation, in both directions, for both live and
cutover-replay traffic.** The Universal Telegram binding UUID is a UT-owned binding identity and
**never** appears in a Contract v1 request or response body. Equality of the two UUIDs is
**never required and never used as a workaround**; no code path may assume it.

This ADR ratifies as the permanent contract the behaviour `resolve_conversation()` already has,
and fixes the surrounding statements and the fail-closed gap:

1. **Identity.** `channel_case_ref` identifies the Support Chat conversation/case. This
   repository resolves it through its existing authoritative `ConversationRepository`
   (`find_by_uuid()`), unchanged. **No Support Chat binding→conversation lookup table, no direct
   Universal Telegram SQL, no shared map, and no fallback that interprets a UT binding UUID as a
   Support Chat identifier is added** — such an SC-side resolution mechanism is explicitly
   rejected here and may only be introduced by a separate, separately-reviewed future ADR.

2. **Provenance map.** The `legacy_handoff_map.channel_case_ref` value is the Support Chat
   conversation UUID the Contract operation resolved and acted on — never the Universal Telegram
   binding UUID. This is already the code's behaviour (`dispatch_with_provenance($body, $kind,
   $conversation->uuid(), …)`); this ADR fixes only the stale comments
   (`Migrator.php`, `HandoffMapRepository.php:81`, `ContractOperationDispatcher` docblocks
   `:25-27`, `:467`, `:540-541`) and ADR-0010 §4's wording.

3. **Fail-closed, classified.** A missing, malformed, or non-existent `channel_case_ref` fails
   closed with a **classified terminal outcome**, never an unbounded transient retry:
   - This repository already fails closed — `resolve_conversation()` returns `null` for a
     malformed or unknown ref and the handler returns `404 not_found`; no domain write, no map
     write. Unchanged.
   - The **closed incident vocabulary** shared with Universal Telegram ADR-0042 §4/§5 (and named
     in ADR-0010 §4) is extended by two codes — `unresolved_case_reference` (Support Chat
     `404 not_found` after an active binding is selected: the `channel_case_ref`, now a
     conversation UUID, resolves to nothing — a data-integrity problem, not a transient one) and
     `handoff_rejected` (every other deterministic Support Chat refusal after active-binding
     selection: `400 invalid_body` / `400 invalid_operator` / `400 unsupported_operation` /
     `409 already_claimed` / `409 claimed_by_other` / `409 invalid_transition`). Universal
     Telegram ADR-0043 owns the dispatcher change that records these as durable UT-only incidents
     (blocking `replaying → idle` and `confirm-complete`, resolvable only by a real retry that
     succeeds or by the existing `cutover incident-acknowledge` terminal path). Only genuinely
     transient conditions stay retryable and are **not** incidents: Support Chat `503
     request_failed`, `401 contract_auth_failed`, and the client-side transport / unavailable /
     unpaired / discovery-incompatible / signing-unavailable gates. `409
     handoff_provenance_conflict` keeps its existing incident code. The classification is
     **exhaustive** — Universal Telegram ADR-0043 §3 tabulates every Contract outcome; no
     generic fallback remains that could create an unbounded silent retry.
   - There is no fallback to legacy processing once an active binding has been selected (the
     cutover-replay dispatcher already has no such fallthrough); no implicit UUID-equality
     assumption anywhere; no silent retry loop that can block replay forever without an outcome.

4. **This repository's obligations are documentation only** — the comment corrections in point 2,
   the `ContractOperationDispatcher` class docblock (drop "interim"/"no `ensure_channel_case`
   exists yet"; cite ADR-0011), and the closed-vocabulary note. **No schema change, no
   `db_version` bump, no runtime behaviour change.** `channel_case_ref` is `CHAR(36)`; a v4
   conversation UUID is 36 characters, so even the DDL is untouched.

## Alternatives

1. **Support Chat resolves `channel_case_ref` through a binding→conversation map** (a new
   SC-side table populated by a new `ensure_channel_case` Contract operation, or by direct
   Universal Telegram SQL, or a shared map). *Rejected.* It adds a new public Contract operation,
   a new table, a new `db_version` step, and a new lifecycle surface to preserve an indirection
   that yields nothing — Universal Telegram already holds `support_conversation_uuid` on every
   binding row. It also makes Support Chat depend on a Universal-Telegram-owned identifier,
   inverting the ADR-0002 / Universal Telegram ADR-0037 ownership boundary. This alternative is
   not merely deferred; introducing it later requires its own ADR with its own justification.

2. **Require `binding_uuid == support_conversation_uuid` at binding creation ("option (c)").**
   *Rejected* (Product Owner direction, 2026-08-27). The deployed creation paths intentionally
   mint an independent `binding_uuid`; formalizing equality contradicts shipped behaviour,
   forces an amendment to Support Chat ADR-0009 / Universal Telegram ADR-0041, and removes
   Universal Telegram's ability to re-key a binding (compensation, re-preparation) without
   disturbing the Support Chat-facing reference.

3. **Keep sending `binding_uuid`; add a Support Chat resolver for it.** Same rejection as (1),
   plus it enshrines the wrong identity on the wire permanently.

4. **Do nothing; require fixtures to seed equality forever.** *Rejected.* No real cohort can be
   handed off; the accepted DEV rehearsal (Tier 2) and any production cutover are permanently
   blocked; `CutoverHandoffIntegrationTest` would assert a condition that cannot hold in
   production.

## Consequences

- The Contract v1 meaning of `channel_case_ref` is unambiguous and identical on both sides:
  the Support Chat conversation/case UUID. `GET /universal-support-chat/v1/channel-contract`
  discovery is unaffected (field name, type, and the six provenance-capable operations
  unchanged).
- **No Support Chat schema change.** `universal_support_chat_db_version` stays at `11`;
  `verify_step_11`'s column-name and forbidden-column guards are unaffected (they check names,
  not comments).
- **No production data migration.** The final cutover has never run; `legacy_handoff_map` is
  empty in every environment (verify by `wp eval` count on DEV before the correction implements).
- `CutoverHandoffIntegrationTest` (7 cases) and `CutoverTier1HandoffResolutionTest` are rewritten
  by Universal Telegram to use bindings with a **distinct** `binding_uuid` produced by the real
  `LegacyBindingImportServiceV1` / `EnsureChannelCaseService`; this repository's interop-side
  fixtures (`InteropTestCase` conversation minting) are aligned so no test requires the two UUIDs
  to be equal.
- Two new closed incident codes, `unresolved_case_reference` and `handoff_rejected`, are added
  to the vocabulary ADR-0010 §4 references and Universal Telegram ADR-0042 §4 owns.
- The Tier 1 rehearsal is re-attempted (runbook v2) only after this ADR and Universal Telegram
  ADR-0043 are accepted and their remediation plans implemented and merged, and its
  real-binding handoff path passes. Tier 2 stays blocked on B1, B2, and F1.

## Security and privacy impact

None adverse. `channel_case_ref` remains an opaque UUID carrying no content; only *which* opaque
UUID it is changes. The value on the wire becomes the same conversation UUID this repository
already writes to its own audit events (`'conversation_uuid' => $conversation->uuid()`), so one
fewer distinct cross-system correlatable identifier crosses the boundary. `legacy_handoff_map`
still stores only ids/uuids/fixed-vocabulary/timestamps; the forbidden-column guard
(`verify_step_11`) is unchanged and still proves no `body|body_ciphertext|plaintext|content_hash|
digest` column exists. The new `unresolved_case_reference` code is a fixed non-content string.
No key material, plaintext, classification boundary, or authentication profile (ADR-0007) is
touched.

## Affected Documents/Milestones

- `docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md` — §4 amended by this
  ADR (Status-field note added on acceptance; body unchanged).
- `docs/adr/README.md` — ADR-0011 row; next available number becomes 0012.
- `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` — new; the
  Support Chat comment-correction and fixture-alignment work packages.
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` — a dated non-design "Amendment A"
  status footer records the F1 halt and the Tier 1 acceptance gate; the design sections are
  unchanged and the design revision is runbook v2 (an implementation-phase deliverable).
- `docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md` — decision item 7 (adopt
  this ADR; reject alternatives (1)/(2); Tier 1 acceptance gate).
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` — §0d planning note.
- `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` — the finding this ADR
  resolves (referenced, not edited).

## Compatibility/Migration Impact

- **Schema**: none. `db_version` unchanged at 11. No `ALTER`, no new step, no data backfill.
- **Wire contract**: the *name*, *type* (`CHAR(36)` / v4 UUID string), and *operation set* of
  `channel_case_ref` are unchanged. Its *referent* changes from "adapter binding UUID" to
  "Support Chat conversation/case UUID". Because this repository already resolves it as a
  conversation UUID, a corrected Universal Telegram talking to an unchanged Support Chat is
  *more* correct, not broken; an uncorrected Universal Telegram talking to a corrected Support
  Chat is exactly today's behaviour. There is no unsafe intermediate state and no required
  deploy order between the two repositories' corrections.
- **Existing bindings**: every `channel_bindings` row already stores `support_conversation_uuid`
  (`NOT NULL`); `prepared` rows resolve immediately once Universal Telegram sends the right
  value; `active` rows created pre-correction by `EnsureChannelCaseService` already carry it.
  Nothing to backfill.
- **Provenance rows**: `legacy_handoff_map` is empty everywhere; no rows carry a stale
  binding-UUID `channel_case_ref` to reconcile.
- **Rollback**: revert the doc-correction commit; no runtime effect.
