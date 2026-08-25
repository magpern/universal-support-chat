# Test Strategy — Universal Support Chat

## Purpose

Define how quality is evidenced across milestones. Runtime test tooling is introduced in SC-M00; this document is the policy baseline.

## Layers

1. **Unit tests** — pure PHP domain logic without WordPress bootstrap where possible.
2. **Integration tests** — WordPress test install; WooCommerce only when a milestone explicitly requires it (Support Chat core does not hard-depend on WooCommerce).
3. **Contract / isolation tests** — visitor ownership, capability negatives, no Telegram requirement for Hub/SoR paths (R1, R7).
4. **Migration matrix** — SC-M03/SC-M04 interoperability cases in ADR-0004 / SC-M03 charter.
5. **Independent acceptance** — when a milestone charter requires it (Vlad), per governance.

## CI expectations (from SC-M00 onward)

- Coding standards and static analysis when PHP exists.
- Unit + integration jobs on supported WordPress versions.
- Documentation link checker for `docs/**/*.md` and `README.md`.

## Documentation freeze validation

For this foundation freeze: Markdown link integrity, absence of prohibited strings, and documentation-only diff — no runtime plugin code.
