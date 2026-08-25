# SC-M00 — Foundation

## Status

Implemented (technical verification; Product Owner closure via merge)

Depends on: none

## Objective

Establish the plugin architecture, development standards, persistence foundations, security boundaries, privacy model, vault/migration approach, and testing infrastructure required by all later milestones.

## Product value

Nothing visitor-facing ships. Risk reduction for all later work.

## Included scope

- WordPress plugin bootstrap and lifecycle (activate, deactivate, upgrade, uninstall).
- Namespace, autoloading, directory structure using identifiers from [ADR-0002](../adr/0002-plugin-identity-and-ownership-boundaries.md).
- Module boundaries for Support Chat product areas (inert until owning milestones).
- Database schema migration framework (only schema SC-M00 itself needs).
- Capability and authorization model foundations ([ADR-0003](../adr/0003-security-privacy-and-visitor-isolation.md)).
- Vault / secret-handling and fail-closed production posture.
- Privacy classification and audit foundations.
- Coding standards, static analysis, unit/integration test foundations, CI foundation.
- Developer documentation updates as needed for runtime bootstrap.

## Explicit exclusions

- Conversations SoR, visitor REST, widget, Hub inbox (SC-M01/SC-M02).
- Channel adapter implementation (UT Adapter M1 in Universal Telegram).
- Migration cutover (SC-M03).
- AI (SC-AI1/SC-AI2).
- Professional visual polish (SC-M05) and availability (SC-M06).

## Acceptance criteria

- Clean install/upgrade/uninstall on supported WordPress.
- Secrets excluded from logs; capabilities enforced for privileged surfaces introduced in M00.
- Unit/integration foundations pass per frozen plan.
- No Telegram dependency.

## Entry / exit

- Entry: foundation documentation freeze merged (this repository baseline).
- Exit: Product Owner closure after verification per governance.

## Frozen plan

[sc-m00-foundation-plan-v1.md](../plans/sc-m00-foundation-plan-v1.md)
