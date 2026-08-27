# ADR-0011: `channel_case_ref` is the Support Chat conversation UUID (F1 correction to ADR-0010 §4)

## Status

**Proposed** — awaiting Product Owner review. Documentation-only; no code, schema, or
`universal_support_chat_db_version` change is made by this ADR.

On acceptance, ADR-0010's Status field gains "§4 `channel_case_ref` semantics superseded by
ADR-0011" (Status-field-only change, per the immutability rule); ADR-0010's Context, Decision,
and other sections are not edited.

Universal Telegram companion: UT ADR-0043 (pins this ADR and owns the adapter-side wire change).

## Context

The SC-M03 final-cutover disposable DEV rehearsal Tier 1 prerequisite validation was executed
against the accepted baselines (Universal Telegram `31519ee`, Universal Support Chat `ce46912`)
and **halted at the UT→SC deferred-update handoff phase by finding F1**, recorded in
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` and (primary)
`https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`.

**F1, precisely.** The Contract v1 wire field `channel_case_ref` is resolved against a *different*
local identifier on each side:

- **Support Chat** — `ContractOperationDispatcher::resolve_conversation()`
  (`src/ChannelContract/Rest/ContractOperationDispatcher.php:545-552`) validates the ref as a v4
  UUID then returns `$this->conversations->find_by_uuid( $ref )`. The class docblock
  (`:25-27`) states this explicitly: *"`channel_case_ref` is Support Chat's own
  `conversation_uuid` for this work package — no adapter binding/`ensure_channel_case` exists yet
  … a deliberate, documented interim convention."*
- **Universal Telegram** — every outbound adapter→SC call site sends
  `$binding->binding_uuid()` as `channel_case_ref`
  (`CutoverReplayDispatcher.php:135,189-192,214`; `InboundAdapterBridge.php:118-165` via
  `SupportChatContractClient`, whose parameter docs read "Opaque binding UUID"). And every
  real binding-creation path mints a **fresh** `binding_uuid` unrelated to the conversation UUID:
  - `LegacyBindingImportServiceV1::import_one()` (UT) —
    `$this->bindings->create( wp_generate_uuid4(), (string) $candidate['support_conversation_uuid'], … STATUS_PREPARED )`.
  - `EnsureChannelCaseService` (UT `:139`) — `$binding_uuid = wp_generate_uuid4();`.

The adapter side (`EnsureChannelCaseService`, the `ChannelBinding` value object, the
`binding_uuid` / `support_conversation_uuid` column split) was built out in UT Adapter M1 —
i.e. the "no `ensure_channel_case` exists yet" premise of Support Chat's interim convention
**was silently outgrown on the Universal Telegram side without Support Chat being updated to
match.** The two sides only interoperate today when a fixture seeds
`binding_uuid == conversation_uuid` — which `CutoverHandoffIntegrationTest` (7 cases) and the
adapter interop suites do.

**Consequence for a real cohort.** `replay-deferred-updates` sends the opaque `binding_uuid`;
Support Chat's `resolve_conversation()` returns `null`; the handler returns
`error( 404, 'not_found' )`; `CutoverReplayDispatcher::finish()` classifies a non-
`handoff_provenance_conflict` failure as `OUTCOME_RETRY_TRANSIENT` (not an incident); the row is
left unresolved forever; the widened backlog predicate never empties; `replaying → idle` and
`cutover confirm-complete` never succeed. The Tier 1 characterization test
`tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` (UT, merged) pins this with a
real `legacy-bind`-prepared, really-activated binding.

**Contradiction inside ADR-0010 itself.** ADR-0010 §4's schema comment
(`docs/adr/0010-…md` line 76; mirrored in `src/Persistence/Migrator.php:815-833` and
`src/ChannelContract/HandoffMapRepository.php:81`) says `channel_case_ref` is *"the binding UUID
this call resolved to"* — which is what the wire actually sends, but **not** what
`resolve_conversation()` resolves it as. One of the two must give.

## Decision

**`channel_case_ref` denotes the Support Chat `conversation_uuid`, in every direction and for
both live and cutover-replay traffic.** Support Chat's existing `resolve_conversation()`
behaviour and its "interim convention" become the **permanent, ratified** contract. The
Universal Telegram adapter is corrected to send `$binding->support_conversation_uuid()` (which
every binding already stores, populated at creation from the SC conversation UUID) as
`channel_case_ref`, and to retain `binding_uuid` **solely** as Universal Telegram's private
binding-row identity (idempotency key, `activate_prepared()` / `revert_activation()` CAS target,
`record_ingest_update_id()` / `record_delivered_key()`, audit rows). The adapter-side wire
change is owned by UT ADR-0043 and its remediation plan.

Support Chat's own obligations under this ADR (all documentation / comment corrections — **no
schema, no `db_version` bump, no behaviour change**):

1. **ADR-0010 §4 is corrected by this ADR**: `channel_case_ref` in the `legacy_handoff_map` row,
   on the wire, and in the six extended operations is *"the Support Chat conversation UUID this
   call resolved to"* — never the adapter binding UUID. The column is `CHAR(36)` and a v4
   conversation UUID is 36 characters, so **the DDL is unchanged**; only the trailing SQL comment
   in `step_11_create_legacy_handoff_map_table()` and the docblocks in `HandoffMapRepository`
   and `ContractOperationDispatcher` are corrected to say "conversation UUID".
2. `ContractOperationDispatcher`'s class docblock drops "interim"/"no `ensure_channel_case`
   exists yet" and states the ratified convention with a pointer to this ADR.
3. `resolve_conversation()` is **unchanged in behaviour**. Its docblock is updated to cite
   ADR-0011 rather than "interim convention".
4. `dispatch_with_provenance()`'s duplicate-detection compare
   (`$existing['channel_case_ref'] !== $channel_case_ref`, `:481`) is **unchanged** — it already
   compares whatever value the resolver derived; that value is now the conversation UUID on both
   the first call and the retry, so `409 handoff_provenance_conflict` semantics are preserved.

## Alternatives

1. **Support Chat resolves `channel_case_ref` through a binding→conversation map** (a new
   SC-side table populated by a new `ensure_channel_case` Contract operation). *Rejected*: adds a
   new public Contract v1 operation, a new table, a new `db_version` step, and a new
   registration/lifecycle surface — a large expansion of the contract to preserve an indirection
   that yields nothing here. The adapter already holds `support_conversation_uuid` on every
   binding row; no lookup service is needed.
2. **Collapse the two identifiers — make `binding_uuid == support_conversation_uuid` at
   creation** (option (c) in the Tier 1 closure). *Rejected*: the deployed binding-creation paths
   (`LegacyBindingImportServiceV1`, `EnsureChannelCaseService`) intentionally mint an independent
   `binding_uuid`; formalizing equality contradicts shipped behaviour, forces a WP5 / ADR-0009
   amendment, and removes Universal Telegram's ability to re-key a binding (compensation,
   re-preparation) without disturbing the SC-facing reference. The Product Owner directed this
   alternative be rejected.
3. **Do nothing; require fixtures to seed equality forever.** *Rejected*: no real cohort can be
   handed off; the accepted DEV rehearsal (Tier 2) and any production cutover are permanently
   blocked; `CutoverHandoffIntegrationTest` would be asserting a condition that cannot hold in
   production.

## Consequences

- The Contract v1 wire contract's meaning of `channel_case_ref` is now unambiguous and identical
  on both sides. `GET /universal-support-chat/v1/channel-contract` discovery is unaffected (the
  field name, type, and six operations are unchanged).
- **No Support Chat schema change.** `universal_support_chat_db_version` stays at `11`.
  `verify_step_11`'s forbidden-column guard and column-name assertion are unaffected (it checks
  names, not comments).
- **No production data migration.** The final cutover has never run; `legacy_handoff_map` is
  empty in every environment. Adapter live-inbound traffic that has populated bindings is
  addressed by UT ADR-0043's rollout section (bindings already carry `support_conversation_uuid`;
  the SC side already resolved by conversation UUID, so already-delivered live traffic that
  happened to work did so with `binding_uuid == conversation_uuid` seeded, or has not yet
  exercised the inbound path in production — UT ADR-0043 confirms against the deployed state).
- `CutoverHandoffIntegrationTest` (7 cases) and the adapter interop suites stop seeding
  `binding_uuid == conversation_uuid` and instead assert the corrected mapping with a distinct
  `binding_uuid`. This makes them real regression coverage for F1. Owned by UT ADR-0043's plan;
  Support Chat's interop-side fixtures (`InteropTestCase` helpers that mint SC conversations)
  are adjusted in lockstep.
- The Tier 1 rehearsal is re-attempted (runbook v2) only after this ADR and UT ADR-0043 are
  accepted and their remediation plans implemented and merged. Tier 2 stays blocked on B1, B2,
  and F1.

## Security and privacy impact

None. `channel_case_ref` remains an opaque UUID carrying no content; switching which opaque UUID
it is does not change what crosses the boundary. `legacy_handoff_map` still stores only
ids/uuids/fixed-vocabulary/timestamps; the forbidden-column guard is unchanged. No key material,
plaintext, or classification boundary is touched. If anything the privacy posture improves
slightly: the value on the wire is now the same conversation UUID Support Chat already logs in
its own audit events (`'conversation_uuid' => $conversation->uuid()`), rather than a second
correlatable identifier.

## Affected Documents/Milestones

- `docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md` — §4 `channel_case_ref`
  semantics superseded by this ADR (Status-field note added on acceptance).
- `docs/adr/README.md` — ADR-0011 row; next available number becomes 0012.
- `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` — new; the
  Support Chat comment-correction work packages.
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` — F1 note; runbook v2 pending.
- `docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md` — decision item 7 (adopt
  option (b), reject option (c)).
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` — §0d planning note.
- `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` — the finding this ADR
  resolves (no edit; referenced).

## Compatibility/Migration Impact

- **Schema**: none. `db_version` unchanged at 11. No `ALTER`, no new step, no data backfill.
- **Wire contract**: the *name*, *type* (`CHAR(36)` / v4 UUID string), and *operation set* of
  `channel_case_ref` are unchanged. Its *referent* changes from "adapter binding UUID" to
  "Support Chat conversation UUID". Because Support Chat already resolved it as a conversation
  UUID, the only component that must change to become correct is the Universal Telegram sender
  (UT ADR-0043). There is no window in which a corrected UT talks to an uncorrected SC and
  breaks: an uncorrected SC already expected the conversation UUID.
- **Coordinated rollout**: UT ADR-0043's remediation merges the adapter change; this ADR's
  Support Chat plan merges only comment/doc corrections and fixture alignment. Either order is
  safe. Neither is deployed to production ahead of Product Owner acceptance of both ADRs.
- **Rollback**: revert the doc-correction commit; no runtime effect.
