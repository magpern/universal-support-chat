# Closure Record — SC-M03 Final-Cutover Disposable DEV Rehearsal, Tier 1 (Support Chat side)

## Status

**HALTED at the UT→SC deferred-update handoff phase by finding F1** — a production-behaviour gap
that spans both repositories. The disposable interop harness is validated on the pinned SHAs; the
constituent phases up to the handoff are proven; **the full Tier 1 operational sequence cannot
reach a PASS while F1 stands.**

The authoritative Tier 1 closure, with full finding detail and run-by-run outcomes, is in
Universal Telegram: `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`. This
record is the Support Chat cross-reference.

- **No production runtime code was altered in this repository.**
- **No bypass, terminal acknowledgement, incident-row mutation, or `binding_uuid == conversation_uuid`
  fixture shortcut was used to manufacture a pass.**
- **No DEV VPS or production action, and no Tier 2 infrastructure, occurred.**

## Authority

Product Owner **Approval A** — recorded in
[`docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md)
("Approval A — recorded"). Merged: Universal Support Chat `ad3f8f2728571485405e02951f3caa2201609955`,
Universal Telegram `528a92e2f285f979626fe68620f531bcc2ca93a9`.

## Baselines exercised

- Universal Support Chat `ce4691241eb843485117b323516899df916fdaf7` (accepted), fresh throwaway checkout.
- Universal Telegram `31519ee3ae297369118bf2deda6eae05d13a3d8b` (accepted), fresh throwaway checkout.
- Disposable `docker/docker-compose.yml` + `docker/docker-compose.interop.yml` harness only,
  `docker compose … down -v` before and after every run. Never `/opt/biopentra/dev/*`, never
  `dev.biopentra.eu`. Zero Telegram network traffic.

## What was validated (Support Chat side)

- **Baseline interop suite** on the pinned SHAs: 42 tests / 580 assertions OK (matches the
  final-cutover closure records).
- **Phase A / Phase B / quiescence-loss recovery (Run 2 core)** — the exact Run 2 sequence
  (Phase A backfill → real quiescence window → Phase B refusal on a real mid-run buffered update →
  `exit` → replay every buffered row through **legacy `process_update()`** → backlog 0 + `idle` →
  re-`enter`/`confirm` → Phase B rerun promotes to `migrated`) is proven end-to-end against the
  real Universal Telegram stack by
  `tests/integration/Interop/QuiescenceProviderIntegrationTest.php`
  tests 4 and 5. This path never invokes the handoff, so **F1 does not apply to it** — the Run 2
  recovery core PASSES.
- **`legacy-bind` producing a non-routing `prepared` binding** — proven by
  `tests/integration/Interop/LegacyBindingImportIntegrationTest.php`.

> Historical note (feature/sc-telegram-adapter-dispatch): the two interop test files named
> above (and `SchemaInventoryTest` / `LegacyExportClientIntegrationTest`) were retired when
> Universal Telegram ADR-0044 removed the legacy-chat / SC-M03 classes they load. This closure
> record is left otherwise unchanged as the historical account of the halted Tier 1 attempt.
- **The handoff-contract handler** (`ContractOperationDispatcher::dispatch_with_provenance()`,
  `HandoffMapRepository`, the `409 handoff_provenance_conflict` path, no-plaintext column shape) —
  proven by `tests/integration/ChannelContract/Rest/ContractOperationsControllerTest.php` and the
  Universal-Telegram-side `CutoverHandoffIntegrationTest.php` — **but only with a fixture-seeded
  `binding_uuid == conversation_uuid`** (see F1).

## Finding F1 — the handoff cannot resolve a real prepared binding

**`ContractOperationDispatcher::resolve_conversation()`
(`src/ChannelContract/Rest/ContractOperationDispatcher.php:545-552`) resolves `channel_case_ref`
strictly as this repository's own `conversation_uuid` (`$this->conversations->find_by_uuid( $ref )`;
docblock: "Interim convention: `channel_case_ref` is the Support Chat `conversation_uuid`").**
Universal Telegram's `CutoverReplayDispatcher` / `SupportChatContractClient` /
`InboundAdapterBridge` all send `$binding->binding_uuid()` as `channel_case_ref`. Every real
binding-creation path (`LegacyBindingImportServiceV1` — the only one a cutover cohort activates —
and `EnsureChannelCaseService`) mints an **independent** `binding_uuid ≠ support_conversation_uuid`.

Result: a real cohort's buffered updates get `error( 404, 'not_found' )` from this repository,
which Universal Telegram classifies as retry-transient (never handed off, never an incident), so
`replaying → idle` and `cutover confirm-complete` block permanently.

ADR-0010 §4's schema comment ("`channel_case_ref` … the binding UUID this call resolved to") and
this repository's resolver disagree at the wire. This is a pre-existing seam (not introduced by
the cutover work package) that the rehearsal is the first to exercise with a real binding.

**Proposed resolution directions — require separate ADR-level review, NOT implemented here:**
(1) this repository resolves `channel_case_ref` via a binding→conversation map it persists;
(2) the adapter sends `support_conversation_uuid` as `channel_case_ref` and ADR-0010 §4 is
corrected; (3) the binding-creation paths mint `binding_uuid == support_conversation_uuid`
(needs a WP5 / ADR-0009 amendment). Each changes the Contract v1 wire contract and/or ADR-0010 §4.

## Characterization evidence

A new Universal-Telegram-side interop test,
`tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` (test-harness-only, no `src/`
change in either repository), pins F1: a positive control (`binding_uuid == conversation_uuid` →
hands off) and the finding (real `LegacyBindingImportServiceV1` binding → `OUTCOME_RETRY_TRANSIENT`,
no Support Chat message, no `legacy_handoff_map` row, no `handed_off_at`). 2 tests / 41 assertions,
OK, run in the disposable harness.

## Next step

1. F1 raised for separate ADR-level review. No production code changed.
2. Tier 1 re-attempted only after F1 is resolved and the runbook is revised (v2 — F1's resolution
   becomes a hard precondition gating both tiers).
3. **Tier 2 remains blocked on B1, B2, and F1.** Approval B cannot take effect until Tier 1
   passes and all three are resolved.

## Non-authorization

This closure authorizes nothing. No DEV or production quiescence, migration, binding preparation,
cohort activation, deferred-update replay, Telegram webhook, route switch, cutover, soak,
rollback, deployment, release, tag, deletion, or retention change occurred or is authorized. No
Tier 2 infrastructure was created.
