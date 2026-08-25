# ADR-0007 — Contract v1 Mutual Signed Adapter Authentication Profile

## Status

Accepted

## Context

[ADR-0005](0005-canonical-support-channel-contract-v1.md) requires that adapter → Support Chat Contract calls be "authenticated, capability-checked", and that Support Chat "rejects unauthenticated or capability-insufficient calls." Neither ADR-0005 nor the frozen [SC-M03 plan](../plans/sc-m03-controlled-migration-and-cutover-plan-v1.md) names an actual authentication mechanism.

UT Adapter M1 was implemented against this gap deliberately: `SupportChatContractClient` (Universal Telegram repository) stubs every UT → Support Chat call to fail closed with `sc_authenticated_contract_unavailable`, and its closure record states the missing mechanism explicitly, ruling out a bare `rest_do_request()` current-user context, a generic WordPress capability alone, an insecure shared secret, and a public REST bypass.

SC-M03 cannot begin implementation — per `docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on") — until this mechanism is fixed. This ADR fixes it. It does not implement it.

## Decision

### 1. Authentication model

Contract v1 mutation calls between Support Chat and a channel adapter use **mutual Ed25519 request signing**. Every authenticated Contract call is a WordPress REST request whose sender proves possession of a private key the receiver has previously paired against, over the exact bytes of that request.

- Support Chat and each adapter (e.g. Universal Telegram) each generate and hold their **own** Ed25519 key pair. A plugin never generates or holds another plugin's private key.
- Each plugin stores **only its own private key**, encrypted in **its own** existing encrypted credential vault (Support Chat: `UniversalSupportChat\Core\Security\CredentialVault`, AES-256-GCM, ADR-0003 encryption posture). The private key never leaves the plugin that generated it and is never transmitted, logged, or included in any Contract payload.
- Each plugin stores, for each paired peer, **only**: the peer's public key (raw 32 bytes), the peer's key ID, pairing status, the peer's permitted-operation allow-list (§4), and non-sensitive pairing metadata (created time, last-rotated time, expiry policy, last-successful-call time). Public keys and this metadata are not secret and are not vault material.
- There is **no shared HMAC secret** anywhere in this profile — signature verification uses only the previously-paired public key, never a value both sides must independently keep confidential.
- WordPress application passwords are **not** used as the runtime machine-authentication mechanism for Contract mutation calls.
- A bare `rest_do_request()` call executed in the calling plugin's own current-user context is **not** authentication for Contract mutation calls and must never be treated as such.
- No Contract mutation route may be reachable by an unauthenticated or merely capability-holding caller; there is no public mutation bypass.
- No plugin reads or writes another plugin's database tables directly to authenticate or authorize a Contract call.
- Support Chat never stores Telegram-native IDs, credentials, bot/topic identifiers, or delivery-queue state, signed or otherwise (ADR-0002, ADR-0005 §1).

This profile authenticates a WordPress REST HTTP request between two plugins running in the same WordPress install today (in-process, same origin) and does not depend on that co-location: nothing in the signed-request profile (§3) assumes a shared PHP process, a shared database, or a shared WordPress user session. A future adapter running as a genuinely remote HTTP peer uses the identical signing, pairing, and verification rules unchanged.

### 2. Pairing and trust establishment

Pairing is the one-time (per key generation) administrative act of each plugin learning the other's current public key and key ID, and recording what that peer is allowed to call. Pairing is distinct from, and far less frequent than, the per-request signing in §3.

- **Local key generation.** Each plugin generates its own Ed25519 key pair locally (e.g. via `sodium_crypto_sign_keypair()`), on its own side, never receiving or deriving the other plugin's private key.
- **Administrator-initiated, both-sides-authorized.** Pairing is initiated only by a request made in the session of a currently-authenticated WordPress user who holds the manage capability of **both** plugins involved (Support Chat: `universal_support_chat_manage`; Universal Telegram: `universal_telegram_manage` — existing capability constants, not invented here). Neither plugin's pairing endpoint accepts a request from a user missing either capability. This administrator-session check authenticates the *pairing* action itself; it is never substituted for per-request signing (§3) on ongoing Contract mutation calls.
- **Public material only.** The pairing exchange carries only each side's public key and key ID — never a private key, never a shared secret, never a signature over anything but the pairing confirmation itself.
- **Recorded state.** Each side explicitly records, for the peer it just paired: peer identity (adapter/plugin slug), the peer's key ID and public key, the permitted-operation allow-list drawn from §4 (never invented ad hoc), status, creation time, an expiry policy (a pairing may be configured to never expire or to expire on a fixed schedule; absence of an explicit policy means "no expiry"), and an audit event recording who paired it and when.
- **Idempotent, confirm-before-replace.** Re-running pairing with an unchanged public key is a no-op. Pairing that would **replace** an already-active peer key requires an explicit, separate administrator confirmation step (a second deliberate action, not implied by re-submitting the same form) before the prior key is superseded. The prior key remains valid for verification until that confirmation.
- **Revocation and rotation are first-class.** An administrator may revoke a peer's key (immediately stops verifying that key; existing signed calls using it are rejected) and may rotate their own plugin's key (generates a new local key pair; the old public key remains recorded as `revoked` for audit, not deleted) independently of pairing. Rotation on one side always requires re-pairing on the peer side before that peer's calls succeed again — rotation is never silently propagated.
- **No sensitive material ever surfaces.** Private keys, raw Ed25519 signatures, nonces used in a live signed request, and any pairing confirmation token are never written to diagnostics pages, audit records, REST responses, or logs. Audit records may name the key ID, the acting administrator, and the action (`paired`, `replaced`, `revoked`, `rotated`, `expired`) — never key material.
- **Operator-facing pairing states** (surfaced in each plugin's admin UI/diagnostics, never to visitors):

  | State | Meaning |
  |---|---|
  | `unpaired` | No peer key recorded for this channel. |
  | `paired_disabled` | A peer key is recorded but the administrator has turned the channel off locally. |
  | `active` | A peer key is recorded, enabled, unexpired, unrevoked, and the peer has advertised a compatible discovery profile (ADR-0006 "compatible"). |
  | `degraded` | Paired and enabled, but the peer has reported unavailability (`report_channel_unavailable`) or discovery currently reports `channel_available: false`. |
  | `revoked` | The peer's key was explicitly revoked by this plugin's administrator; calls signed with it are rejected. |
  | `expired` | The peer's key passed its recorded expiry policy; calls signed with it are rejected until re-paired. |
  | `incompatible` | A peer key is recorded and unrevoked/unexpired, but discovery reports a contract version, auth-profile version, or required-operation set this plugin does not support (ADR-0006). |

  Every state other than `active` fails closed for that channel only (§5); it never affects Support Chat's own Hub/widget operation.

### 3. Canonical signed-request profile

This is the wire-level profile every authenticated Contract mutation call (§4) must follow. Contract discovery (the existing unauthenticated `GET` handshake) is unaffected and remains callable without a signature — see the addition to discovery output below.

**Auth profile identifier:** `support-channel-contract-auth/v1`. This identifier is versioned independently of the Contract operation-set version (`support-channel-contract/v1`, ADR-0005 §7); a future auth-mechanism change ships as `.../v2` without requiring a new Contract operations ADR, and vice versa.

**Required headers** on every authenticated request:

| Header | Value |
|---|---|
| `X-SC-Contract-Version` | `support-channel-contract/v1` (ADR-0005 §7, verbatim) |
| `X-SC-Auth-Profile` | `support-channel-contract-auth/v1` (verbatim) |
| `X-SC-Sender` | Sender plugin slug, e.g. `universal-telegram` or `universal-support-chat` |
| `X-SC-Audience` | Intended recipient plugin slug (the same two values as above, other side) |
| `X-SC-Key-Id` | Sender's current key ID (format below) |
| `X-SC-Timestamp` | Unix seconds, decimal ASCII, matching the value used in the signed string |
| `X-SC-Nonce` | Per-request random nonce (format below), matching the value used in the signed string |
| `X-SC-Body-Sha256` | Lowercase hex SHA-256 of the exact raw request body bytes (empty-body requests use the SHA-256 of the empty string) |
| `X-SC-Signature` | Standard base64 (RFC 4648 §4, with padding) of the raw 64-byte Ed25519 signature |

**Canonical signed string.** The sender builds the following UTF-8 byte string, one field per line, joined by a single `\n` (0x0A), no trailing newline, no carriage returns, and signs it with `sodium_crypto_sign_detached()`:

```
support-channel-contract-auth/v1
support-channel-contract/v1
<X-SC-Sender>
<X-SC-Audience>
<X-SC-Key-Id>
<X-SC-Timestamp>
<X-SC-Nonce>
<HTTP method, uppercase>
<canonical route path>
<X-SC-Body-Sha256>
```

The first line is a fixed **domain-separation constant** — literally the auth-profile identifier — so a valid signature can never be replayed as if it authenticated a different protocol or purpose. The second line pins the Contract operations version being invoked, so a signature cannot be replayed across an incompatible Contract version. Every other line must be byte-identical to the corresponding header value; the receiver rejects the request if they diverge.

**Canonicalization rules:**

- **Encoding:** every field is UTF-8; the canonical string itself is treated as raw bytes for signing (no re-encoding, no normalization beyond exact UTF-8).
- **Body:** the hash covers the exact bytes transmitted as the request body, before any JSON decoding or WordPress request-object parsing; a receiver that decodes the body must hash the raw bytes it received, not a re-serialization.
- **Query parameters:** **forbidden.** A request carrying any query string is rejected before signature verification. All call parameters travel in the JSON body. This removes an entire class of canonicalization ambiguity (parameter ordering, encoding, duplicate keys).
- **Route path:** the exact REST route path as registered (e.g. `/universal-support-chat/v1/contract/ingest-operator-reply`), without scheme, host, or the `/wp-json` prefix variability some sites apply — receivers canonicalize using the same registered-route value they use to dispatch the request, not the raw `REQUEST_URI`.
- **Timestamp-skew policy:** the receiver rejects any request where `abs(receiver_now_unix - X-SC-Timestamp) > 300` (five minutes).
- **Nonce:** 16 raw random bytes (`random_bytes(16)`), encoded as unpadded base64url (RFC 4648 §5, 22 characters) in `X-SC-Nonce` and in the signed string, identically.
- **Nonce replay store:** the receiver records `(X-SC-Sender, X-SC-Key-Id, X-SC-Nonce)` durably for at least the acceptance window plus a clock-skew margin (600 seconds total) and rejects any request that repeats a tuple still within that retention window. Expired entries may be purged by routine housekeeping (each plugin's existing scheduled-cleanup mechanism). The replay store holds only the nonce, sender, key ID, and the time it was recorded — never a request body or any Contract payload field.
- **Key-ID format:** `<sender-plugin-slug>.<16-lowercase-hex-chars>`, where the hex suffix is the first 8 bytes of `SHA-256(raw 32-byte Ed25519 public key)`, hex-encoded — e.g. `universal-telegram.9f3a1c02b7e4d810`. A key ID is a stable, non-secret fingerprint of a specific public key generation; rotation always produces a new key ID.
- **Signature encoding:** standard (padded) base64 of the raw 64-byte Ed25519 detached signature, ASCII in the header.
- **Failure response class:** any verification failure — missing/malformed header, query string present, unknown key ID, revoked or expired key, sender/audience mismatch, stale timestamp, nonce replay, body-hash mismatch, signature mismatch, or operation not on the sender's allow-list (§4) — produces the **same** generic response: HTTP `401`, JSON body `{"ok": false, "reason": "contract_auth_failed"}`. The specific cause is never returned to the caller; it may be recorded in the receiver's own internal audit/diagnostics only. This uniform failure class is what prevents an unauthenticated caller from learning whether a binding, conversation, operator, or key exists (§5).

**Discovery remains unauthenticated and safe.** The existing `GET /universal-support-chat/v1/channel-contract` handshake (`ContractDiscovery`) requires no signature and must additionally advertise:

- `contract_version`: `support-channel-contract/v1` (unchanged field, already present);
- `auth_profile`: `support-channel-contract-auth/v1` (new field);
- `channel_available` (unchanged field, already present);
- `operations`: the actually-supported operation list (unchanged field, already present — must reflect reality, not the full ADR-0005 catalog, once this ADR is implemented);
- a safe degraded/unavailable indicator that carries no internal detail (e.g. `channel_available: false` with no further reason exposed to an unauthenticated caller).

**Compatibility** between Support Chat and an adapter requires **all** of: exact `contract_version` match, exact `auth_profile` match, `channel_available: true`, and every operation the caller needs present in the advertised `operations` list. Any mismatch is "incompatible" per ADR-0006 and fails closed for that channel only.

### 4. Directional authorization

Two distinct, non-overlapping allow-lists, drawn verbatim from ADR-0005 §4–§5. A receiver verifies the caller's peer record before dispatch and rejects (uniform `401`, §3) any operation not on that specific sender's allow-list — a valid signature alone is necessary but not sufficient.

**Support Chat → adapter** (adapter verifies Support Chat's signature and allow-list membership):

- `ensure_channel_case`
- `notify_operators`
- `deliver_transcript_backfill`
- `deliver_message`

**Adapter → Support Chat** (Support Chat verifies the adapter's signature and allow-list membership):

- `ingest_operator_reply`
- `claim`
- `release`
- `resolve`
- `reopen`
- `update_assignment`
- `update_operator_presence`
- `report_channel_unavailable`
- `report_delivery_failure`

Before executing any domain mutation, the receiver verifies, in order: signature validity, declared sender identity matches the key that signed it, declared audience matches the receiver's own plugin identity, the key is `active` (not revoked/expired/unpaired/disabled), the requested operation is on that sender's allow-list, the timestamp is within the acceptance window, the nonce has not been replayed, and the body hash matches the received body. Only then does the call reach Support Chat's existing ownership, concurrency, idempotency, encryption, audit, and retention rules (ADR-0003, ADR-0004, ADR-0005 §6) for the domain mutation itself. Failure at any authentication step short-circuits before any domain mutation is attempted.

### 5. Failure and privacy rules

- Authentication or pairing failure fails closed for **that channel only** (ADR-0006); it never affects Support Chat's own Hub or website widget, and it never affects the adapter's non-Contract functions (e.g. Universal Telegram's non-chat notifications).
- Support Chat Hub and website chat remain fully available with the adapter absent, disabled, degraded, revoked, expired, or incompatible — identical to the failure model already fixed by ADR-0006, now with authentication failure added as an explicit trigger of the same "channel unavailable" state.
- The uniform failure response (§3) means an unauthenticated or improperly-signed caller cannot distinguish "no such binding", "no such conversation", "operator unknown", "key revoked", or "signature invalid" — all return the same generic denial.
- Contract v1's existing plaintext rule is preserved unchanged: plaintext exists only in memory for the duration of an authorized `deliver_*`/backfill call (ADR-0003, ADR-0005 §4); this ADR governs authenticating the call, not the eligibility or handling of the payload it carries.
- Authentication and replay-store records (peer metadata, nonce log, audit entries) never contain message bodies, notes, credentials, or any other secret/internal-classified field (ADR-0003 classification) — only the fields explicitly listed in §2 and §3.

### 6. Implementation sequencing

This ADR is a documentation freeze. Amending the [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) and superseding the [SC-M03 plan](../plans/sc-m03-controlled-migration-and-cutover-plan-v1.md) with [v2](../plans/sc-m03-controlled-migration-and-cutover-plan-v2.md) (this same freeze) fixes the following strict order for future implementation work, none of which is performed in this task:

1. **Support Chat** implements the authenticated Contract server: pairing endpoints/UI, the peer-key and nonce-replay stores, and signature verification enforcing this ADR, wired to the existing SC-M01/SC-M02 domain mutations for the "adapter → Support Chat" allow-list (§4).
2. **Universal Telegram** implements a signed Contract client replacing `SupportChatContractClient`'s current unconditional fail-closed stubs, and its own pairing/peer-key handling for the "Support Chat → adapter" allow-list — a follow-up slice of UT Adapter M1 (its own documentation amendment, pinning this ADR's commit SHA, is the task immediately after this one merges; it is not performed here).
3. **End-to-end authenticated interoperability tests** between the two, proving the signed calls in both directions, pairing, rotation, revocation, replay rejection, and the uniform-failure/fail-closed behaviour, before any migration code is written.
4. **Only then**, SC-M03's one-shot legacy migration engine and controlled cutover orchestration (unchanged in scope from the existing SC-M03 charter/ADR-0004; this ADR does not alter migration/cutover design, only unblocks the Contract-server prerequisite it depends on).
5. **Only after SC-M03 acceptance**, the Universal Telegram legacy Conversations tab, AI tab, widget, and chat settings decommission — a Cursor-led step in the Universal Telegram repository, out of scope here and for SC-M03 itself.

**SC-M03 implementation code may not begin until both (a) this ADR and (b) the corresponding Universal Telegram adapter-documentation amendment pinning it are merged to their respective `main` branches.**

## Alternatives

- **Shared HMAC secret** distributed to both plugins — rejected: a shared secret compromised on either side compromises both directions and cannot be attributed to a single sender; explicitly excluded by the UT Adapter M1 closure record.
- **WordPress application passwords** as the machine-authentication mechanism — rejected: scoped to a human user account, not a plugin-to-plugin identity; awkward revocation/rotation story; does not generalize to a genuinely remote adapter without exposing broader WordPress user capabilities than Contract v1 needs.
- **Bare `rest_do_request()` in the calling plugin's own current-user context** — rejected: not authentication of the *receiving* plugin's Contract boundary at all; explicitly ruled out by the UT Adapter M1 closure record.
- **mTLS between plugins** — rejected for this profile: both plugins run in-process on one WordPress install today (ADR-0002 non-goals: no companion server); requiring TLS client certificates for an in-process call is disproportionate, and mTLS configuration is a hosting/ops concern outside either plugin's control, unlike an application-level signature the plugins fully own.
- **JWT bearer tokens** — rejected: adds token issuance, expiry, and refresh machinery on top of what a simple per-request signature already achieves, without removing the need for a paired key in the first place; per-request signing also avoids a stolen-bearer-token replay window a JWT would otherwise have until expiry.
- **HMAC over a Diffie-Hellman-derived shared key** — rejected: adds key-agreement protocol complexity Ed25519 request signing does not need; asymmetric signing keeps each plugin's private key exclusively on the side that generated it, with no derived shared secret to protect.

## Consequences

- SC-M03's authenticated Contract server (§6 step 1) is now specified precisely enough to implement without inventing a mechanism during coding.
- Universal Telegram's `SupportChatContractClient` stub gains a concrete target profile to implement against in its own follow-up slice (§6 step 2); Universal Telegram documentation is not modified by this task.
- ADR-0005 is unchanged; this ADR fills in the authentication mechanism ADR-0005 §5 required but left unspecified, without editing ADR-0005's own immutable Decision text.
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`, the SC-M03 charter, and a new SC-M03 plan v2 are updated in this same freeze to reference this ADR and the amended sequencing.

