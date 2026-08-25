# Closure Record — SC-M03 Work Package 0: Authenticated Contract v1 Server

## Final status

**PASS.** Implements only SC-M03 plan v2 work package 0 (`docs/plans/sc-m03-controlled-migration-and-cutover-plan-v2.md` §8). Work packages 1–8 (Universal Telegram signed client, joint interoperability tests, migration engine, cutover orchestration) are **not** implemented here.

## What this closes

- The authenticated, capability-checked Contract v1 server ADR-0005 §5 required and ADR-0007 specified.
- The peer-key pairing authority, nonce replay protection, and signature verification ADR-0007 §2–§4 defines.
- Wiring of the eight **implemented** adapter → Support Chat operations to Support Chat's existing domain services, gated strictly behind successful authentication. `update_operator_presence` is a real Contract v1 name (ADR-0005 §5) this work package does not implement; it is not on the allow-list, not advertised by discovery, and not dispatchable (see below) — it is not counted among the eight.

This does **not** close SC-M03 itself. SC-M03's charter/plan v2 remain "Planned" for migration/cutover; only work package 0 is closed here.

## Preconditions confirmed

- Universal Telegram PR #33 (`docs(adr): pin Support Chat ADR-0007 and scope UT signed Contract client`) — **MERGED**, `main` SHA `51ab1aa99e8925913aa5a85db93c620585a38762`.
- Support Chat PR #5 (ADR-0007) — **MERGED**, `main` SHA `8ee396d8b8edcbf526797c0a1f5741f3842df57a`.

## Baseline

