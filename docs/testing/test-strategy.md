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

## SC-M07 — AI-first visitor support ([ADR-0018](../adr/0018-ai-first-visitor-support.md), Proposed in the freeze)

When SC-M07 implementation is authorized (a separate Product Owner acceptance record), it
adds `tests/unit/AI/` and `tests/integration/AI/` suites covering the provider contract, the
input-independence of the server-owned system policy, prompt/data fencing against injection,
keyword-retrieval ranking / budget / stale-and-revoked exclusion, encrypted knowledge
persistence, rate-limit-to-handoff behaviour, turn idempotency, the handoff-reason enums,
operator takeover, retention/uninstall, and redaction of every `ai.*` audit and diagnostics
path.

**No real AI provider call is ever made in CI — this is structural, not policy:** unit tests
have no HTTP; integration tests wire the deterministic `AI\Provider\FakeProvider`; and a
structural boundary test confines every `wp_*remote_*` call in `src/` to `src/AI/Provider/`
(reached only by the async worker, never by a visitor or Hub request). A separate,
env-var-guarded, non-CI script may exercise the real OpenAI endpoint for manual verification.
The interop suite must stay green with Universal Telegram unchanged, and a check asserts an
`ai`-direction message is never mirrored to Telegram.

## Documentation freeze validation

For this foundation freeze: Markdown link integrity, absence of prohibited strings, and documentation-only diff — no runtime plugin code.
