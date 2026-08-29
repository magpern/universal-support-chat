# SC-AI3 — AI-Assisted Support / RAG Knowledge Base (future — not implemented)

## Status

**Future / not implemented / deferred.**

This is a forward-looking roadmap note only. There is no ADR, no frozen plan, no
schema, no code, and no Product Owner approval for it yet. Nothing in this document
authorizes any implementation work.

Depends on: SC-AI1 and SC-AI2 (the human-approved-draft and controlled-direct-AI
boundaries) plus its **own** future ADR package, plan, and Product Owner approval.

## Current state (as of this note)

- Universal Support Chat has **no** AI, LLM, RAG, embeddings, vector store, content
  ingestion, retrieval, or automated-answer capability of any kind.
- Support Chat is a **conversation system of record** ([SC-M01](sc-m01-conversation-system-of-record.md))
  with an optional Telegram operator relay ([ADR-0012](../adr/0012-automatic-support-chat-to-telegram-dispatch.md)).
- Telegram-originated replies are **human-operator** messages relayed through the
  channel adapter. They are never AI-generated.
- The existing AI milestones [SC-AI1](sc-ai1-operator-ai-drafts-approve-and-send.md)
  and [SC-AI2](sc-ai2-controlled-direct-ai-responses.md) are themselves still
  `Planned` and each already require their own AI ADR package before any code.

## Deferred future capability

A future **"AI-assisted support / RAG"** capability would let an approved model
answer or assist using a **controlled Support Chat knowledge base** derived from
**selected** site / support content. Its purpose is to give SC-AI1 drafts and
SC-AI2 direct replies a grounded, traceable, site-scoped source corpus instead of
unsourced model knowledge.

It is recorded here only as a **separate, later milestone** that requires, in order
and each as its own reviewed step:

1. Its own ADR (or ADR package).
2. Its own frozen implementation plan.
3. Explicit Product Owner approval.
4. Implementation.
5. Evaluation (answer quality, refusal behaviour, grounding fidelity).
6. A dedicated privacy and security review.

## Non-negotiable design boundaries for the later milestone

These constrain the future ADR; they do not pre-decide it.

- **Ownership.** Support Chat remains the owner of the feature and of the knowledge
  base. Universal Telegram stays transport / adapter only and gains nothing here.
- **Off by default.** No AI, retrieval, indexing, or provider call is enabled merely
  by installing or updating the plugin. Enablement is explicit administrator site
  policy, consistent with master-plan **R4**.
- **Explicit, traceable, removable sources.** Knowledge sources must be explicitly
  selected by an administrator, individually indexed, individually traceable back to
  their origin in any answer, and individually removable (removal purges the derived
  index entries).
- **Scoped retrieval.** Retrieval must be tenant / site scoped. It must never expose
  credentials, private operator notes, operator-only data, unrelated customer
  conversations, or arbitrary WordPress content (drafts, private posts,
  password-protected content, customer/order/comment/profile data).
- **Untrusted content.** The model must treat retrieved material and visitor text as
  **untrusted data, not executable instructions** (prompt-injection containment).
- **No executable output.** No generated HTML, JavaScript, PHP, SQL, shell commands,
  or other executable content may be rendered or executed. Output is inert text.
- **Human control preserved.** Human handoff and operator review remain available;
  the SC-AI1 → SC-AI2 "safety before autonomy" ordering is unchanged.
- **Deferred to the ADR.** Model / provider choice, API-key storage, spending limits,
  retention, logging / redaction, prompt-injection controls, evaluation methodology,
  and failure behaviour are **not** assumed now — the future ADR decides them.

## Legacy clarification (Universal Telegram, historical reference only)

The retired Universal Telegram legacy chat implementation is inspected here purely as
historical reference. Source-verified findings (Universal Telegram repository, at the
M09 merge commit `396b1d8`, ADR-0028, and the later transport-only retirement
`1af1cf3` / ADR-0044):

- Universal Telegram's legacy "M09 — AI Draft Assistant" shipped **operator-only draft
  generation, operator-reviewed conversation / operational summaries (M11B), an
  OpenAI provider abstraction, and AI configuration**. No AI output was ever
  visitor-facing.
- Its grounding layer (`src/AI/Content/ApprovedContentRepository.php`) was a
  **bounded, plain in-PHP keyword-overlap match** over an explicit administrator
  allow-list of approved, published, non-password-protected posts / pages, with the
  query derived solely from the conversation's own last visitor message.
- It was **not** a genuine retrieval / vector / embeddings knowledge base. ADR-0028's
  Alternatives section **explicitly rejected "a vector database or embeddings-based
  retrieval layer" outright**.
- Whether the keyword allow-list grounding is called "RAG" is a terminology
  judgement: it is retrieval-grounded generation, but keyword-based, not
  embeddings / semantic. There was no content ingestion pipeline, no vector index,
  and no semantic search surface.
- Status: the M09 code was merged into Universal Telegram but its Product Owner
  acceptance remained pending, and the entire legacy chat + AI subsystem
  (`src/AI/**`) was removed when Universal Telegram became transport/adapter-only
  (ADR-0044). It does not exist in Universal Telegram today.

Conclusion: **AI drafts / summaries / configuration plus a bounded keyword allow-list
grounding — not a genuine vector/embeddings RAG feature.** This is source-verified,
not inferred.

## Frozen plan

None. A plan is authored only after the future ADR and Product Owner approval exist.
