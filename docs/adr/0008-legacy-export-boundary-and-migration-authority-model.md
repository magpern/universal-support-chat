# ADR-0008 — Legacy Export Boundary and Migration Authority Model

## Status

Accepted

## Context

SC-M03's migration/cutover sequencing ([ADR-0007](0007-contract-v1-mutual-signed-adapter-authentication-profile.md) §6, [SC-M03 charter](../milestones/sc-m03-controlled-migration-and-cutover.md) §0) gates the one-shot legacy migration engine behind: Support Chat's authenticated Contract server (work package 0, shipped), Universal Telegram's signed Contract client (external follow-up, shipped), and a proven end-to-end interoperability gate (shipped — Support Chat PR #7 / Universal Telegram PR #35, both merged to their respective `main`).

Work packages 3 (batch migrator/backfill) and 4 (validators) — [plan v2](../plans/sc-m03-controlled-migration-and-cutover-plan-v2.md) §8 items 3–4 — cannot begin implementation under `docs/governance.md`'s freeze model until two things this ADR exists to fix are settled:

1. **Contract v1's operation allow-list is closed and does not cover bulk legacy-data export.** ADR-0007 §4 fixes exactly eight adapter → Support Chat operations and four Support Chat → adapter operations, none of them a bulk read of legacy conversations/messages/notes. Reusing or extending the Contract v1 REST surface for migration reads would either violate the closed allow-list or introduce a bulk-read capability into a channel designed for real-time, per-conversation, signed mutation calls — neither is acceptable. A distinct mechanism is required, and this ADR fixes it.
2. **No authority model exists for a WP-CLI-invoked, cross-plugin read.** Every existing Support Chat/Universal Telegram authenticated interaction (ADR-0007) assumes an HTTP request with a WordPress current-user or a signed remote peer. A WP-CLI process run by a server operator has neither by default. Without an explicit authority model, an implementer would either invent one ad hoc during coding (forbidden by `docs/governance.md`'s freeze model) or the migration engine would have no way to read legacy data at all.

Both plugins run in the same WordPress install today ([ADR-0002](0002-plugin-identity-and-ownership-boundaries.md) non-goals: no companion server), which makes an in-process interface possible without a network hop — but ADR-0002's plugin-ownership boundary and this repository's existing "no plugin reads or writes another plugin's database tables directly" rule (ADR-0007 §1) still apply in full; being in-process does not relax them.

This ADR also formally freezes the `QuiescenceStateProvider` contract that work packages 3–4's Phase B (final reconciliation and validation) depends on, so that its governance is fixed before any implementation exists to informally redefine it later.

## Decision

### 1. Ownership split

- **Universal Telegram owns all legacy-source reads and decryption.** Only Universal Telegram's own repository classes (its `MessageRepository`, `ConversationNoteRepository`, and equivalents, already the sole authorized readers of its own `CredentialVault`-encrypted columns) ever decrypt legacy `conversations`/`conversation_messages`/`conversation_notes` ciphertext. Support Chat never holds, derives, or is given Universal Telegram's vault key material.
- **Support Chat owns migration orchestration, target writes, and re-encryption.** Support Chat's migration engine (work packages 3–4) is the sole caller of the export boundary (§3), the sole writer of its own `conversations`/`conversation_messages`/`conversation_notes` rows, and re-encrypts every migrated body through its own `CredentialVault` before any write — consistent with [ADR-0003](0003-security-privacy-and-visitor-isolation.md)'s encryption posture and [ADR-0004](0004-migration-and-retention-principles.md)'s "re-encrypt into the Support Chat vault" principle.
- Neither plugin is given the other's vault key, encrypted secret, or raw key material at any point. This is the same "each plugin holds only its own private key material" posture ADR-0007 §1 already establishes for Contract v1 signing keys, applied here to vault encryption keys.

### 2. The boundary is a narrow, versioned, in-process PHP interface — never REST, never a Contract v1 operation

- Universal Telegram exposes a single new, explicitly versioned service, `LegacyExportServiceV1`, in its own codebase. It is a plain PHP class with a stable, documented method contract (§6) — not a WordPress REST route, not a hook fired for arbitrary listeners, and not an addition to Contract v1's operation allow-list (ADR-0007 §4, which remains closed and unmodified by this ADR).
- The interface is called **in-process**, within the same PHP request that is running Support Chat's migration WP-CLI command, because both plugins are already loaded in the same WordPress runtime (this repository's existing assumption, unchanged — see ADR-0002 non-goals). This is a same-process function call, not a network request; there is no HTTP round trip, no new listening port, and nothing for a remote caller to reach.
- **No public REST route is added by this boundary, in either plugin, under any circumstance.** A future adapter running as a genuinely remote peer (ADR-0007 §1's forward-compatibility note) is out of scope for this ADR; if legacy-data migration for a remote adapter is ever needed, that requires its own future ADR — this one governs only the same-process, same-install case that exists today.
- **No shared secret, application password, or bearer token is introduced.** Authority to call the boundary is established entirely by the authorization model in §5 (WP-CLI execution context plus an explicit capability check), never by a credential either plugin must keep synchronized with the other.

### 3. No plaintext logging or persistence outside transient transfer

- Plaintext produced by `LegacyExportServiceV1::export_batch()` exists only as PHP in-memory values for the duration of a single migration batch's processing within Support Chat's WP-CLI command.
- Plaintext is **never** written to: any WordPress debug log, any `error_log()`/`WP_CLI::debug()` call, any `legacy_migration_*` table column (Support Chat's migration metadata schema, work package 3's implementation, is restricted by design to counts, IDs, timestamps, and boolean validation results — never a content-derived value, per the [work packages 3–4 plan](../plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md)'s validation design), or any WP-CLI stdout/stderr output beyond aggregate counts.
- Support Chat re-encrypts each plaintext value through its own `CredentialVault` immediately upon receipt, within the same function call that received it from the export boundary — there is no intermediate buffering step, queue, temp file, or cache.
- This mirrors Contract v1's existing plaintext discipline (ADR-0003, ADR-0005 §4, ADR-0007 §5: "plaintext exists only in memory for the duration of an authorized call") applied to the migration boundary instead of a live delivery call.

### 4. Authority model for WP-CLI invocation

- `LegacyExportServiceV1` is invocable **only** from a WP-CLI request context (`defined( 'WP_CLI' ) && WP_CLI`), verified by the service itself, not merely by its caller's convention. Any invocation attempted outside a WP-CLI process — a web request, an Ajax handler, a REST callback, a cron job — is rejected unconditionally, regardless of capability or authentication state. This is a hard architectural gate, not a policy note: WP-CLI access to a WordPress install already requires operating-system-level shell access to the host running it, which is the trust boundary this ADR relies on (the same boundary `CLAUDE.md`'s existing WP-CLI convention already establishes for this VPS: `docker compose run --rm wpcli wp <args>`).
- Because WP-CLI by default runs without an authenticated WordPress current user, the boundary does **not** condition on a WordPress user capability (there is normally none to check). Instead, the operator must explicitly authorize the run by invoking Support Chat's migration command with `--assume-migration-authority` (an explicit, mandatory flag with no default), which the migration WP-CLI command validates before ever calling the export boundary. Omitting the flag fails the command closed before any Universal Telegram code is reached.
- **Fail-closed conditions**, each producing a distinct, operator-visible diagnostic (never a partial or silent skip):
  - Universal Telegram plugin inactive or its main class not loaded: `LegacyExportServiceV1` is unreachable (class does not exist); Support Chat's migration command must check `class_exists()` before invocation and fail closed with a clear "Universal Telegram is not active" message.
  - Universal Telegram active but running an incompatible version (schema below the version this boundary requires, e.g. missing `db_version` 32's tables): the service itself checks its own schema health (mirroring Universal Telegram's existing `SchemaHealth` pattern used by `WorkerRunner`) and refuses to export, returning a typed incompatibility reason rather than partial/malformed data.
  - Invoked outside WP-CLI context: unconditional rejection, §4 above.
  - `--assume-migration-authority` omitted: Support Chat's command fails closed before calling the boundary at all.
  - No other authorization path exists. There is no capability, filter, or configuration constant that bypasses any of the above.

### 5. Export shape, versioning, redaction, and batch limits

- **Versioned envelope.** Every export call is versioned independently of both plugins' own release versioning: `LegacyExportServiceV1::export_batch()` is a v1-suffixed method name, and its return shape carries an explicit `"export_schema_version": 1` field. A future incompatible change to the export shape ships as `export_batch_v2()` (a new method) rather than a breaking change to v1's contract — mirroring how ADR-0007 §3 versions its auth profile independently of the Contract operation-set version.
- **Method contract**: `export_batch( int $after_source_id, int $limit ): array`, `$limit` bounded server-side to a fixed maximum (100 conversations per call, enforced inside `LegacyExportServiceV1` regardless of what the caller requests) — this is a batch-size ceiling, not a caller-configurable trust decision, preventing a single call from holding an unbounded result set in memory.
- **Returned shape per conversation** (the fields this ADR requires to exist; the reviewed WP3–WP4 plan's field-mapping registry governs how Support Chat disposes of each one): the legacy numeric `id`, `conversation_uuid`, `bot_id`, `destination_id`, `status`, `assigned_operator_id`, `owner_user_id`, `topic_creation_state`, `telegram_topic_id`, `topic_lifecycle_state`, `start_idempotency_key`, `created_at`/`updated_at`/`resolved_at`/`expires_at`, `assignee_last_seen_message_id`, plus its full ordered list of messages (`id`, `message_uuid`, `direction`, decrypted body plaintext, `created_at`) and notes (`id`, `operator_user_id`, decrypted body plaintext, `created_at`). Fields this ADR does not list (e.g. `secret_hash`, `chat_profile`, `session_ref`, `consent_state`, `ai_participation_state`, `telegram_sender_user_id`, `outbound_message_uuid`, `telegram_message_id`) are **not included in the export shape at all** — redaction happens at the Universal Telegram source, not by Support Chat discarding fields it received. This is a stronger guarantee than a receiver-side filter: a field the export boundary never emits cannot be logged, cached, or migrated by mistake on the Support Chat side.
- **Error behavior**: a per-conversation read failure (decrypt failure, malformed row) is returned as a typed error entry within the batch result (`{"id": ..., "error": "decrypt_failed"}`), never as a thrown exception that aborts the whole batch — the caller (Support Chat's migration engine) is responsible for its own row-level fail-closed handling (its own map-table `failed` status), consistent with the reviewed plan's per-conversation atomicity design.
- **No permanent cross-plugin SQL access.** `LegacyExportServiceV1` is the only path Support Chat's migration engine ever uses to reach Universal Telegram's data — Support Chat's code never opens a `$wpdb` query against a `universal_telegram_*`-prefixed table, and no database user, view, or federated-table mechanism spanning both plugins' schemas is created by this ADR or may be created without a new ADR. This is the same rule ADR-0037 (Universal Telegram repository) already states for its side ("no cross-plugin direct DB access"), now made explicit and binding from Support Chat's side too.

### 6. `QuiescenceStateProvider` — frozen contract, default-deny, binding on WP2

Work packages 3–4's Phase B (final reconciliation and validation) requires a signal that Universal Telegram's legacy writers have stopped and drained (plan v2 §8 item 2, "quiescence switches/drains" — not yet implemented, a separate future work package). This ADR freezes the interface that signal must take, before either the signal's real implementation or work packages 3–4's consumption of it exist:

```php
interface QuiescenceStateProvider {
    public function is_quiescent(): bool;
    public function since(): ?DateTimeImmutable;
}
```

- Work packages 3–4 may implement **only**: (a) a default-deny stub (`is_quiescent()` unconditionally returns `false`) shipped as the real, production-registered implementation until a real provider exists, and (b) an injectable fake implementation used exclusively by automated tests to exercise Phase B's logic in isolation. Neither constitutes evidence that quiescence has ever actually occurred in production.
- **Phase B must call `is_quiescent()` as its sole precondition gate and must refuse to run — failing closed with a clear diagnostic — whenever it returns `false` or the provider is unset.** No configuration flag, filter, or code path may allow Phase B to proceed without a `true` result from an object satisfying this exact interface.
- **This contract is binding on work package 2's future real implementation.** Whatever mechanism work package 2 (quiescence switches/drains) eventually builds to detect and enforce quiescence, its provider **must implement this exact interface** (`is_quiescent(): bool`, `since(): ?DateTimeImmutable`) to be consumed by Phase B. No later milestone may redefine what "quiescent" means, add a bypass flag, weaken the boolean into something Phase B treats as advisory, or introduce an alternate path for Phase B to proceed without it. If work package 2's design later needs richer signal than a boolean (e.g. partial/per-subsystem quiescence), that is an explicit, reviewed **interface extension** proposed and accepted at that time — following this same ADR process — not a silent reinterpretation of the frozen v1 interface.
- Work packages 3–4's closure record **may not claim** real quiescence was ever achieved, that any conversation was validated as cutover-ready, that route switching occurred, that soak or rollback was exercised, or that any production migration was executed. Phase B proven only against the test seam in §6 above is the maximum claim work packages 3–4's closure is entitled to make.

## Alternatives

- **Add a bulk-export operation to Contract v1's allow-list** — rejected: ADR-0007 §4's allow-lists are fixed, closed, and drawn from ADR-0005; a bulk-read capability is a different security shape (an unbounded historical read) than the real-time, per-conversation, signed mutation calls Contract v1 governs, and mixing the two would weaken the auditability of the existing allow-list.
- **A new authenticated REST route dedicated to migration export** — rejected: introduces new public-network attack surface for what is fundamentally a same-host, operator-invoked, one-time administrative operation; the in-process call (§2) achieves the same result with strictly less exposed surface, given both plugins already run in the same WordPress install.
- **Direct cross-plugin `$wpdb` table access from Support Chat** — rejected outright: violates ADR-0002's plugin-ownership boundary and the "no plugin reads or writes another plugin's database tables directly" rule ADR-0007 §1 already establishes; also bypasses Universal Telegram's own vault-decryption authority (§1), which this ADR requires stay exclusively on Universal Telegram's side.
- **A shared migration secret/token authorizing the export call** — rejected: same reasoning ADR-0007 §1 already applied to Contract v1 signing (a shared secret compromised on either side compromises both, and cannot be attributed to a single caller); the WP-CLI-context-plus-explicit-flag model (§4) achieves authorization without introducing a value both plugins must keep confidential.
- **Letting `is_quiescent()` return richer state (an enum or object) from the outset** — rejected for now: a boolean-plus-timestamp is the minimum signal Phase B actually needs to gate on; richer signal can be added as a reviewed extension (§6) if work package 2's design later needs it, without over-specifying an interface no implementation yet exists to validate.

## Consequences

- Work packages 3–4 (Support Chat repository) can now be implemented against a fixed export contract instead of inventing a mechanism during coding.
- Universal Telegram's repository gains a new, narrow obligation: implement `LegacyExportServiceV1` per §2–§5 of this ADR, in its own follow-up documentation-and-implementation slice, pinned to this ADR's post-merge commit SHA (per `docs/governance.md`'s existing cross-repo pinning rule, already established for Universal Telegram's ADR-0007 pin).
- The `QuiescenceStateProvider` interface exists in Support Chat's codebase from work packages 3–4 onward as a fixed contract; work package 2 is scoped, when it begins, to supplying a real implementation of an interface that already exists, not to designing one from scratch.
- No Contract v1 operation, allow-list entry, or REST route is added; ADR-0007 is unmodified.

## Security and privacy impact

- Legacy vault key material never crosses the plugin boundary in either direction; Support Chat's migration engine can only ever obtain plaintext momentarily, through Universal Telegram's own authorized decryption path, never Universal Telegram's key.
- Redaction happens at the source (§5): fields not required for migration (e.g. `secret_hash`, `telegram_sender_user_id`, `consent_state`) are never emitted by the export boundary at all, rather than relying on the receiving side to discard them correctly.
- The WP-CLI-only, flag-gated authority model (§4) confines the entire new capability to operators who already have host shell access — no new capability is granted to any WordPress user role, and no new network-reachable endpoint is created.
- The `QuiescenceStateProvider` default-deny freeze (§6) makes it structurally impossible for work packages 3–4 to claim or accidentally trigger a real cutover-readiness state before work package 2 exists.

## Affected Documents/Milestones

- `docs/adr/README.md` (index and reserved-number table updated for ADR-0008).
- `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (additive amendment recording this ADR and the work-package-3–4 authorization; charter §Principles, interoperability matrix, and exclusions unchanged).
- `docs/decisions/sc-m03-wp3-wp4-legacy-migration-po-decisions.md` (new — the Product Owner decision record this ADR's field-mapping and ownership questions defer to).
- `docs/plans/sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md` (new — the implementation-ready plan for work packages 3–4, referencing this ADR).
- ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0007 (referenced, unedited).
- Universal Telegram repository: a future documentation amendment implementing `LegacyExportServiceV1` per §2–§5, pinned to this ADR's post-merge commit SHA and this file's canonical blob URL on `main` — not performed in this task, and a precondition for work packages 3–4's implementation to begin (see Compatibility/Migration Impact).

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, release, tag, or deployment change in this freeze — this ADR is documentation only.
- Work packages 3–4 implementation may not begin until **both** (a) this ADR and (b) the Universal Telegram documentation amendment pinning it (implementing `LegacyExportServiceV1`) are merged to their respective `main` branches — mirroring the identical two-repository gate ADR-0007 §6 already established for the Contract server/signed-client pair.
- This ADR does not authorize, schedule, or execute any production legacy-data migration. It authorizes only the future implementation of the migration *engine* (work packages 3–4), which per its own reviewed plan may not claim real cutover readiness in any case (§6).
