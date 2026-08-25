# SC-M03 Controlled Migration and Cutover — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-m03-controlled-migration-and-cutover.md`
- ADRs: ADR-0003, ADR-0004, ADR-0005, ADR-0006

## 2. Findings

Legacy chat SoR resides in Universal Telegram until cutover. UT Adapter M1 must already provide the binding table.

## 3. Assumptions

- Maintenance window available for quiescence.
- AI tables remain in UT (historical); not migrated here.

## 4. Decisions (locked)

Follow ADR-0004 exactly: no dual-write; quiesce five mutation classes; resumable re-encrypt copy; mapping + counts + content hashes; create bindings for existing topics; atomic route switch; soak read-only legacy; rollback = route reverse only.

## 5. Impact

- Migration WP-CLI (or admin-protected) tool in Support Chat and/or coordinated UT read-only exporter — **no cross-table writes**; export/import via explicit tools.
- Routing flags/options switching SoR ownership.
- Runbook documentation in-repo.

## 6. Security and privacy

Plaintext only in memory during re-encrypt; destroy buffers; validate no cross-visitor mapping.

## 7. Test and CI

Full interoperability matrix from charter; T9-style quiescence integrity; resume-after-interrupt test.

## 8. Work packages

1. Quiescence switches/drains  
2. Batch migrator + checkpoints  
3. Validators (counts/hashes/mapping)  
4. Binding creator (calls adapter APIs / writes adapter-owned bindings via UT tool)  
5. Atomic route switch  
6. Soak/rollback runbook  
7. Matrix automation/checklist  

## 9. Risks

- Migrating before adapter — blocked by dependency.  
- Partial cutover — forbidden; switch is atomic.

## 10. Out of scope

AI migration; destructive legacy deletes; dual-write; implementing Adapter M1.

## 11. Definition of done

Charter matrix green; PO accepts cutover runbook; soak begun or completed per PO.