- Repository: `magpern/universal-support-chat`
- Starting commit (`origin/main`): `8ee396d8b8edcbf526797c0a1f5741f3842df57a`
- Branch: `feature/sc-m03-contract-server`
- Plugin version: `0.2.0` → `0.3.0` (minor bump: genuine new capability class, per this repository's own versioning convention)
- Schema version (`universal_support_chat_db_version`): `4` → `7` (steps 5–7: channel peers, contract nonces, channel status)

## Schema changes

| Step | Table | Purpose |
|---|---|---|
| 5 | `universal_support_chat_channel_peers` | Peer public key, key ID, allowed-operation allow-list, pairing metadata, status, expiry (ADR-0007 §2) |
| 6 | `universal_support_chat_contract_nonces` | `(sender, key_id, nonce)` replay store, 600-second retention (ADR-0007 §3) |
| 7 | `universal_support_chat_channel_status` | Per-conversation channel availability, set only by authenticated `report_channel_unavailable` or `report_delivery_failure` (ADR-0005 §3) |

New options (not tables, not secret except as noted): `universal_support_chat_contract_own_key` (this site's own public key + key ID), `universal_support_chat_contract_own_key_secret` (this site's own private key, encrypted via the existing `CredentialVault`, AES-256-GCM — never plaintext at rest, never exposed by any code path).

No Telegram-native column, table, or namespace reference exists anywhere in `src/` (`tests/unit/Core/NoTelegramCouplingTest.php`, unchanged, passes against the new code).

## New source (all under the already-authorized `ChannelContract` boundary)

- `ChannelContract/Auth/`: `ContractIdentity`, `ContractOperations`, `KeyId`, `OwnKeyManager`, `PeerRecord`, `PeerRepository`, `NonceReplayRepository`, `NonceCleanupHandler`, `SignatureVerifier`, `VerificationResult`, `PairingService`, `PairingResult`.
- `ChannelContract/ChannelStatusRepository.php`.
- `ChannelContract/Rest/ContractOperationsController.php`, `ChannelContract/Rest/ContractOperationDispatcher.php`.
- `ChannelContract/Admin/PairingPage.php`, `ChannelContract/Admin/PairingActions.php`.
- `ContractDiscovery` amended: discovery is now truthful (`channel_available` and `operations` reflect actually-paired, usable peers) and advertises `auth_profile: support-channel-contract-auth/v1`.
- `Conversations/ConversationRepository` gained `claim()`, `release()`, `assign()` — concurrency-safe primitives the Contract operations `claim`/`release`/`update_assignment` needed and SC-M01/SC-M02 had not yet required.

## Authentication mechanism (ADR-0007, implemented exactly)

- Mutual Ed25519 request signing via libsodium (`sodium_crypto_sign_keypair`/`_detached`/`_verify_detached`). Support Chat's private key is generated locally and never leaves `OwnKeyManager`; it is stored only as a `CredentialVault` envelope.
- Pairing is a WordPress-admin action requiring `current_user_can( CapabilityRegistrar::MANAGE )` **and** `current_user_can( $required_peer_capability )`, where `$required_peer_capability` is data the pairing administrator supplies (e.g. `universal_telegram_manage`), never a literal reference to any specific adapter plugin in source code — this keeps the mechanism generic across future adapters and keeps `NoTelegramCouplingTest` honest rather than working around it.
- Every mutation route verifies, in order: no query string; all nine required headers present; exact `contract_version`/`auth_profile` match; `audience` is this plugin; peer exists and `is_usable()` (active, unrevoked, unexpired); `key_id` matches the peer's recorded key; operation is on the peer's allow-list; timestamp within ±300s; nonce well-formed; body SHA-256 matches the received bytes; Ed25519 signature verifies over ADR-0007 §3's exact ten-line canonical string; nonce atomically recorded (a duplicate insert — the database's own `UNIQUE KEY` — is the race-free replay guard).
- **Fail-closed proof:** any single failure above short-circuits to the same response — `401 {"ok": false, "reason": "contract_auth_failed"}` — before any domain mutation is attempted. `ContractOperationsControllerTest` exercises eleven distinct failure causes (missing header, wrong sender, wrong audience, unknown key ID, invalid signature, tampered body, stale timestamp, query string present, nonce replay, operation off the peer's allow-list, revoked key, expired key) and asserts both the identical response body and that the target conversation's `assigned_operator_id` is unchanged in every case.

## Discovery

`GET universal-support-chat/v1/channel-contract` (unauthenticated, unchanged route) now returns:

```json
{
  "ok": true,
  "contract_version": "support-channel-contract/v1",
  "auth_profile": "support-channel-contract-auth/v1",
  "adapter_required": false,
  "channel_available": false,
  "operations": []
}
```

`channel_available` and `operations` become truthful once a peer is paired, active, and unexpired — never a fixed catalog regardless of pairing state, and never any information beyond the boolean/array fields above (ADR-0007 §3, §5: no existence leak to an unauthenticated caller). `operations` can only ever list a subset of `ContractOperations::ADAPTER_TO_SUPPORT_CHAT` (eight names); `update_operator_presence` is excluded from that constant itself, so no peer allow-list, and therefore no discovery response, can ever contain it — advertising an operation is structurally tied to that operation actually being dispatchable, not asserted independently.

## Admin UI

Settings → Support Chat Pairing (`options-general.php?page=universal-support-chat-pairing`, `CapabilityRegistrar::MANAGE`-gated): shows this site's own public key/key ID (never the private key), the list of paired peers with their pairing state (`active`/`paired_disabled`/`revoked`/`expired` are computed; `degraded`/`incompatible` are named in `PeerRecord::pairing_state()`'s docblock but not yet computed — see Known limitations), and forms for pairing, confirm-before-replace, revoke, disable/enable, and rotating this site's own key. The allowed-operations checklist iterates `ContractOperations::ADAPTER_TO_SUPPORT_CHAT` directly, so `update_operator_presence` cannot be offered there either. No form or page ever renders a private key, a signature, a nonce, or message content.

## Test evidence

Full SC quality gate, run via this repository's own Docker tooling:

