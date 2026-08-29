# Master Plan — Universal Support Chat

> Governance: `docs/governance.md`. Milestones: `docs/milestones/README.md`. Architecture: `docs/ARCHITECTURE.md`.
>
> Identity: display name **Universal Support Chat**; slug `universal-support-chat` — [ADR-0002](adr/0002-plugin-identity-and-ownership-boundaries.md).
>
> Self-contained deployment: support chat runs within WordPress. No companion bot server or vendor-hosted SaaS backend is required. Optional channel adapters may call external channel APIs asynchronously.

## 1. Product vision

Build a standalone WordPress plugin that provides professional website support chat:

1. Visitor widget and durable conversations in WordPress as system of record.
2. WordPress Hub inbox with first-class operator replies (no channel required).
3. Tickets, waiting queue, assignment, notes, and audit.
4. Support hours and live availability.
5. Future controlled chat AI (human-approved drafts first; then direct AI).
6. Optional channel adapters (Universal Telegram first) for escalated operator workflows only.

## 2. Original product requirements (acceptance criteria)

These **R1–R7** requirements are mandatory acceptance criteria for the roadmap. Every relevant milestone charter maps to one or more of them.

### R1 — Telegram routing

Support Chat works without Telegram. Telegram is an optional adapter and receives **only** escalated/support-channel traffic — **never** ordinary AI-only chat.

### R2 — Professional launcher

Circular launcher; chat icon when closed; X when open; subtle morph animation; `prefers-reduced-motion` support.

### R3 — Professional greeting

Configurable opening Hello/greeting, title/avatar, and professional chat presentation.

### R4 — AI from the start

Future AI is enabled by administrator **site policy** and visitor **disclosure**, not a visitor checkbox.

### R5 — Support hours and live status

Support Chat owns schedule, exceptions, manual `Automatic / Online / Offline`, waiting queue, and Hub administration. Telegram `/support` is only an optional adapter capability when the adapter is active.

### R6 — AI first-line support

Future AI answers routine questions before escalating according to controlled policy.

### R7 — Offline human support

A human request **always** creates a durable Support Chat ticket with truthful offline wording. Telegram may notify if connected, but a **ticket never depends on Telegram**.

## 3. Product principles

- **Standalone first** — Hub and widget never require an adapter.
- **WordPress is SoR** — conversations and tickets live in Support Chat tables.
- **Optional channels** — Contract v1 ([ADR-0005](adr/0005-canonical-support-channel-contract-v1.md)); fail closed per channel ([ADR-0006](adr/0006-optional-channel-and-adapter-failure-model.md)).
- **Privacy by default** — [ADR-0003](adr/0003-security-privacy-and-visitor-isolation.md).
- **Human control before autonomy** — SC-AI1 before SC-AI2.
- **No dual-write migration** — [ADR-0004](adr/0004-migration-and-retention-principles.md).
- **No companion server**.
- **Mutually authenticated Contract calls** — [ADR-0007](adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md): mutual Ed25519 request signing between Support Chat and each adapter; no shared secret, no bare user-context call, no public mutation bypass.

## 4. Relationship to Universal Telegram

- Legacy chat currently exists inside Universal Telegram. This repository does **not** claim that code is already extracted.
- Next documentation step (separate task): Universal Telegram supersession + Support Chat Adapter M1 charter, pinned to this repository’s Contract v1 **commit SHA** and canonical URL.
- Runtime: UT Adapter M1 implements the adapter after Contract v1 exists; then SC-M03 migrates data.

## 5. Roadmap (planned only — no runtime code in the foundation freeze)

| Order | Milestone | Summary | Requirements |
|---|---|---|---|
| 1 | [SC-M00](milestones/sc-m00-foundation.md) | Plugin shell, governance already started here, capabilities, vault/migration approach, test foundations | — |
| 2 | [SC-M01](milestones/sc-m01-conversation-system-of-record.md) | Conversations SoR, retention, visitor REST; no Telegram; no AI | R1 baseline |
| 3 | [SC-M02](milestones/sc-m02-widget-and-hub-replies.md) | Widget baseline, Hub inbox, first-class Hub reply; no Telegram; no AI | R5 Hub path |
| 4 | [UT Adapter M1](milestones/ut-adapter-m1-universal-telegram-support-chat-adapter.md) | **In Universal Telegram repo** after Contract v1: client, binding table, inbound/outbound, compliance | R1 channel |
| 5 | [SC-M03](milestones/sc-m03-controlled-migration-and-cutover.md) | Authenticated Contract server (ADR-0007), then quiesced one-shot migration; bindings for existing topics | ADR-0004, ADR-0007 |
| 6 | [SC-M04](milestones/sc-m04-telegram-optional-acceptance.md) | Prove SC with adapter absent/unavailable | R1, R7 |
| 7 | [SC-M05](milestones/sc-m05-professional-widget-experience.md) | Professional launcher and greeting | R2, R3 |
| 8 | [SC-M06](milestones/sc-m06-support-availability-and-offline-tickets.md) | Hours, status, offline tickets | R5, R7 |
| 9 | [SC-AI1](milestones/sc-ai1-operator-ai-drafts-approve-and-send.md) | Operator drafts + Approve and send as *Support team* | Safety before autonomy |
| 10 | [SC-AI2](milestones/sc-ai2-controlled-direct-ai-responses.md) | Direct AI as *AI assistant* | R4, R6 |
| 11 | [SC-AI3](milestones/sc-ai3-ai-assisted-support-and-rag.md) | **Future / not implemented.** AI-assisted support / RAG knowledge base — grounded, traceable, site-scoped source corpus for SC-AI1/SC-AI2. Own ADR, plan, PO approval, evaluation, and privacy/security review required. | R4, R6 |

**SC-AI1 precedes SC-AI2.** SC-AI3 is a deferred forward-looking note only — see its charter; there is no ADR, plan, schema, code, or approval for it.

## 6. Explicit non-goals (foundation)

- Runtime PHP/JS/CSS, REST, schema, queues, widget assets, AI calls, Composer plugin packages, tests as product code, releases, tags, deployments.
- Modifying Universal Telegram in the foundation freeze task.
- Creating a Contract v1 release tag solely for documentation freeze.
- Linking non-repository working drafts as dependencies.
