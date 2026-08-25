# SC-AI2 Controlled Direct AI Responses — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-ai2-controlled-direct-ai-responses.md`
- Requirements: **R4**, **R6**, **R1**
- Depends on: SC-AI1 complete

## 2. Decisions

- Site policy + disclosure; not visitor checkbox.
- Attribution **AI assistant**.
- No write-capable tools.
- Ordinary AI-only turns must not call `ensure_channel_case` / `deliver_message`.

## 3. Work packages (high level)

1. AI policy/disclosure ADR package (future)  
2. First-line responder  
3. Escalation rules to human  
4. Zero-channel tests for AI-only turns  
5. Safety evaluations  

## 4. Out of scope

Starting before SC-AI1; channel-required tickets.

## 5. Definition of done

R4/R6/R1 charter acceptance.
