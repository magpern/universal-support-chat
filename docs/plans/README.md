# Implementation Plan Conventions

A definitive implementation plan is required before any milestone's implementation begins (`docs/governance.md`).

## Required structure

Each plan at `docs/plans/<id>-plan-vN.md` must contain:

1. Reference to the milestone charter and every ADR it relies on or introduces.
2. Repository findings at plan-drafting time.
3. Assumptions and open questions (separated from decisions).
4. Architectural decisions with alternatives/tradeoffs (cite ADRs).
5. Directory, namespace, schema, and API impact (scoped).
6. Security and privacy impact.
7. Test and CI impact.
8. Work packages in execution order.
9. Risks and mitigations.
10. Explicit out-of-scope list.
11. Definition of done matching the charter acceptance criteria.

## Freeze, revision, and supersession

- Plans are committed code-free with required ADRs, or after those ADRs already exist.
- Once committed, a plan is immutable. Changes require a new `vN+1` file that supersedes the prior plan.
- Implementation reports cite the plan-freeze commit SHA.

## Foundation freeze plans

This foundation documentation freeze includes boundary plans for the initial roadmap. SC-M00–SC-M04 plans are implementation-ready architecture freezes. SC-M05, SC-M06, SC-AI1, and SC-AI2 plans freeze product boundaries; they may require additional ADRs before coding.

