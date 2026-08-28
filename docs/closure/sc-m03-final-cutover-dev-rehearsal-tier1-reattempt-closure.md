# Closure Record — SC-M03 Final-Cutover Disposable DEV Rehearsal, Tier 1 re-attempt (Support Chat side)

## Status

**PASS.** The single Product-Owner-authorised Tier 1 re-attempt under DEV rehearsal runbook v2 was
executed against the immutable execution baselines on both supported WP/PHP variants. Support Chat
participated as the real Contract v1 peer in the dual-plugin interop harness; **no Support Chat
runtime code, schema, `universal_support_chat_db_version` (11), test, configuration, or workflow
was changed** — the checkout is the immutable baseline, detached HEAD, clean tree.

The authoritative closure, with the full evidence bundle and run-by-run detail, is in Universal
Telegram: [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`](https://github.com/magpern/universal-telegram/blob/main/docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md).
This record is the Support Chat cross-reference.

- **No bypass, terminal acknowledgement, incident-row mutation, or `binding_uuid == conversation_uuid`
  fixture shortcut was used to manufacture a pass.**
- **No DEV VPS or production action, no Telegram network traffic, and no Tier 2 infrastructure.**
- **This closure authorizes nothing.** Tier 2 remains blocked on B1 and B2 and pending Approval B.

## Authority

Product Owner **Approval A addendum**, recorded / accepted 2026-08-28 in
[`docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`](../decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md)
(Addendum C). Merged: Universal Support Chat `9aaf2685bccc1655d501c7827986df1e18409f7f`, Universal
Telegram `4458ada28c25594a563d05559991e98d19598549`. It authorises **exactly one (1)** Tier 1
re-attempt at the two immutable baseline SHAs and nothing else. A second attempt, or any change
to the immutable baseline SHAs, requires a new Product Owner approval.

## Baselines exercised — proof no DEV checkout was touched

- Universal Support Chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (immutable Tier 1 execution
  baseline), fresh throwaway checkout, detached HEAD, `git status --porcelain` clean. Verified to
  exist on `origin` before checkout. Runtime tree byte-identical to the F1 implementation commit
  `9144cb1` — `git diff --name-only 9144cb1 HEAD` lists `docs/` paths only.
- Universal Telegram `6eed0228286e84b4e56e0119f242b483f138a58e` (immutable Tier 1 execution
  baseline), fresh throwaway checkout.
- Disposable `docker/docker-compose.yml` + `docker/docker-compose.interop.yml` harness only,
  driven through the approved `bin/docker/*.sh` entry points, with `SUPPORT_CHAT_HOST_PATH`
  pointed at the throwaway checkout. `docker compose config` confirms the default
  `/opt/biopentra/dev/universal-support-chat` mount is never used. `docker compose … down -v`
  before and after every run; no `t1re` container, volume, or network survives.
- Zero Telegram network traffic (external-HTTP test group disabled; `pre_http_request` boundary
  in place; no token, `setWebhook`, or message send anywhere in the suite).

## What was validated — Support Chat side

Support Chat's real Contract v1 server, `ContractOperationDispatcher`, `HandoffMapRepository`,
`ConversationRepository`, `ChannelStatusRepository`, and `Migrator` were exercised as the real
peer, on both variants:

- **Dual-plugin interop suite** — `OK (47 tests, 722 assertions)` on floor (WP 6.9 / PHP 8.1) and
  current (WP 7.1 / PHP 8.3), matching the F1 implementation closure count exactly.
- **F1-correction gate** — a real `LegacyBindingImportServiceV1`-prepared binding
  (`binding_uuid ≠ support_conversation_uuid`) is handed off; Support Chat's
  `resolve_conversation()` resolves the wire `channel_case_ref` (= the conversation UUID) to the
  real conversation; exactly one Support Chat message; exactly one `legacy_handoff_map` row keyed
  by the conversation UUID, never the binding UUID.
- **Fail-closed classification (Run 3)** — a `404 not_found` from `resolve_conversation()` →
  UT `unresolved_case_reference` incident (no map row, backlog stays blocked); a deterministic
  `400 invalid_body` (oversized reply) → UT `handoff_rejected` incident (no map row, no Support
  Chat message); an unrecognised `ok:false` reason → `handoff_rejected`, never retryable.
- **Provenance / idempotency** — one domain effect + one map row per handed-off update;
  re-presented `(bot_id, update_id)` converges; a mismatched pre-seeded map row → real
  `409 handoff_provenance_conflict`, rollback, no domain write, no map write.
- **No plaintext** — `conversation_messages` body column is ciphertext; `legacy_handoff_map`
  carries only ids/uuid/kind/timestamp columns; `Migrator::verify_step_11` forbidden-column
  guard passed as part of interop provisioning.
- **Phase A / Phase B / quiescence-loss recovery (Run 2 core)** — proven end-to-end against the
  real stack by `QuiescenceProviderIntegrationTest` and the companion Migration suites (part of
  the wp-only integration run, `OK (1131 tests, 3758 assertions)` on both variants).

## Evidence bundle

Raw logs on the operator scratchpad (not committed): `scratchpad/t1re-evidence/` — per-variant
`10-interop.txt` / `11-unit.txt` / `12-wp-only.txt` with `EXIT=0`, `interop-testdox.txt`,
`unit-classifier-testdox.txt`, and `01-down-pre.txt` / `13-down-post.txt` / `14-volumes-after.txt`
teardown proof. All fixture data synthetic; no ciphertext, token, credential, or key retained.

## Next step

**Tier 1 is complete.** No further Tier 1 run is authorised. The next possible activity is Tier 2 —
the actual disposable DEV rehearsal — blocked on B1 (no isolated full-WordPress instance) and B2
(no dedicated non-production Telegram bot) and pending a separate signed Approval B. Nothing in
this record authorises Tier 2, any DEV VPS action, any Telegram network traffic, any production
activity, or any operational cutover action.

## Non-authorization

This closure authorizes nothing. No DEV or production quiescence, migration, binding preparation,
cohort activation, deferred-update replay, Telegram webhook, route switch, cutover, soak,
rollback, deployment, release, tag, deletion, or retention change occurred or is authorized. No
Tier 2 infrastructure was created. No Support Chat runtime code, schema, or `db_version` was
changed. The immutable Tier 1 execution baseline SHAs are unchanged.
