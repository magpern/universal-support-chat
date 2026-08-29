# Product Owner Decision Record — ADR-0015 Operator Settings and Diagnostics: implementation acceptance

## Status

Approved

## Decision owner

Magnus (Product Owner, per `docs/governance.md` role table).

## Context

[ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) and its companion
plan [`sc-operator-settings-and-diagnostics-plan-v1.md`](../plans/sc-operator-settings-and-diagnostics-plan-v1.md)
were frozen as documentation-only on `main` at commit
`f978ea5e46223215af2e2b27cf48a0facf81f28f` (PR #42, "docs: freeze operator Settings and
Diagnostics design (ADR-0015)").

Both the ADR and the plan state that implementation is authorized only from the merged freeze
baseline, and the plan's Definition of Done requires ADR-0015 to be `Accepted`. Per
`docs/governance.md` ("Scope-change and closure approval authority"; "No role approves its own
work product as final"), that authorization is a distinct, explicit Product Owner act recorded
separately from the design freeze. This record captures it.

This record is documentation-only. It changes no architecture — ADR-0015 remains the
authoritative design — and it authorizes no work beyond the frozen scope of ADR-0015 and
plan v1.

## Decision

The Product Owner records the following acceptance verbatim:

> Product Owner acceptance — ADR-0015 operator Settings and Diagnostics implementation
>
> I accept ADR-0015 and `docs/plans/sc-operator-settings-and-diagnostics-plan-v1.md` for implementation exactly within their frozen scope.
>
> This authorizes implementation of the five defined work packages only: Support Chat submenu structure; a Settings page exposing only the six existing settings; read-only, redacted Diagnostics; plugin-row navigation links; and the capability-checked legacy Settings URL redirect.
>
> It does not authorize any new setting, option, default, schema or db-version change, dependency, capability change, dispatch or Telegram behavior change, Universal Telegram change, AI/RAG work, DEV or production deployment, live setting change, release, tag, or data operation.
>
> Signed: Product Owner
> Date: 2026-08-29

## Scope authorized (for reference — the record above is authoritative)

Exactly the five work packages frozen in
[plan v1 §10](../plans/sc-operator-settings-and-diagnostics-plan-v1.md) and
[ADR-0015 §1–§4](../adr/0015-operator-settings-page-and-diagnostics-separation.md):

1. **WP1** — `Administration\Settings\SupportChatSettingsPage` as a submenu of the existing
   top-level `Support Chat` menu; four sections; each of the six existing option keys an
   explicit control with a hidden `0` companion; `remove_data_on_uninstall` as the final,
   clearly warned "Data removal" setting; the
   `option_page_capability_universal_support_chat_settings_group` filter registered in
   `register()` (not `admin_init`); Settings API nonce handling; save feedback.
2. **WP2** — `Administration\Diagnostics\DiagnosticsPage` reparented under the Support Chat
   menu and renamed "Diagnostics" (slug `universal-support-chat-diagnostics`); read-only;
   adds only the frozen safe aggregates (dispatch enabled/disabled; peer pairing state and
   usability; dispatch-outbox counts by state); enforces the ADR-0015 §3 redaction boundary.
3. **WP3** — explicit "Conversations" first-submenu label on the Hub; `PluginActionLinks`
   "Settings" link retargeted to the new Settings page; Settings↔Conversations and
   Diagnostics→Settings links; `Administration\Compat\LegacySettingsRedirect` (pure
   `resolve_target()` + thin `maybe_redirect()` with the frozen body).
4. **WP4** — the read-only Telegram status panel on the Settings page (dispatch flag +
   pairing state + link to Diagnostics; no controls).
5. **WP5** — documentation touch-ups (changelog note on the Diagnostics slug move and the
   legacy-URL 302).

## Not authorized

Per the acceptance text: no new setting, option, default, schema or `db_version` change,
dependency, capability change, dispatch or Telegram behaviour change, Universal Telegram
change, AI/RAG work, DEV or production deployment, live setting change, release, tag, or data
operation. `Settings::sanitize()` and `Settings::defaults()` are not to be modified.

## Affected Documents/Milestones

- [ADR-0015](../adr/0015-operator-settings-page-and-diagnostics-separation.md) — Status moves
  `Proposed` → `Accepted`, referencing this record.
- [plan v1](../plans/sc-operator-settings-and-diagnostics-plan-v1.md) — header updated to note
  implementation is authorized by this record from the merged `main` baseline.
- [`docs/decisions/README.md`](README.md) — index entry.
- [`docs/adr/README.md`](../adr/README.md) — ADR-0015 index status.

## Baseline

Implementation begins from `main` after this record merges. The implementation branch and PR
must cite:

- ADR-0015 / plan freeze commit: `f978ea5e46223215af2e2b27cf48a0facf81f28f`.
- This acceptance record's merge commit (to be filled in the implementation PR).
