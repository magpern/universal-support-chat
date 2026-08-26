# Closure Record — SC-M03 Work Package 1: Outbound Contract v1 Client + Joint Interoperability Gate

## Final status

**PASS.** Implements SC-M03 plan v2 (`docs/plans/sc-m03-controlled-migration-and-cutover-plan-v2.md`
§8) work package 1's Support-Chat-owned half: the outbound Contract v1
client this plugin uses to call a paired adapter (the four
Support-Chat-to-adapter operations), plus re-verification that the joint
authenticated interoperability gate against Universal Telegram's real,
merged signed client — plan v2 §8's explicit prerequisite for work package
2 (migration engine) — genuinely passes. Work packages 2+ (migration
engine, cutover orchestration) are **not** implemented here.

This does **not** close SC-M03 itself. SC-M03's charter/plan v2 remain
"Planned" for migration/cutover; only the work package 1 interoperability
gate is closed here.

## What this closes

- The outbound half of ADR-0007's mutual-authentication design: this
  plugin, not only the adapter, can now originate a signed Contract v1
  call and have Universal Telegram's real inbound verifier accept it.
- Plan v2 §8 work package 1's gate condition: "coordinated end-to-end
  authenticated interoperability proof between the two plugins" — proven
  against Universal Telegram's real, merged signed client and inbound
  acceptors, not a local fixture standing in for the adapter.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `2f748168f591bec551a740a5060d394bc6e29ba3` (merge of PR #6, SC-M03 WP0)
- Branch: `feature/sc-m03-ut-interop-wp6`
- Final branch SHA: `0ec44cf8f901c641d4a000abdf70a8764a607eae`
- Plugin version: `0.3.0` → `0.4.0` (minor bump: genuine new capability
  class — the outbound client — per this repository's own versioning
  convention documented in the WP0 closure record)
- Schema version (`universal_support_chat_db_version`): `7` → `8` (step 8:
  `channel_peers.outbound_route_base`)

## Schema changes

| Step | Table/column | Purpose |
|---|---|---|
| 8 | `universal_support_chat_channel_peers.outbound_route_base` | Non-secret routing metadata: the REST route prefix this plugin targets when it originates a call to that paired peer (e.g. `universal-telegram/v1/support-chat`). `NULL` for every peer paired before this column existed — never used for verifying inbound calls, never a credential. |

## New source

- `ChannelContract/Outbound/SignatureSigner.php` — builds and Ed25519-signs
  the exact ADR-0007 §3 ten-line canonical request over this plugin's own
  `OwnKeyManager` key, symmetric to the adapter's own signer.
- `ChannelContract/Outbound/NonceGenerator.php` — generates the same nonce
  format ADR-0007 §3 requires of an outbound sender.
- `ChannelContract/Outbound/IdempotencyKeys.php` — deterministic
  idempotency-key derivation for `ensure_channel_case`/`deliver_message`
  retries.
- `ChannelContract/Outbound/ContractTransport.php` (interface) and
  `InProcessContractTransport.php` — dispatches a signed request via
  `rest_do_request()`, the same in-process transport pattern this
  codebase's own `DiscoveryClient` already uses to reach a route inside the
  same WordPress install (real signing, real REST dispatch — never a
  bypass of either).
- `ChannelContract/Outbound/AdapterContractClient.php` — the four
  Support-Chat-to-adapter operations: `ensure_channel_case`,
  `notify_operators`, `deliver_transcript_backfill`, `deliver_message`.
  Fails closed (never signs/sends) if the target peer is unpaired,
  disabled, revoked, or expired, or lacks an `outbound_route_base`.
- `Persistence/Migrator.php` — step 8 above.
- `Core/Plugin.php` — wires `AdapterContractClient` for real use by SC's
  own conversation/channel-status event paths (unchanged conversation
  domain logic; this closure does not add any new automatic-mirroring
  trigger — see the UT-side closure's item 9 confirmation, re-verified
  against this exact branch).

## Interoperability re-verification (this closure's own responsibility)

A companion Universal Telegram work package (`feature/ut-adapter-m1-interop-wp6`,
closed in `ut-adapter-m1-wp6-interop-gate-closure.md` in that repository)
built a dual-plugin Docker harness that loads **this exact branch's real
source** (mounted by host path, linked into a disposable WordPress
install — `docker/docker-compose.interop.yml`,
`tests/bin/install-support-chat.sh` in the UT repository) alongside
Universal Telegram's own real, merged code, and performs a real two-way
Ed25519 pairing between the two plugins' own production
`OwnKeyManager`/`PairingService` instances, with REST routes resolved by
each plugin's own production `Plugin::boot()` — no test-only route
registration.

This closure record does not re-describe that harness's full 10-item
interoperability matrix (see the UT-side closure record for that); it
records that **this SC branch, at SHA `0ec44cf8f901c641d4a000abdf70a8764a607eae`,
is the exact code that matrix was run against**, and that the run was
genuinely green: **35 interop tests, 413 assertions, all passing**,
covering (among other things) every one of this branch's four new outbound
operations dispatched through `AdapterContractClient` against Universal
Telegram's real, merged `OutboundContractController` and
`SignatureVerifier` — not a fixture standing in for the adapter.

## Test evidence (this repository's own quality gate, run directly — not estimated)

| Check | Command | Result |
|---|---|---|
| Unit | `bin/docker/test-unit.sh` | 59 tests, 150 assertions — OK |
| Integration (WP 6.9 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.3` | 70 tests, 282 assertions — OK |
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 0 warnings |
| PHPStan (level 5) | `bin/docker/phpstan.sh` | 0 errors |
| Doc links | `composer check-doc-links` (via `bin/docker/composer.sh`) | Clean |

New test files (this branch, predating this closure record but
re-verified by it): `tests/unit/ChannelContract/Outbound/{SignatureSignerTest,NonceGeneratorTest,IdempotencyKeysTest,AdapterContractClientTest}.php`,
`tests/integration/ChannelContract/Outbound/AdapterContractClientTest.php`.
`ActivationTest`/`MigratorTest` updated only for the new `db_version` 8 and
`outbound_route_base` column, remaining green.

## Known limitations (explicit, not defects)

- **`channel_case_ref` == `conversation_uuid`**, unchanged from the WP0
  closure record — still the deliberate interim convention; not revisited
  by this work package.
- **`PeerRecord::pairing_state()` still never returns `degraded` or
  `incompatible`**, unchanged from WP0 — out of scope for this work
  package.
- This closure's own test suite signs outbound requests against a real,
  locally generated Universal Telegram peer key (the WP0 closure's "no
  live peer" limitation is now resolved for the interop-harness
  environment specifically, via the companion UT-repository work package's
  dual-plugin Docker harness) — but no real-site pairing against a
  production Universal Telegram installation has occurred, and none is
  claimed.

## Explicit confirmation of scope boundaries

- **No** migration engine, cutover orchestration, quiescence, route
  switch, soak, or rollback code — plan v2 §8 work packages 2+ remain
  entirely unimplemented.
- **No** removal of any Universal Telegram legacy UI, tab, or setting
  (out of scope for this repository entirely; the companion UT-side work
  package independently confirms none was touched there either).
- **No** chat visual redesign (SC-M05), Availability/offline-ticket UX
  (SC-M06, `update_operator_presence` remains unimplemented and
  unreachable, unchanged from WP0), or AI (SC-AI1/SC-AI2).
- **No** shared secret, public REST bypass, application-password
  shortcut, or direct Universal Telegram table query from this plugin —
  `AdapterContractClient` only ever originates a signed, verified Contract
  v1 REST call, same authentication profile as every inbound route.
- **No** release, tag, deployment, or real-site pairing/cutover. This
  branch is not merged by this task.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.

## Next task

Once both this PR and the companion Universal Telegram PR
(`feature/ut-adapter-m1-interop-wp6`) are reviewed and merged, SC-M03 plan
v2 §8's work package 1 gate is satisfied and work package 2 (migration
engine) may begin as its own separately planned, implemented, and closed
unit of work — nothing in this closure record authorizes starting it
directly.
