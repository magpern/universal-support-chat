# ADR-0017 — Support availability authority, and honest offline / offline-ticket behaviour

## Status

**Accepted** — 2026-08-30, per Product Owner implementation acceptance
[`docs/decisions/sc-adr-0017-availability-po-acceptance.md`](../decisions/sc-adr-0017-availability-po-acceptance.md).
Introduced for [SC-M06 — Support Availability and Offline Tickets](../milestones/sc-m06-support-availability-and-offline-tickets.md)
and its plan [sc-m06-support-availability-and-offline-tickets-plan-v2.md](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md)
(which supersedes the product-boundary stub plan v1, retained unedited); merged as
**Proposed** in the SC-M06 documentation freeze on `main` at
`cdfcd5ada3de635365d9051c42b6b7da80c82b16` (PR #51). Implementation is authorized — from the
merged freeze baseline, exactly within the frozen scope of this ADR and plan v2 — by the
acceptance record cited above; implementation begins only after that record merges.

No plugin version tag, GitHub Release, DEV change, production change, live setting or data
change, Telegram/Universal Telegram change, Contract v1 change, schema or
`universal_support_chat_db_version` change, or new capability is part of this decision.

## Context

Master-plan requirement **R5** ("Support hours and live status") gives Support Chat
ownership of the support schedule, exceptions, a manual `Automatic / Online / Offline`
control, the waiting queue, and Hub administration, with Telegram `/support` only ever an
optional adapter capability. Requirement **R7** ("Offline human support") requires that a
human request **always** creates a durable Support Chat ticket with truthful offline
wording, that Telegram may notify only if connected, and that a **ticket never depends on
Telegram**. [ADR-0006](0006-optional-channel-and-adapter-failure-model.md) already fixes the
fail-closed-per-channel posture and restates both rules; it does not define how availability
is evaluated or where its state lives.

The SC-M06 charter and the foundation-freeze stub plan v1 froze only the product boundary.
`docs/plans/README.md` records that the SC-M06 plan "freeze[s] product boundaries; [it] may
require additional ADRs before coding." `docs/ARCHITECTURE.md` lists the **Availability**
boundary as "Not authorized until SC-M06" with no `src/` directory permitted yet.

Relevant current runtime facts (verified against the freeze baseline
`b17f4713f88e9db24dd7942b1f7b0cf768263721`):

- The visitor REST surface (`Conversations\Rest\ConversationsController`) is
  **authenticated-only** — every handler requires `is_user_logged_in()` plus a valid
  `wp_rest` nonce. Logged-out visitors never reach conversation storage; the widget
  (`ChatWidget\WidgetAssets`) shows them a truthful sign-in prompt.
- `Conversations\ConversationStatus` already defines `waiting_for_operator` and a transition
  map, but the map does **not** currently permit `new → waiting_for_operator` (only
  `new → open` and `new → archived`).
- `Core\Configuration\Settings` is the sole owner of the single fixed-shape option array
  `universal_support_chat_settings` (nine keys today). [ADR-0016](0016-support-chat-widget-presentation-settings.md)
  established that ADR-0015's "no new option key" fence is scoped to ADR-0015 and that later
  milestones may add keys additively, resolved through `Settings::sanitize()`.
- Operator-authored, visitor-rendered text is **plain text only** — tag-stripped on input,
  `esc_html()` / `.textContent` on output, never `innerHTML`
  ([ADR-0016](0016-support-chat-widget-presentation-settings.md) §2). SC-M06 was
  forward-named there as an inheritor of that precedent.
- Telegram delivery is already fully off the request critical path
  ([ADR-0012](0012-automatic-support-chat-to-telegram-dispatch.md),
  [ADR-0014](0014-interactive-chat-delivery-class-and-immediate-dispatch.md)): the
  visitor/Hub request commits the message and a content-free outbox row in one transaction
  and fires a non-blocking cron kick; all Telegram I/O runs in `DispatchWorker` under
  WP-Cron.
- The plugin has no timezone handling yet. WordPress `wp_timezone()` / `current_datetime()`
  is the site-timezone source.

Without an ADR, several durable questions would be settled only as implementation detail:
who owns availability, how the schedule / exceptions / manual override combine, what happens
when configuration is invalid, whether the widget may ever imply an untrue "online" or a
response-time estimate, and whether an "offline ticket" is a new artefact or an existing
conversation. Each of these is an ownership, precedence, or safety boundary that later
milestones (SC-AI2 "recommended after SC-M06", future SLA/response-time work) will build on.

## Decision

### 1. Support Chat is the sole availability authority

Support Chat owns and evaluates, in-process, the support schedule, date exceptions, the
manual availability override, the resolved visitor-facing availability state, and
offline-ticket handling. No channel adapter — and specifically not Universal Telegram — is
an authority over, or a required participant in, availability, schedules, tickets, or
conversation state. Support Chat operates identically whether an adapter is absent,
disabled, mismatched, or failing.

### 2. Site timezone

Scheduled hours and date exceptions are stored as wall-clock times/dates and evaluated in
the **WordPress site timezone** (`wp_timezone()`), using PHP `DateTimeZone` /
`DateTimeImmutable`. Daylight-saving transitions follow that timezone. Changing the site
timezone re-interprets the same stored schedule with no migration and no stored-data
rewrite.

### 3. Availability state model and precedence

The resolved availability state is exactly one of `available` or `unavailable`. The
operator-facing control mode is exactly one of `Automatic`, `Force online`, or
`Force offline`.

Resolution precedence, highest first — this order is frozen:

1. **Manual override** — a non-expired `Force online` / `Force offline` override sets the
   state directly.
2. **Date exception** for "today" in the site timezone — a `closed` exception forces
   `unavailable` for that date; a "special hours" exception **replaces** that date's
   weekly intervals with the exception's intervals.
3. **Weekly schedule** — in `Automatic` mode with no exception for today, the state is
   `available` iff "now" (site timezone) falls within a scheduled interval for the current
   weekday, otherwise `unavailable`.
4. **Fail-safe** — `unavailable` whenever the effective configuration cannot be evaluated:
   an unparseable or empty schedule, an unparseable stored override or exception set, or a
   timezone-resolution failure.

A `closed` date exception is therefore an availability decision for that date, not merely a
schedule-validation concern.

### 4. Fail-safe is pessimistic and honest

An unresolvable configuration never yields `available` and never yields any "online" or
response-time claim. The offline path (below) stays fully open. Runtime-corrupt stored
configuration is **not** rewritten or normalised by the resolver; it triggers the fail-safe
state and an admin-only Diagnostics warning.

### 5. Visitor-copy honesty — durable boundary

The visitor widget must never display an untrue "online" state, an availability promise, a
response-time estimate, or an ETA. Concretely:

- A positive indicator ("We're online") is shown **only** when the resolved state is truly
  `available`.
- When `unavailable`, the widget states plainly that the team is offline and that a message
  left now will be answered later **in this chat** — with no time estimate.
- Any promised-response-time or SLA-style copy is **out of scope for SC-M06** and requires a
  new ADR before it may be added.
- Operator-authored offline copy is **plain text only**, inheriting
  [ADR-0016](0016-support-chat-widget-presentation-settings.md) §2 (tag-stripped in;
  `esc_html()` / `.textContent` out; never `innerHTML`).

### 6. Manual override — model, expiry, precedence

The override is durable runtime state, stored **separately** from the fixed-shape
`universal_support_chat_settings` option (an auto-expiring value does not belong in
sanitised Settings-API config). It records at least: the mode (`Force online` /
`Force offline`), an expiry that is **either a timestamp or `null`**, the setting operator,
and the set-at time.

- A `null` expiry ("until cleared") is a valid, first-class, persistent state. It is shown
  as such in the Hub and in Diagnostics and persists until an operator explicitly clears it.
- Lazy reaping applies **only** to an override whose **non-null** expiry is in the past:
  such an override is evaluated as absent and then cleared (on next read and on a cheap
  scheduled tick).
- Clearing the override — explicitly, or by reaping an expired one — returns the system to
  `Automatic`.
- The override is changed only through a dedicated, nonce-protected,
  `CapabilityRegistrar::MANAGE`-gated action — never through the Settings API save path.

### 7. Offline ticket = an existing authenticated conversation, resolved server-side, committed atomically

An "offline ticket" is **not** a new ticket type, a new table, or an unauthenticated form.
It is an ordinary authenticated visitor conversation/message, created or continued through
the **existing** REST endpoints, that is submitted while the server resolves the
availability state as `unavailable`.

- Availability is resolved **authoritatively on the server** at message-acceptance time.
  Any value the browser holds is presentation only and never trusted for this decision.
- When the server resolves `unavailable` for an accepted visitor message, it commits, as
  **one unit of work**: (a) the visitor `ConversationMessage`; (b) the corresponding
  existing content-free ADR-0012 dispatch-outbox row, when Telegram dispatch is enabled;
  and (c) the conversation transition to `waiting_for_operator`. If the transition cannot be
  applied, the whole unit rolls back — a committed message is never left in the prior
  status.
- The conversation-status transition map gains **exactly one** new edge:
  `new → waiting_for_operator`. This is a code-constant change to
  `Conversations\ConversationStatus`; it changes no table and no
  `universal_support_chat_db_version`. A synthetic `new → open → waiting_for_operator`
  routing is explicitly rejected (see Alternatives).
- The `unavailable` path may move a conversation to `waiting_for_operator` from `new`,
  `open`, or `waiting_for_visitor`, including an already-active conversation.
- When the server resolves `available`, existing behaviour is unchanged
  (`new` / `waiting_for_visitor` → `open`).
- Existing operator-reply behaviour is unchanged: `waiting_for_operator →
  waiting_for_visitor` (already a legal edge).
- The start (`POST /conversations`) and message (`POST /conversations/{uuid}/messages`)
  responses, and the existing poll response (`GET /conversations/{uuid}`), carry the
  freshly server-resolved availability state, so a widget opened just before a schedule
  boundary cannot keep showing stale "online" UI after the visitor acts.

### 8. Telegram — no new mechanism

SC-M06 adds no Telegram notification or dispatch mechanism. Where an operator has already
opted into ADR-0012 dispatch and a usable adapter is paired, the visitor's offline message
is mirrored by the **existing** `DispatchWorker` exactly as any other committed visitor
message. Adapter absence, disablement, version mismatch, or failure never affects ticket
creation, storage, the `waiting_for_operator` transition, or Hub handling. No Telegram I/O
occurs in the originating visitor request.

### 9. No new capability, schema, endpoint, or AI

`CapabilityRegistrar::MANAGE` gates schedule/exception edits, offline-copy edits, and
override changes. No new capability. No schema or `universal_support_chat_db_version` change
(stays at `12`). No new REST endpoint — the existing authenticated conversation/message
endpoints carry the availability field; a **public unauthenticated availability endpoint is
explicitly not introduced in SC-M06**. No AI, RAG, embeddings, presence signal, SLA timer,
per-operator schedule, or visitor contact-capture field.

## Alternatives

- **Store availability, including the manual override, inside
  `universal_support_chat_settings`.** Rejected: that option is sanitised fixed-shape
  configuration written only through `options.php`; an override that auto-expires is runtime
  state that changes outside an operator save. The schedule/exception/offline-copy *config*
  is a natural fit and is added there; the override is a separate autoloaded option.
- **Store and evaluate the schedule in UTC.** Rejected: operators reason about support hours
  in local wall-clock time; UTC storage makes every edit and every DST boundary
  error-prone. Site-timezone evaluation with `DateTimeZone` is both simpler for operators
  and correct across DST.
- **Add a dedicated availability capability.** Rejected: unnecessary — availability is
  operator configuration already covered by `MANAGE`, and a new capability adds a grant
  surface for no product need.
- **Show a live "operators online now" presence signal.** Rejected: the plugin has no
  presence data, and a presence indicator invites exactly the untrue "online" claim R5/R7
  forbid. Availability is schedule + exception + explicit override only.
- **Optimistic fail-open (treat unresolvable config as `available`).** Rejected: it would
  present an untrue "online" state and a chat the team is not watching. Fail-safe is
  `unavailable`.
- **Route offline `new` conversations through a synthetic
  `new → open → waiting_for_operator` hop to avoid changing the transition map.** Rejected:
  it adds status/audit churn and a transient `open` state the conversation was never really
  in. The direct `new → waiting_for_operator` edge — a code constant, no schema change —
  expresses the real state.
- **Treat date exceptions purely as schedule-validation input rather than a precedence
  tier.** Rejected: a "closed today" exception must actually force `unavailable`
  irrespective of the weekly pattern, so it is an evaluation tier above the weekly schedule.
- **Add a public unauthenticated `GET …/availability` endpoint so the closed launcher can
  show honest state before login.** Rejected for SC-M06: the pre-login launcher already
  shows only a truthful sign-in prompt and no availability claim, so the endpoint adds an
  unauthenticated surface for no in-scope benefit. It may be reconsidered in a later
  milestone with its own decision.
- **Make Telegram `/support` the availability control surface.** Rejected: R5 makes
  Telegram `/support` an optional adapter capability only; availability must be fully
  operable with no adapter.
- **A brand-new "offline ticket" entity / unauthenticated intake form.** Rejected: R7 is
  satisfied by the existing authenticated conversation model; a second intake path would
  fork visitor identity, isolation (ADR-0003), retention, and idempotency for no benefit.

## Consequences

- Support Chat gains a small, pure availability resolver plus schedule/exception value
  objects and a separate override option; a new "Availability" section on the existing
  ADR-0015 Settings page; a dedicated override action on the Hub; a read-only Availability
  block on Diagnostics; and one new conversation-status edge.
- Visitors outside support hours get an honest offline widget and can still leave a message
  that becomes a normal `waiting_for_operator` conversation, answerable from the Hub with no
  adapter present.
- Operators get a configurable weekly schedule, date exceptions, and a manual override with
  optional expiry, all under the existing `MANAGE` capability.
- The `Availability` boundary in `docs/ARCHITECTURE.md` becomes authorized for SC-M06 with a
  `src/` home.
- SC-AI2 (recommended after SC-M06) and any future response-time / SLA work inherit the
  honesty boundary and the "SC is the availability authority" rule.
- A downgrade to a pre-SC-M06 build simply stops evaluating availability; existing
  `waiting_for_operator` rows remain valid and handled.

## Security and privacy impact

- **No new capability and no capability relaxation.** Schedule/exception/offline-copy edits
  go through the existing ADR-0015 Settings page and its
  `option_page_capability_universal_support_chat_settings_group` filter (`MANAGE`). The
  override action performs its own `MANAGE` check and nonce verification.
- **No new visitor PII.** The visitor is an authenticated WordPress user; the offline path
  stores a conversation and a message exactly as the online path does. No email, phone, or
  free-text contact field is added. Visitor isolation and the authenticated-only visitor
  REST boundary ([ADR-0003](0003-security-privacy-and-visitor-isolation.md)) are unchanged —
  no route, field, or permission change.
- **Operator-authored offline copy cannot inject markup or script** — plain-text
  sanitisation on input, `esc_html()` / `.textContent` on output, no `innerHTML`
  ([ADR-0016](0016-support-chat-widget-presentation-settings.md)).
- **Diagnostics exposes only safe aggregates** — resolved state, mode, override expiry (or
  "until cleared"), and a "schedule config valid: yes/no" boolean — consistent with the
  ADR-0015 §3 redaction boundary. No schedule contents that could be sensitive beyond
  business hours, no visitor data, no credentials, identifiers, or raw errors.
- **Audit events** for schedule update, exceptions update, override set, override cleared,
  and override expired are classified `INTERNAL` and never exported to an adapter
  ([ADR-0003](0003-security-privacy-and-visitor-isolation.md)).
- **Fail-closed availability** avoids presenting a chat the team is not monitoring as
  "online" and avoids leaking partial adapter/binding state to visitors
  ([ADR-0006](0006-optional-channel-and-adapter-failure-model.md)).

## Affected Documents/Milestones

- [SC-M06 — Support Availability and Offline Tickets](../milestones/sc-m06-support-availability-and-offline-tickets.md)
  — charter; frozen-plan pointer moves to plan v2 and this ADR is referenced. Charter scope
  and acceptance criteria are unchanged.
- [sc-m06-support-availability-and-offline-tickets-plan-v2.md](../plans/sc-m06-support-availability-and-offline-tickets-plan-v2.md)
  — realises this ADR; supersedes the retained stub plan v1.
- Builds on [ADR-0006](0006-optional-channel-and-adapter-failure-model.md) (fail-closed per
  channel; R1/R7 restated) and inherits the plain-text precedent from
  [ADR-0016](0016-support-chat-widget-presentation-settings.md) §2.
- Complements [ADR-0015](0015-operator-settings-page-and-diagnostics-separation.md) (the
  Settings page the schedule/exception/offline-copy keys are added to; the Diagnostics page
  the Availability block is added to) and
  [ADR-0012](0012-automatic-support-chat-to-telegram-dispatch.md) /
  [ADR-0014](0014-interactive-chat-delivery-class-and-immediate-dispatch.md) (the
  post-commit outbox seam the offline message rides when dispatch is already enabled —
  unchanged here).
- Respects [ADR-0003](0003-security-privacy-and-visitor-isolation.md) and
  [ADR-0004](0004-migration-and-retention-principles.md) (offline tickets are conversations
  under the existing retention and uninstall paths) — neither is changed.
- `docs/ARCHITECTURE.md` — the **Availability** boundary row moves from "Not authorized
  until SC-M06" to an authorized SC-M06 description; the **Conversations** row notes the one
  added transition edge.
- Implementation will add `src/Availability/` (pure resolver + value objects), one edge to
  `src/Conversations/ConversationStatus.php`, additive keys in
  `src/Core/Configuration/Settings.php`, a new override option + action, an Availability
  section on `src/Administration/Settings/SupportChatSettingsPage.php`, an Availability block
  on `src/Administration/Diagnostics/DiagnosticsPage.php`, a Hub Waiting filter + override
  control, and an `availability` field on the existing
  `src/Conversations/Rest/ConversationsController.php` responses. No new REST route, schema,
  or capability.
- [docs/decisions/](../decisions/) — a later Product Owner implementation-acceptance record
  flips this Status to Accepted.

## Compatibility/Migration Impact

- **No schema version change.** `universal_support_chat_db_version` stays at `12`. No table
  is created, altered, dropped, or reinterpreted.
- **Additive option keys only.** New schedule / exceptions / offline-copy keys are added to
  the existing `universal_support_chat_settings` array; an option array lacking them resolves
  to the documented defaults through `Settings::sanitize()` (default schedule: Monday–Friday
  12:00–15:00 site timezone; no exceptions; a non-committal default offline message making
  no time claim). The manual override lives in a new autoloaded option that simply does not
  exist until first set (⇒ `Automatic`).
- **Transition-map edge is a pure code change.** `new → waiting_for_operator` is added to
  `ConversationStatus::map()`; no stored row is touched and every existing row in any status
  stays valid.
- **No behaviour change until an operator configures availability**, except that the default
  schedule (Mon–Fri 12:00–15:00) begins to apply on upgrade — which is the intended R5
  default and is fully operator-editable. Sites that want the previous always-on behaviour
  set `Force online` or a 24×7 schedule.
- **Uninstall unchanged in principle** — the new override option is removed only under the
  existing opted-in `remove_data_on_uninstall` path, alongside the settings option it
  accompanies.
- **Fully backward compatible with Universal Telegram absent, disabled, mismatched, or
  failing.** Availability evaluation and the offline path have no adapter dependency.
- **No plugin version tag, GitHub Release, DEV change, or production change** is part of this
  decision. A downgrade to a pre-ADR-0017 build stops evaluating availability and drops the
  new transition edge; existing `waiting_for_operator` conversations remain handled by the
  pre-existing edges.
