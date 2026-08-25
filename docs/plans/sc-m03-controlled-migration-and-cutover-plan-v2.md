# SC-M03 Controlled Migration and Cutover — Implementation Plan v2

Supersedes [sc-m03-controlled-migration-and-cutover-plan-v1.md](sc-m03-controlled-migration-and-cutover-plan-v1.md) (`docs/plans/README.md`: v1 is retained, unedited, permanently; this file is the frozen plan going forward).

## 1. References

- Charter: `docs/milestones/sc-m03-controlled-migration-and-cutover.md` (§0 Sequencing amendment)
- ADRs: ADR-0003, ADR-0004, ADR-0005, ADR-0006, **ADR-0007** (new — Contract v1 mutual signed adapter authentication profile)

## 2. Findings

Legacy chat SoR resides in Universal Telegram until cutover. UT Adapter M1 shipped with adapter persistence, discovery, and fail-closed wiring complete, but every adapter → Support Chat Contract call deliberately stubbed to fail closed, because Contract v1 (ADR-0005) requires authenticated, capability-checked calls without specifying a mechanism. That gap blocked SC-M03 implementation under `docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on"). ADR-0007 (this same documentation freeze) fixes the mechanism: mutual Ed25519 request signing, administrator-authorized pairing, no shared secret, no bare `rest_do_request()` context, no public mutation bypass.

## 3. Assumptions

- Maintenance window available for quiescence (unchanged from v1).
- AI tables remain in UT (historical); not migrated here (unchanged from v1).
- Universal Telegram implements its own signed-client follow-up slice against ADR-0007 as a separate, coordinated piece of work in the Universal Telegram repository; this plan does not implement or specify Universal Telegram-side code.
- The authenticated Contract server (work package 0 below) is now in scope for SC-M03 implementation; it was previously assumed pre-existing (an assumption v1 did not actually record, and which the UT Adapter M1 closure record subsequently proved false).

## 4. Decisions (locked)

- Follow ADR-0004 exactly for migration/cutover: no dual-write; quiesce five mutation classes; resumable re-encrypt copy; mapping + counts + content hashes; create bindings for existing topics; atomic route switch; soak read-only legacy; rollback = route reverse only. (Unchanged from v1.)
- Follow ADR-0007 exactly for the Contract server: mutual Ed25519 request signing, administrator-authorized pairing with confirm-before-replace, per-request nonce replay protection, directional operation allow-lists, uniform fail-closed denial response. (New in v2.)
- Work is strictly sequenced: authenticated Contract server → Universal Telegram signed client (external repo) → authenticated interoperability tests → migration engine → cutover orchestration. No migration or cutover code is written before the Contract server and interoperability tests pass.

## 5. Impact

- **New:** Support Chat-owned Contract server implementing ADR-0007: peer-key store, nonce-replay store, pairing admin UI/endpoints, signature verification middleware in front of the existing `ChannelContract` REST surface, wired to the "adapter → Support Chat" operation allow-list against SC-M01/SC-M02 domain mutations.
- Migration WP-CLI (or admin-protected) tool in Support Chat and/or coordinated UT read-only exporter — no cross-table writes; export/import via explicit tools. (Unchanged from v1.)
- Routing flags/options switching SoR ownership. (Unchanged from v1.)
- Runbook documentation in-repo. (Unchanged from v1.)
- No Universal Telegram repository changes in this plan; the signed client is Universal Telegram's own follow-up slice, out of scope here.

## 6. Security and privacy

- Plaintext only in memory during re-encrypt; destroy buffers; validate no cross-visitor mapping. (Unchanged from v1.)
- Private signing keys never leave the plugin that generated them, encrypted in that plugin's own vault (ADR-0007 §1); peer public keys and pairing metadata are not secret but must never appear alongside vault material.
- Nonce replay store and pairing audit records must never contain message bodies, notes, or credentials (ADR-0007 §5).
- Authentication failure responses are uniform and leak no internal detail (ADR-0007 §3), preserving the existing "no binding/conversation/operator existence leak to an unauthenticated caller" property this milestone's Contract server must not weaken.

## 7. Test and CI

- Full interoperability matrix from the charter (unchanged scope from v1).
- T9-style quiescence integrity; resume-after-interrupt test (unchanged from v1).
- **New:** Contract-server authentication test matrix — valid signed call succeeds; unsigned/forged/replayed/expired/wrong-audience/wrong-operation/revoked-key/unpaired-key calls are rejected with the uniform denial and no domain mutation occurs; pairing idempotency and confirm-before-replace; rotation invalidates the old key id; revocation takes effect immediately.
- End-to-end authenticated interoperability tests against Universal Telegram's signed client are a **prerequisite gate** for work packages 3+ below, not a parallel activity.

## 8. Work packages

0. **Authenticated Contract server** (ADR-0007): peer-key store; nonce-replay store; pairing admin UI/endpoints (generate key, pair, confirm-replace, revoke, rotate); signature-verification middleware; wiring to the "adapter → Support Chat" allow-list domain mutations. *Gate: must be complete and tested before any work package below begins.*
1. **External gate:** Universal Telegram signed Contract client (Universal Telegram repository; not implemented by this plan) and coordinated end-to-end authenticated interoperability proof between the two plugins. *Gate: must pass before work package 2 begins.*
2. Quiescence switches/drains (unchanged from v1 WP1).
3. Batch migrator + checkpoints (unchanged from v1 WP2).
4. Validators (counts/hashes/mapping) (unchanged from v1 WP3).
5. Binding creator (calls adapter APIs / writes adapter-owned bindings via UT tool, now over the authenticated Contract server from work package 0) (was v1 WP4).
6. Atomic route switch (unchanged from v1 WP5).
7. Soak/rollback runbook (unchanged from v1 WP6).
8. Matrix automation/checklist (unchanged from v1 WP7).

## 9. Risks

- Migrating before the Contract server exists and is proven end-to-end — blocked by work packages 0–1 gating everything after them (new risk this plan closes; v1 did not record it because v1 assumed the mechanism already existed).
- Migrating before the adapter (UT Adapter M1) — blocked by dependency (unchanged from v1).
- Partial cutover — forbidden; switch is atomic (unchanged from v1).
- Authentication mechanism scope creep during implementation (inventing behavior ADR-0007 does not specify) — mitigated by treating ADR-0007 as the sole source of the wire profile; any gap found during implementation returns to architecture review for an ADR amendment, not an ad hoc code decision (`docs/governance.md` freeze model).

## 10. Out of scope

AI migration; destructive legacy deletes; dual-write; implementing UT Adapter M1 itself; implementing Universal Telegram's signed Contract client (Universal Telegram repository, coordinated separately); SC-M05/SC-M06/SC-AI1/SC-AI2 scope. (Unchanged from v1, with the Universal Telegram signed-client boundary made explicit.)

## 11. Definition of done

Charter matrix green (unchanged from v1); the ADR-0007 Contract server and Universal Telegram's signed client interoperate end-to-end with automated proof (new gate before migration/cutover work is considered done); PO accepts cutover runbook; soak begun or completed per PO.