| Plan | Milestone |
|---|---|
| [sc-m00-foundation-plan-v1.md](sc-m00-foundation-plan-v1.md) | SC-M00 |
| [sc-m01-conversation-system-of-record-plan-v1.md](sc-m01-conversation-system-of-record-plan-v1.md) | SC-M01 |
| [sc-m02-widget-and-hub-replies-plan-v1.md](sc-m02-widget-and-hub-replies-plan-v1.md) | SC-M02 |
| [ut-adapter-m1-dependency-plan-v1.md](ut-adapter-m1-dependency-plan-v1.md) | UT Adapter M1 (external) |
| ~~[sc-m03-controlled-migration-and-cutover-plan-v1.md](sc-m03-controlled-migration-and-cutover-plan-v1.md)~~ (superseded) | SC-M03 |
| [sc-m03-controlled-migration-and-cutover-plan-v2.md](sc-m03-controlled-migration-and-cutover-plan-v2.md) | SC-M03 (current; ADR-0007 sequencing) |
| [sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md](sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md) | SC-M03 work packages 3–4 detail (ADR-0008 authorization) |
| [sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md](sc-m03-wp5-existing-telegram-topic-binding-plan-v1.md) | SC-M03 work package 5 detail (ADR-0009 authorization) |
| [sc-m03-final-cutover-plan-v1.md](sc-m03-final-cutover-plan-v1.md) | SC-M03 final cutover detail (ADR-0010 authorization; documentation freeze only, no implementation) |
| [sc-m03-final-cutover-dev-rehearsal-plan-v1.md](sc-m03-final-cutover-dev-rehearsal-plan-v1.md) | SC-M03 final-cutover disposable DEV rehearsal — Support Chat companion, **superseded by v2** (retained unedited as the historical record of the halted first attempt; Tier 1 attempted 2026-08-27 → halted by finding F1 — closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`) |
| [sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md](sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md) | F1 remediation — Support Chat companion (ADR-0011 **Accepted** 2026-08-27; comment corrections only, no schema / `db_version` change; primary plan in Universal Telegram. **F1 implementation and closure MERGED** — SC #26 `9144cb1` / closure #27 `5d81b5b`; UT #53 `7d4cc4f` / closure #54 `32f17ea`; real dual-plugin interop green on both WP/PHP variants post-merge) |
| [sc-m03-final-cutover-dev-rehearsal-plan-v2.md](sc-m03-final-cutover-dev-rehearsal-plan-v2.md) | SC-M03 final-cutover disposable DEV rehearsal — Support Chat companion **v2** (current; supersedes v1; primary runbook in Universal Telegram). Pins the immutable Product-Owner-approved Tier 1 execution baselines UT `6eed0228286e84b4e56e0119f242b483f138a58e` / SC `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (operators fetch origin, verify these exact commits exist, and check them out before execution; runtime trees byte-identical to the F1 implementation commits `7d4cc4f` / `9144cb1`, documentation only added since; future documentation merges must not alter the baseline without a new PO approval); revises only the F1-invalidated portions of v1 (wire identity = the Support Chat conversation UUID; Run 1 handoff fixture/assertions with real distinct `binding_uuid`; exhaustive fail-closed classifier `unresolved_case_reference` / `handoff_rejected` referenced by Runs 2/3; new Run 1 F1-correction gate; new Run 3 fail-closed incident scenarios); all v1 safety boundaries, evidence/redaction/teardown requirements, the Tier 1/Tier 2 distinction, and blockers B1–B5 carried forward. The Approval A addendum is **RECORDED / Product Owner accepted 2026-08-28** (decision record Addendum C); the single authorised Tier 1 re-attempt was **executed 2026-08-28 and PASSED** on both WP/PHP variants (Addendum D; closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`) and the one-time authorisation is consumed; Tier 2 blocked on B1/B2 and pending Approval B) |
| [sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md](sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md) | SC-M03 Tier 2 disposable DEV rehearsal **prerequisites** — Support Chat companion (planning-only, FROZEN; primary in Universal Telegram). Specifies B1 (isolated full-WordPress instance sharing nothing with `dev.biopentra.eu` — own compose project/DB/Redis/vhost/TLS/`CredentialVault` key; B1 verification gate) and B2 (dedicated non-production Telegram bot/supergroup/topics; token encrypted only in the rehearsal DB; full webhook + revocation lifecycle; B2 verification gate), the Tier 2 operator sequence (real WP-Cron/Action Scheduler, Redis, authenticated webhook ingress, real topic-lifecycle messages, real `sendMessage`, real `409 quiescence_active`), and a **proposed, unsigned Approval B** authorizing exactly one Tier 2 rehearsal after B1/B2 are provisioned and independently verified. Provisions nothing; creates no Telegram resource; records no Approval B; executes no Tier 2) || [sc-m04-telegram-optional-acceptance-plan-v1.md](sc-m04-telegram-optional-acceptance-plan-v1.md) | SC-M04 |
| [sc-m05-professional-widget-experience-plan-v1.md](sc-m05-professional-widget-experience-plan-v1.md) | SC-M05 |
| [sc-m06-support-availability-and-offline-tickets-plan-v1.md](sc-m06-support-availability-and-offline-tickets-plan-v1.md) | SC-M06 |
| [sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md](sc-ai1-operator-ai-drafts-approve-and-send-plan-v1.md) | SC-AI1 |
| [sc-ai2-controlled-direct-ai-responses-plan-v1.md](sc-ai2-controlled-direct-ai-responses-plan-v1.md) | SC-AI2 |

## Post-freeze feature plans

| Plan | Scope |
|---|---|
| [sc-telegram-adapter-dispatch-plan-v1.md](sc-telegram-adapter-dispatch-plan-v1.md) | ADR-0012 — automatic Support Chat → Telegram message dispatch (SC-owned outbox); realises the SC-owned-delivery half of Universal Telegram ADR-0044 |
| [sc-interactive-telegram-dispatch-plan-v1.md](sc-interactive-telegram-dispatch-plan-v1.md) | ADR-0014 — fixed `interactive_chat` delivery class on ADR-0012 mirror sends, a compatible `deliver_message` extension, and one bounded immediate dispatch attempt after the atomic outbox commit; counterpart to Universal Telegram ADR-0045. **Superseded by v2** (retained unchanged) — its in-request immediate attempt could synchronously call `createForumTopic` for a new conversation. |
| [sc-interactive-telegram-dispatch-plan-v2.md](sc-interactive-telegram-dispatch-plan-v2.md) | ADR-0014 **+ Amendment 1** — the corrected **fully asynchronous** expedited dispatch: the visitor/Hub request only commits the message + outbox row and fires a non-blocking `spawn_cron()` kick; **all** Telegram I/O (topic creation, notify, delivery) happens in the async worker; `deliver_message` still carries `delivery_class = interactive_chat` for Universal Telegram queue priority (ADR-0045). |
| [sc-operator-settings-and-diagnostics-plan-v1.md](sc-operator-settings-and-diagnostics-plan-v1.md) | ADR-0015 — a real operator-facing **Support Chat Settings** page (WordPress Settings API, existing option group + `MANAGE` capability, exposing only the six existing option keys incl. a visible "Data removal" setting), the existing read-only status table reparented + renamed to a separate **Diagnostics** page (safe Telegram pairing/dispatch/outbox aggregates only), all three screens as submenus of the existing top-level `Support Chat` menu, and a capability-checked `302` compat redirect for the legacy `options-general.php?page=universal-support-chat` URL. No new option/schema/default/capability/uninstall/delivery change. |
