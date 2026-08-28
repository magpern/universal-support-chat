# SC-M03 Final-Cutover — Tier 2 Disposable DEV Rehearsal Prerequisites Plan v1 (Support Chat companion)

**Status: planning-only. FROZEN. This document authorizes nothing.** It provisions no
infrastructure, creates no Telegram resources, executes no Tier 2 rehearsal, and does not record
Approval B. It changes no code, schema, `universal_support_chat_db_version` (11), plugin version,
configuration, test, or workflow. It does not alter the consumed Tier 1 Approval A addendum, the
executed Tier 1 re-attempt closure, or the immutable Tier 1 execution baseline SHAs.

The **primary** Tier 2 prerequisites plan — the full B1 topology, the B2 Telegram procedure, the
Tier 2 operator sequence, the evidence/hard-stop/teardown rules, and the proposed unsigned
Approval B text — is in Universal Telegram:
`https://github.com/magpern/universal-telegram/blob/main/docs/plans/sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md`.
This companion records the Support Chat–side detail and confirms the cross-references.

## 1. Baselines

| Item | Value |
|---|---|
| Authored against `origin/main` | universal-support-chat `cc0c879f31bcdee20b7695c599e113449e12480b`, universal-telegram `ea06520fdc8998dd2c25b0b5cdd09534c2ded3aa` |
| Immutable Tier 2 execution baselines | identical to the immutable Tier 1 baselines: universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a`, universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` |
| Operative runbook | [`sc-m03-final-cutover-dev-rehearsal-plan-v2.md`](sc-m03-final-cutover-dev-rehearsal-plan-v2.md) (companion; primary in Universal Telegram) — its §5/§8/§9 apply to Tier 2 verbatim |
| Tier 1 status | **COMPLETE — executed 2026-08-28, PASSED** ([Tier 1 re-attempt closure](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md); decision record Addendum D). Approval A addendum consumed. |
| Remaining blockers to Tier 2 | **B1**, **B2** (runbook v2 §6). This plan designs their resolution and verification; it resolves nothing by building. |

## 2. Support Chat–side detail for B1 (isolated instance)

In the isolated rehearsal instance (`scm03rehearsal` Compose project — see the primary plan §2):

- Universal Support Chat runs from a **fresh throwaway checkout** at
  `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (detached HEAD, clean tree), bind-mounted read-only
  alongside the Universal Telegram checkout at `6eed0228286e84b4e56e0119f242b483f138a58e`. No
  other Biopentra plugin is present.
- Support Chat's schema migrates lazily on `plugins_loaded`; the B1 verification gate asserts
  `get_option('universal_support_chat_db_version')` == `11` after install, in a database
  (`rehearsal`) that shares nothing with `wordpress-db`.
- Support Chat's `CredentialVault` uses the rehearsal-specific key (distinct from
  `dev.biopentra.eu`); it cannot decrypt any `dev.biopentra.eu` Support Chat ciphertext.
- Contract v1 pairing is performed through the two plugins' real WordPress-admin pairing actions
  in the isolated instance (nonces `usc_contract_pairing` / `universal_telegram_support_chat_pairing`),
  requiring both plugins' MANAGE capabilities; discovery must report `channel_available:true`
  with the six cutover ops on the peer allow-list. Transport stays `InProcessContractTransport`
  (`rest_do_request()`) — no HTTP hop, no new port.

## 3. Support Chat CLI paths exercised for the first time under Tier 2

Tier 1 drove these as service calls; Tier 2 runs them as **real WP-CLI** inside the isolated
instance, with real Action Scheduler batch draining through the rehearsal cron:

- `wp universal-support-chat legacy-migrate run --phase=backfill --assume-migration-authority`
  and `--phase=reconcile` — Phase A / Phase B under a **real** continuous-quiescence provider
  (`UniversalTelegramQuiescenceStateProvider` delegating in-process to a real quiescent UT), with
  the real `PhaseBReconciliationService` re-checking `is_quiescent()` each iteration.
- `wp universal-support-chat legacy-migrate validate` / `status` — the migration-evidence gate
  asserted before `cutover begin` (B4 / A4).
- `wp universal-support-chat legacy-bind run --assume-binding-authority` — real
  `LegacyBindingImportServiceV1` under a real quiescence lock and real live re-check, producing a
  real `prepared` binding with an independent `binding_uuid`.
- Real Contract v1 server handling during `replay-deferred-updates`: real
  `ContractOperationDispatcher::dispatch_with_provenance()` writing one domain effect + one
  `legacy_handoff_map` row per handed-off update, keyed by the Support Chat conversation UUID;
  real `409 handoff_provenance_conflict` on a mismatched pre-seeded map row; real `404` →
  `unresolved_case_reference` and deterministic `400`/`409` → `handoff_rejected` (Run 3).
- `Migrator::verify_step_11` forbidden-column guard, run as part of the no-leak audit.

## 4. Evidence, redaction, hard stop, incident, retry, rollback, teardown

Runbook v2 §5/§8/§9 and the **primary plan §5** apply verbatim. Support Chat–specific evidence:
`SHOW COLUMNS` + filtered `SELECT *` on `universal_support_chat_legacy_handoff_map`,
`conversation_messages` (body column proven ciphertext), and the channel-status table;
`verify_step_11` result; `universal_support_chat_db_version` before/after (unchanged, 11).
Never retain any `body_ciphertext`, message text, `CredentialVault` key material, bot token, or
webhook secret. "Rollback" for Support Chat is teardown of the isolated instance only — the
migration map and handoff records in `dev.biopentra.eu` are never touched; production and DEV
remain forward-only.

## 5. Proposed Approval B text

**Identical to the primary plan §6** — reproduced there in full, PROPOSED and unsigned. It
authorizes exactly one Tier 2 rehearsal, at the immutable execution baselines, only after B1 and
B2 are provisioned **and independently verified**, and only against the isolated instance and the
dedicated bot. It is not signed and not recorded by this plan.

## 6. Four-phase separation

Per the primary plan §7: (1) documentation/planning — **this task**; (2) prerequisite
provisioning — a separate later task, not authorised here; (3) Approval B recording — the Product
Owner, not done here; (4) one-time Tier 2 execution — blocked on phases 2 and 3. This plan is
phase 1 only.

## 7. Explicit non-authorizations

This document authorizes nothing: not provisioning B1, not creating any Telegram resource, not
recording Approval B, not executing Tier 2. No action against `dev.biopentra.eu`,
`/opt/biopentra/dev/*`, `/opt/biopentra/data/*`, the DEV/production WordPress, database, Redis,
SWAG, bots, webhooks, or conversations; no DNS/TLS/UFW/SSH change; no production or DEV
quiescence, migration, binding preparation, cohort activation, route switch, cutover, soak,
deployment, release, tag, rollback, deletion, or retention change; no second Tier 1 attempt and
no change to the immutable Tier 1 execution baseline SHAs. Separate Product Owner authorization
(Approval B), preceded by independently verified B1/B2 provisioning, is required before any
Tier 2 activity.
