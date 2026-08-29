# Closure Records

Milestone and documentation-freeze closure records live in this directory.

- [Support Chat foundation documentation freeze](support-chat-foundation-documentation-freeze-closure.md)
- [SC-M00 Foundation](sc-m00-foundation-closure.md)
- [SC-M01 Conversation System of Record](sc-m01-conversation-system-of-record-closure.md)
- [SC-M02 Widget and WordPress Hub Replies](sc-m02-widget-and-hub-replies-closure.md)
- [SC Contract v1 Authentication Profile Documentation Freeze](sc-contract-v1-authentication-profile-documentation-freeze-closure.md)
- [SC-M03 Work Package 0: Authenticated Contract v1 Server](sc-m03-work-package-0-contract-server-closure.md)
- [SC-M03 Work Package 1: Outbound Contract v1 Client + Joint Interoperability Gate](sc-m03-work-package-1-interop-gate-closure.md)
- [SC-M03 Work Packages 3–4: Controlled Legacy Conversation Migration Engine](sc-m03-work-packages-3-4-legacy-migration-engine-closure.md)
- [SC-M03 Work Package 2: Real Quiescence Provider and Phase B Continuous Re-check](sc-m03-wp2-phase-b-recheck-implementation-closure.md)
- [SC-M03 Work Package 5: Legacy Binding Preparation](sc-m03-work-package-5-legacy-binding-preparation-closure.md)
- [SC-M03 Final Cutover](sc-m03-final-cutover-closure.md)
- [SC-M03 Final-Cutover Disposable DEV Rehearsal — Tier 1](sc-m03-final-cutover-dev-rehearsal-tier1-closure.md) — halted first attempt (finding F1); primary closure in the Universal Telegram repository
- [SC-M03 Final-Cutover Disposable DEV Rehearsal — Tier 1 re-attempt](sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md) — the single authorised re-attempt under runbook v2, executed and PASSED 2026-08-28 (both WP/PHP variants); primary closure in the Universal Telegram repository
- [SC-M03 Final-Cutover F1 `channel_case_ref` Identity-Correction Implementation](sc-m03-final-cutover-f1-identity-correction-implementation-closure.md) — comment corrections only (SC #26); primary closure in the Universal Telegram repository
- [Retirement of the obsolete SC-M03 legacy-migration / final-cutover engine](sc-m03-engine-retirement-closure.md) — ADR-0013; dead engine + WP-CLI + provenance path removed, historical schema/data and documents preserved, `db_version` unchanged
- [ADR-0014 — Interactive delivery class and fully asynchronous expedited dispatch](adr-0014-interactive-dispatch-closure.md) — merged (SC #39 `4bf012a`, UT #64 `9b4a6ef`); visitor/Hub requests only persist message + content-free outbox and request a non-blocking WP-Cron run, all Telegram work in the worker, `interactive_chat` queue priority; no schema/version change
- [ADR-0015 — Operator Settings page and Diagnostics separation](sc-adr-0015-operator-settings-diagnostics-implementation-closure.md) — merged to `main` `b56ea23` (SC [#44](https://github.com/magpern/universal-support-chat/pull/44); freeze `f978ea5`, PO acceptance `9a304cd`). Support Chat menu owns Conversations / Settings / Diagnostics; Settings exposes only the six existing option keys (visible warned uninstall-data setting) with unchanged sanitisation/defaults/schema; Diagnostics stays read-only with safe dispatch/pairing/outbox aggregates and an enforced redaction boundary; legacy `options-general.php?page=universal-support-chat` URL 302-redirects to Diagnostics. No schema/`db_version`/version/capability/dispatch change. **Merged, not deployed.**