| Check | Command | Result |
|---|---|---|
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 0 warnings (after `phpcbf` auto-fixed 22 pure alignment warnings) |
| PHPStan (level 5) | `bin/docker/phpstan.sh` | 0 errors |
| Unit (PHP 8.1) | `bin/docker/test-unit.sh` | 35 tests, 68 assertions — OK |
| Unit (PHP 8.3) | `bin/docker/test-unit.sh --php-version=8.3` | 35 tests, 68 assertions — OK |
| Unit (PHP 8.4) | `bin/docker/test-unit.sh --php-version=8.4` | 35 tests, 68 assertions — OK |
| Integration (WP 6.9 / PHP 8.1) | `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` | 64 tests, 254 assertions — OK |
| Integration (WP 7.1 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` | 64 tests, 254 assertions — OK |
| Doc links | `bin/docker/composer.sh run-script check-doc-links` | Clean |

New test files: `tests/unit/ChannelContract/Auth/{KeyIdTest,ContractOperationsTest}.php`; `tests/integration/ChannelContract/Auth/{OwnKeyManagerTest,PairingServiceTest,NonceReplayRepositoryTest}.php`; `tests/integration/ChannelContract/Rest/ContractOperationsControllerTest.php`. Existing suites (`MigratorTest`, `ActivationTest`, `VisitorRestTest`, `NoTelegramCouplingTest`, `StructuralBoundariesTest`, all SC-M00–SC-M02 suites) updated only where the new schema version (`4`→`7`) or `ContractDiscovery`'s new constructor dependency required it, and remain green.

Required-matrix coverage: key generation/custody and no private-key exposure (`OwnKeyManagerTest`); idempotent pairing, confirm-before-replace, rotation, revocation, expiry, all four reachable pairing states (`PairingServiceTest`); valid signed request accepted and reaches the correct domain service for `claim`, `ingest_operator_reply`, `resolve`/`reopen`, `report_channel_unavailable`, `report_delivery_failure` (`ContractOperationsControllerTest`); the eleven-cause uniform-denial matrix with no-mutation proof, **plus a twelfth case** proving a fully well-formed, correctly signed request for `update_operator_presence` is denied with the identical response (`test_update_operator_presence_is_denied_as_unsupported`) (`ContractOperationsControllerTest`); `report_delivery_failure` persists a degraded channel status and is safe to repeat — a second identical call produces exactly one row, not a duplicate or an error (`test_report_delivery_failure_marks_channel_status_degraded`, `test_report_delivery_failure_is_safe_to_repeat`); nonce retention/cleanup (`NonceReplayRepositoryTest`); Support Chat fully usable with no paired peer (`test_contract_discovery_is_unavailable_with_no_paired_peer`, `test_hub_and_widget_conversation_lifecycle_unaffected_by_no_paired_peer`); `update_operator_presence` is structurally absent from the implemented-operation catalog and from any valid peer allow-list (`ContractOperationsTest`); no Telegram-native persistence or namespace coupling (`NoTelegramCouplingTest`, unchanged, still passes); SC-M00–SC-M02 suites remain green.

**Gap, not silently claimed as covered:** the "both capabilities" pairing gate (`current_user_can( CapabilityRegistrar::MANAGE ) && current_user_can( $required_peer_capability )`) lives in `PairingActions::handle_pair`/`guard()`, matching this codebase's existing convention of leaving admin-post handlers (which call `wp_safe_redirect()` + `exit`) untested directly — `HubActions` has no dedicated test file for the same reason, and this closure does not claim otherwise. `PairingService` itself records `required_peer_capability` as data but does not re-check `current_user_can()`; the capability gate is enforced only at the admin-post layer, consistent with how every other Hub mutation in this codebase is gated.

## Resolved implementation behaviour (corrected in this closure)

Two points identified during review conflicted with ADR-0007's requirement that discovery advertise only actually-supported operations. Both are now resolved, not deferred:

- **`update_operator_presence` is not an implemented Contract operation, and is unreachable rather than a silent no-op.** It is absent from `ContractOperations::ADAPTER_TO_SUPPORT_CHAT` entirely — not merely excluded from one peer's allow-list. Consequences of that single change, all verified by test: no peer can ever be paired with it (`PairingService`/`is_valid_adapter_allow_list` rejects it); discovery can never advertise it (`ContractDiscovery` only ever emits a subset of the same constant); the admin pairing UI cannot offer it as a checkbox; and a fully well-formed, correctly signed authenticated request naming it as the operation is denied with the **same uniform ADR-0007 §3 response** (`401 {"ok": false, "reason": "contract_auth_failed"}`) as any other authentication failure — `SignatureVerifier::verify()` fails the operation-recognition check before any peer/allow-list/signature check runs, so the denial is indistinguishable from "your signature is wrong," not a distinct "not implemented" error that would leak which operations exist. No Availability/presence storage or SC-M06 functionality was added; Support Chat's Availability boundary remains unauthorized and untouched, exactly as before.
- **`report_delivery_failure` now performs a real, SC-owned, persisted mutation** — it no longer only audits the event. It reuses the existing `universal_support_chat_channel_status` table and `ChannelStatusRepository::mark_degraded()` (the same primitive `report_channel_unavailable` already used), no migration or schema change required: it marks the reporting conversation's channel status `degraded` with the caller-supplied (or `delivery_failed`-default) reason code. This stores only the conversation's own opaque UUID association and a fixed reason-code string — never a Telegram-native ID, a message body, or delivery-queue state. The mutation is safe to repeat: the table's `UNIQUE KEY conversation_id` plus an `INSERT ... ON DUPLICATE KEY UPDATE` upsert means a second identical call updates the same row rather than erroring or duplicating, proven by `test_report_delivery_failure_is_safe_to_repeat` (asserts exactly one row after two calls) and `test_report_delivery_failure_marks_channel_status_degraded` (asserts the persisted status and reason code). Discovery can now honestly advertise `report_delivery_failure` because the behaviour it names actually exists — it was never removed from `ContractOperations::ADAPTER_TO_SUPPORT_CHAT`, since unlike `update_operator_presence` it required no unauthorized boundary to implement for real.

## Known limitations (explicit, not defects)

- **`channel_case_ref` == `conversation_uuid` for this work package.** ADR-0005 defines `channel_case_ref` as an opaque value the adapter receives from `ensure_channel_case` (a Support Chat → adapter call this work package does not implement — no binding/ensure-channel-case infrastructure exists yet). Using the conversation's own UUID as the interim `channel_case_ref` is a deliberate, documented convention, not a schema invention; it is expected to be revisited when `ensure_channel_case` and binding storage are implemented (SC-M03 work packages 2+ per plan v2 §8). This applies equally to `report_delivery_failure`'s new persisted mutation above.
- **`PeerRecord::pairing_state()` never returns `degraded` or `incompatible`.** Both require live discovery/callback signals (comparing against the peer's own discovery response, or aggregating conversation-level `report_channel_unavailable`/`report_delivery_failure` calls) that are out of scope for a server that only verifies inbound calls and does not yet call out to any adapter. The four reachable states (`active`, `paired_disabled`, `revoked`, `expired`) are fully implemented and tested.
- **No live Universal Telegram peer exists to pair against.** Every test in this closure signs requests with a locally generated Ed25519 keypair standing in for a future Universal Telegram signed client (SC-M03 plan v2 work package 1, external repository, not implemented here). End-to-end interoperability against the real Universal Telegram signed client is explicitly the next task.

## Explicit confirmation of scope boundaries

- **No** Universal Telegram signed-client code, and **no** modification to any file in the `universal-telegram` repository.
- **No** end-to-end cross-repository interoperability test — only Support Chat-owned test seams (a locally generated test keypair standing in for a future adapter).
- **No** legacy migration, data import, quiescence, route switch, cutover, rollback, or soak code.
- **No** removal of any Universal Telegram legacy UI, tab, or setting (out of scope for this repository entirely).
- **No** chat visual redesign (SC-M05), availability/offline-ticket UX (SC-M06), or AI (SC-AI1/SC-AI2).
- **No** release, tag, deployment, or real-site pairing/cutover. This branch is not merged by this task.

## Next task

**Universal Telegram signed-client implementation** (`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` in the Universal Telegram repository, work packages 1–5): replace `SupportChatContractClient`'s fail-closed stubs with genuine ADR-0007 signing, add Universal Telegram's own pairing UI and inbound signature verification for the Support Chat → adapter operations. Then joint end-to-end authenticated interoperability tests across both plugins (work package 6) — a hard gate SC-M03 plan v2 §8 work package 1 requires before any migration/cutover code is written.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.
