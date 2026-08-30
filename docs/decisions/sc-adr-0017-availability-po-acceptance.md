# Product Owner Decision Record — ADR-0017 / SC-M06 Support Availability and Offline Tickets: implementation acceptance

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
and its companion plan
[`sc-m06-support-availability-and-offline-tickets-plan-v2.md`](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md)
were frozen as documentation-only on `main` at commit
**`cdfcd5ada3de635365d9051c42b6b7da80c82b16`** (PR #51, "docs(sc-m06): freeze ADR-0017
(Proposed) + availability & offline-tickets plan v2"). Plan v2 supersedes the original
product-boundary stub `sc-m06-support-availability-and-offline-tickets-plan-v1.md` (retained
unedited).

Both the ADR and plan v2 state that ADR-0017 is merged **Proposed** in the freeze and that
implementation is authorized only from the merged freeze baseline, after a separate Product
Owner acceptance act recorded distinctly from the design freeze (per `docs/governance.md` —
"No role approves its own work product as final"). This record captures that act.

This record is documentation-only. It changes no architecture — ADR-0017 remains the
authoritative design — and it authorizes no work beyond the frozen scope of ADR-0017 and
plan v2.

## Decision

The Product Owner records the following acceptance verbatim:

> Product Owner acceptance — ADR-0017 / SC-M06 support availability and offline tickets
> implementation
>
> I accept [ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
> and [`docs/plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md`](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md)
> for implementation exactly as merged in the freeze at
> `cdfcd5ada3de635365d9051c42b6b7da80c82b16`, and exactly within their frozen scope.
>
> This authorizes implementation of the frozen SC-M06 plan v2 (WP1–WP8) only, with these
> decisions fixed:
>
> - **Support Chat is the sole authority** for availability, the weekly schedule, date
>   exceptions, the manual override, the visitor-facing resolved state, and offline-ticket
>   handling. Universal Telegram is not an authority and is never required.
> - Availability is evaluated in the **WordPress site timezone** (`wp_timezone()` / PHP
>   `DateTimeZone`); DST follows that timezone. Resolved state is exactly `available` or
>   `unavailable`; operator mode is exactly `Automatic`, `Force online`, or `Force offline`.
> - Resolution **precedence**, highest first:
>   `manual override → date exception → weekly schedule → fail-safe unavailable`. A `closed`
>   date exception forces `unavailable`; a special-hours exception replaces that date's
>   weekly intervals. An invalid, empty, or unparseable runtime schedule, or a timezone
>   evaluation failure, **fails closed to `unavailable`** and is never rewritten.
> - The widget **never** shows an untrue online claim, availability promise, response-time
>   estimate, or ETA. A subtle "We're online" indicator appears **only** while the resolved
>   state is truly `available`. Offline copy is **plain text only** — tag-stripped in,
>   `esc_html()` / `.textContent` out, never `innerHTML` (ADR-0016 precedent).
> - **Default schedule: Monday–Friday, 12:00–15:00 in the site timezone.**
> - **One global offline message**, not separate exception / out-of-hours variants. Default
>   text: `The support team is offline right now. Leave your message here and we'll reply in
>   this chat when we're back.`
> - Manual overrides may carry a timestamp expiry **or a `null` expiry** ("until cleared").
>   A `null` expiry is valid, persists until cleared, and is visible in the Hub and in
>   Diagnostics. Only **expired non-null** overrides are lazily reaped.
> - **Offline ticket contract:** an offline ticket is not a new ticket type or an
>   unauthenticated form. It is an existing authenticated visitor conversation/message
>   submitted while the **server** resolves availability as `unavailable` (the browser value
>   is presentation only). On acceptance the server commits, **in one transaction**: (1) the
>   visitor message, (2) the corresponding existing content-free ADR-0012 dispatch-outbox
>   row when Telegram dispatch is enabled, and (3) the conversation transition to
>   `waiting_for_operator`; if the transition fails the whole transaction rolls back. The
>   status map gains **exactly one** direct edge, `new → waiting_for_operator` (no synthetic
>   `new → open → waiting_for_operator`). The `unavailable` path may transition `new`,
>   `open`, or `waiting_for_visitor` to `waiting_for_operator`. Available behaviour is
>   unchanged. Existing operator-reply behaviour (`waiting_for_operator →
>   waiting_for_visitor`) is unchanged. The start/message POST responses (and the existing
>   poll response) carry the freshly resolved availability state.
> - **Hub Waiting view:** an explicit Waiting filter; membership exactly
>   `waiting_for_operator` plus legacy `new` only as a documented transitional inclusion;
>   sorted `updated_at` ascending (oldest first); no inferred "last visitor message
>   unanswered" query.
> - Schedule, exceptions, and the offline-copy fields are **additive keys** in the existing
>   fixed-shape `universal_support_chat_settings` option. The Availability-section update is
>   validated **atomically**: any malformed value rejects the whole section update and the
>   previous valid configuration is preserved — malformed input is never silently normalised
>   to defaults. The manual override lives in a separate autoloaded runtime option
>   (`universal_support_chat_availability_override`) changed only through a dedicated
>   nonce-protected, `MANAGE`-gated `admin_post` action — never through the Settings API.
>   That option is removed only under the existing opted-in `remove_data_on_uninstall`
>   semantics.
> - A read-only Diagnostics "Availability" block shows only safe aggregates: resolved state,
>   mode, override expiry (or "until cleared"), and "schedule config valid: yes/no". INTERNAL
>   audit events are recorded for schedule update, exceptions update, override set, override
>   cleared, and override expired.
> - **SC-M06 adds no Telegram mechanism.** Existing opt-in ADR-0012 dispatch may mirror an
>   offline visitor message through the existing asynchronous `DispatchWorker`. Adapter
>   absence, disablement, version mismatch, or failure never affects ticket creation, the
>   status transition, or Hub handling. **No Telegram I/O occurs in the originating
>   request.**
> - Uses the existing `CapabilityRegistrar::MANAGE` capability — **no new capability**.
> - **No database schema or `universal_support_chat_db_version` change** (stays `12`;
>   `Migrator` untouched).
>
> This authorization **excludes**: any DEV or production deployment; any live setting or
> data change; any Telegram or Universal Telegram change; any Contract v1 change; any GitHub
> Release, version tag, or data operation; any AI, RAG, embeddings, provider, prompt, or
> automated-answer capability; any live operator-presence signal; any SLA timer,
> response-time, or ETA copy; any per-operator or per-team schedule; any visitor
> email/phone/subject capture; any new REST route (including a public unauthenticated
> availability endpoint); any change to the frozen technical content of plan v2 or ADR-0017;
> and any work outside SC-M06.
>
> Signed: Product Owner
> Date: 2026-08-30

## Scope authorized (for reference — the record above is authoritative)

Exactly the work packages frozen in
[plan v2 §14](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md) (WP1–WP8)
and the decisions in
[ADR-0017 §Decision](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md)
(§§1–9):

1. **WP1** — the pure `UniversalSupportChat\Availability` resolver + schedule / exception
   value objects + `InvalidScheduleException`, with the frozen precedence and DST/boundary
   handling.
2. **WP2** — the `new → waiting_for_operator` status-map edge (operator-reply edge verified
   and preserved), the `AvailabilityService`, and the one-transaction server-authoritative
   message + outbox + transition path in `ConversationsController` with an `availability`
   field on all response envelopes.
3. **WP3** — the four additive `universal_support_chat_settings` keys with atomic validation
   that preserves the prior valid configuration, and the "Availability" section on the
   existing ADR-0015 Settings page.
4. **WP4** — the `universal_support_chat_availability_override` autoloaded option, the
   nonce + `MANAGE` `admin_post` override action, expiry reaping (read path + the existing
   daily retention cron, no new event), `Uninstaller` deletion under
   `remove_data_on_uninstall`, the audit events, and the Hub override control block.
5. **WP5** — widget rendering: server-rendered `availability` + offline copy, the
   `.textContent` offline notice and post-send confirmation, the "We're online" pill shown
   only when truly available, and state refresh from the poll and POST responses; CSS.
6. **WP6** — the explicit Hub **Waiting** filter (`= waiting_for_operator` [+ transitional
   `new`], `updated_at ASC`).
7. **WP7** — the read-only Diagnostics "Availability" block (safe aggregates only) and the
   runtime-corruption admin warning.
8. **WP8** — wiring in `Plugin.php`, the asset cache-bust version-constant bump (no release
   or tag), `docs/ARCHITECTURE.md` + the structural unauthorized-boundary test update to
   permit `src/Availability/`, the full CI gate run, and the implementation PR (left open,
   unmerged; no closure record).

## Not authorized

Per the acceptance text: no DEV or production deployment; no live setting or data change; no
Telegram or Universal Telegram change; no Contract v1 change; no GitHub Release, tag, or data
operation; no AI / RAG / provider / prompt / automation; no operator-presence signal; no SLA
/ response-time / ETA copy; no per-operator schedules; no visitor contact capture; no new
REST route (including a public unauthenticated availability endpoint); no schema or
`universal_support_chat_db_version` change (stays `12`); no new capability; no change to the
frozen technical content of plan v2 or ADR-0017; no work outside SC-M06; no closure record
as part of the implementation PR.

## Affected Documents/Milestones

- [ADR-0017](../adr/0017-support-availability-authority-and-honest-offline-behaviour.md) —
  Status moves `Proposed` → `Accepted` in the same commit as this record, referencing it.
- [plan v2](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md) — header
  gains a short "implementation authorized" note (frozen technical content unchanged).
- [`docs/decisions/README.md`](README.md) — index entry.
- [`docs/adr/README.md`](../adr/README.md) — ADR-0017 index status `Proposed` → `Accepted`.
- [SC-M06 charter](../milestones/sc-m06-support-availability-and-offline-tickets.md) —
  already points to plan v2 and ADR-0017; milestone scope unchanged.

## Baseline

Implementation begins from `main` after this record merges. The implementation branch and PR
must cite:

- ADR-0017 / plan v2 freeze commit: `cdfcd5ada3de635365d9051c42b6b7da80c82b16` (PR #51).
- This acceptance record's merge commit (to be filled in the implementation PR).