## Security and privacy impact

- Removes the previously-open question of how Contract v1's "authenticated, capability-checked" requirement (ADR-0005 §1, §5) is actually enforced.
- Private key material never leaves the plugin that generated it; compromise of one plugin's stored peer public keys does not expose the other plugin's signing capability.
- Uniform failure responses and a fixed, auditable allow-list per direction close the "public REST bypass" and "generic capability alone" gaps the UT Adapter M1 closure record identified.
- Nonce replay protection and a bounded timestamp window bound the window during which a captured signed request could be replayed to effectively zero beyond the acceptance window.
- No new plaintext exposure: this ADR governs the authentication envelope around Contract calls, not the transcript-eligibility or in-memory-only rules ADR-0003/ADR-0005 already fix.

## Affected Documents/Milestones

- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (additive amendment)
- `docs/plans/sc-m03-controlled-migration-and-cutover-plan-v1.md` (superseded by `plan-v2.md`; v1 retained unedited per `docs/plans/README.md`)
- ADR-0002, ADR-0003, ADR-0005, ADR-0006 (referenced, unedited)
- Universal Telegram repository: UT Adapter M1 charter/closure and ADR-0037 (external; pinned by a future Universal Telegram documentation amendment, not performed in this task)

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, release, tag, or deployment change in this freeze.
- SC-M03 implementation (migration engine, cutover orchestration, and the Contract server itself) remains entirely unimplemented until the sequencing in §6 is followed.
