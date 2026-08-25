# SC-AI1 Operator AI Drafts and Approve-and-Send — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-ai1-operator-ai-drafts-approve-and-send.md`
- Precedes: SC-AI2
- Contract: ADR-0005 (`deliver_message` only if already escalated)

## 2. Decisions

- Human-approved send only; attribution **Support team**.
- No autonomous visitor send.
- Additional AI ADRs required before coding beyond this boundary freeze.
- Legacy UT draft data migrates under this milestone’s AI migration subsection — not SC-M03.

## 3. Work packages (high level)

1. AI ADR package (future docs freeze)  
2. Draft lifecycle  
3. Approve-and-send into SoR  
4. Optional escalated channel mirror  
5. Tests for no-send-without-approve  

## 4. Out of scope

SC-AI2 direct replies; visitor checkbox enablement model.

## 5. Definition of done

Charter acceptance; SC-AI1 closed before SC-AI2 starts.
