# Architecture Reference — Universal Support Chat

This document records product boundaries, contracts, and versioning conventions for Universal Support Chat.

## Product identity

See [ADR-0002](adr/0002-plugin-identity-and-ownership-boundaries.md).

- Display name: **Universal Support Chat**
- Slug / text domain: `universal-support-chat`
- PHP namespace: `UniversalSupportChat\`
- Composer package: `magpern/universal-support-chat`
- Standalone WordPress plugin; **must work fully without Universal Telegram**

## Product boundaries

| Boundary | Namespace | Status at SC-M00 |
|---|---|---|
| Core | `UniversalSupportChat\Core` | Implemented (composition root, configuration, lifecycle, capabilities, vault) |
| Persistence | `UniversalSupportChat\Persistence` | Implemented (migrator, lock, schema health; `db_version` target 1) |
| Privacy | `UniversalSupportChat\Privacy` | Implemented (classification, redactor) |
| Audit | `UniversalSupportChat\Audit` | Implemented (audit logger + repository; audit log table) |
| Administration | `UniversalSupportChat\Administration` | Implemented ([ADR-0015](adr/0015-operator-settings-page-and-diagnostics-separation.md)): the top-level `Support Chat` menu owns three submenus — **Conversations** (Hub inbox/detail/reply/notes), **Settings** (`SupportChatSettingsPage`; Settings API over the existing option group, `MANAGE`-gated, the nine current keys — the six operational keys plus the three SC-M05 [ADR-0016](adr/0016-support-chat-widget-presentation-settings.md) widget-presentation keys `widget_title` / `widget_greeting` / `widget_avatar_attachment_id`), and **Diagnostics** (`DiagnosticsPage`; read-only version/schema/vault/audit plus safe Telegram dispatch/pairing/outbox aggregates). `Administration\Compat\LegacySettingsRedirect` 302-redirects the retired `options-general.php?page=universal-support-chat` URL to Diagnostics for `MANAGE` users. No new top-level menu, option, schema, or capability. |
| Conversations | `UniversalSupportChat\Conversations` | Implemented (SC-M01: SoR, visitor REST, retention). SC-M06 ([ADR-0017](adr/0017-support-availability-authority-and-honest-offline-behaviour.md)) adds one transition-map edge (`new → waiting_for_operator`) and an `availability` field on the existing visitor conversation/message responses — no new route, schema, or `db_version` change. |
| ChatWidget | `UniversalSupportChat\ChatWidget` | Implemented (SC-M02 minimal visitor widget; SC-M05 [ADR-0016](adr/0016-support-chat-widget-presentation-settings.md): operator-configurable `widget_title` / `widget_greeting` / `widget_avatar_attachment_id` on the ADR-0015 Settings page via `WidgetPresentation`, professional circular launcher + CSS icon morph, desktop panel / mobile sheet / RTL / `prefers-reduced-motion`, non-modal `role="dialog"` with no `aria-modal` and no Tab trap. No REST/schema/`db_version`/capability change) |
| Availability | `UniversalSupportChat\Availability` | Authorized for SC-M06 ([ADR-0017](adr/0017-support-availability-authority-and-honest-offline-behaviour.md), **Proposed** in the freeze; companion plan `docs/plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md`). Support Chat is the sole authority for the weekly support schedule, date exceptions, the manual `Automatic / Force online / Force offline` override, the resolved visitor-facing state, and offline-ticket handling. Evaluated in the WordPress site timezone (`wp_timezone()`); precedence `manual override → date exception → weekly schedule → fail-safe unavailable`. The widget never shows an untrue "online" or response-time claim. An offline ticket is an existing authenticated visitor conversation/message submitted while the server resolves `unavailable`, committed in one transaction (message + existing content-free ADR-0012 outbox row when dispatch is enabled + transition to `waiting_for_operator`). Schedule / exceptions / offline-copy are additive keys on the existing `universal_support_chat_settings` option; the override is a separate autoloaded option set only through a nonce + `MANAGE` action. No new REST route, capability, schema, or `db_version` change; no Telegram mechanism; no AI. |
| AI | — | Not authorized until SC-AI1. No AI/LLM/RAG/embeddings/vector store/content ingestion/retrieval/automated-answer capability exists today. A future AI-assisted support / RAG knowledge base is deferred to [SC-AI3](milestones/sc-ai3-ai-assisted-support-and-rag.md) (own ADR + plan + PO approval required). |
| ChannelContract | `UniversalSupportChat\ChannelContract` | SC-M03 work package 0: authenticated Contract v1 server (ADR-0007) — peer key store, nonce replay store, signature verification, pairing admin UI, truthful discovery. No adapter is paired by default; no migration/cutover code. |

Channel adapters (e.g. Universal Telegram) are **external plugins**, not boundaries inside this repository. A structural unit test forbids premature `src/` directories for unauthorized boundaries.

## Canonical Contract v1

[ADR-0005 — Canonical Support Channel Contract v1](adr/0005-canonical-support-channel-contract-v1.md) is the sole canonical cross-plugin contract.

Consumers must pin:

1. The immutable git commit SHA on `main` that contains the accepted ADR-0005 text used for implementation, and
2. The canonical document URL in this repository.

Do not maintain a second full copy of Contract v1 in another repository.

## Optional channel failure model

[ADR-0006](adr/0006-optional-channel-and-adapter-failure-model.md): fail closed for the channel only; Hub and website chat continue.

## Contract v1 authentication

[ADR-0007](adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md): mutual Ed25519 request signing between Support Chat and each adapter — separate key pairs, administrator-authorized pairing, no shared secret, no bare `rest_do_request()` context, no public mutation bypass. Fixes the mechanism ADR-0005 §5 requires but leaves unspecified. SC-M03's authenticated Contract server and Universal Telegram's signed Contract client both implement against this ADR; neither existed before it.

## Migration

[ADR-0004](adr/0004-migration-and-retention-principles.md): no dual-write; quiesced one-shot cutover; UT Adapter M1 before SC-M03. SC-M03 implementation additionally requires ADR-0007's authenticated Contract server before the migration/cutover engine itself — see the [SC-M03 charter](milestones/sc-m03-controlled-migration-and-cutover.md) sequencing amendment.

## Security and privacy

[ADR-0003](adr/0003-security-privacy-and-visitor-isolation.md).

## Execution sequence (locked)

1. SC-M00 → SC-M01 → SC-M02
2. **UT Adapter M1** (Universal Telegram repository; after Contract v1 exists)
2a. **ADR-0007 authenticated Contract server** (Support Chat) and its Universal Telegram signed-client follow-up slice — required before SC-M03 code (see [ADR-0007](adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md))
3. SC-M03 (migration/cutover)
4. SC-M04 (telegram-optional acceptance)
5. SC-M05, SC-M06, **SC-AI1**, then **SC-AI2**

SC-AI1 precedes SC-AI2. **SC-AI3** (AI-assisted support / RAG knowledge base) is a deferred future note only — unscheduled, unauthorized, needs its own ADR, plan, and PO approval.

## Versioning conventions

- Plugin SemVer: `UNIVERSAL_SUPPORT_CHAT_VERSION` — **`0.3.0`** at SC-M03 work package 0 (`0.2.0` at SC-M02, `0.1.0` at SC-M01); `0.7.0` at SC-M05 (ADR-0016 widget presentation); `0.8.0` at SC-M06 (ADR-0017 availability & offline tickets — asset cache-bust; no release or tag).
- Independent integer schema version option `universal_support_chat_db_version` — target **`7`** at SC-M03 work package 0 (1=audit, 2=conversations, 3=messages, 4=notes, 5=channel peers, 6=contract nonces, 7=channel status).
- No Contract v1 release tag is required for adapter pinning; commit SHA is sufficient.
- SC-M03 work package 0 does not create a GitHub Release or version tag.

## Where to look

- Governance: `docs/governance.md`
- Master plan / roadmap: `docs/master-plan.md`
- Milestones: `docs/milestones/`
- ADRs: `docs/adr/`
- Plans: `docs/plans/`
- Testing: `docs/testing/`
- Closure: `docs/closure/`
