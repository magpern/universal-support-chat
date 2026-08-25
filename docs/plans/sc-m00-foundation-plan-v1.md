# SC-M00 Foundation — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-m00-foundation.md`
- ADRs: ADR-0001, ADR-0002, ADR-0003 (foundations); ADR-0004–0006 inform vault/migration posture but are not implemented here

## 2. Repository findings

Documentation-only repository at foundation freeze. No PHP plugin bootstrap, Composer package, or CI workflows exist yet. Sibling Universal-* plugins provide conventions to mirror in SC-M00 code (not copied in this freeze).

## 3. Assumptions and open questions

- Assumptions: WordPress 6.9+ / PHP 8.1+ targets match sibling plugins unless PO changes them in implementation.
- Open (decide in implementation without new product ADR unless architecture changes): exact Composer package name; Docker vs host toolchain layout details.

## 4. Architectural decisions

- Identifiers per ADR-0002.
- Capability + vault fail-closed posture per ADR-0003.
- No channel adapter code in this plugin.
- Migration framework present; no legacy data migration executed (SC-M03).

## 5. Impact

- New plugin bootstrap files, `src/` module boundaries (empty/inert where not needed), migration framework, test/CI skeletons.
- No conversation tables yet unless strictly required for framework proof (prefer deferring chat schema to SC-M01).

## 6. Security and privacy

Secrets never in logs; production fail-closed without key material; capability cleanup on uninstall designed explicitly.

## 7. Test and CI

Unit + integration WordPress-only foundations; doc-link checker; PHPCS/PHPStan when PHP exists.

## 8. Work packages

1. Plugin header/bootstrap + autoload  
2. Core composition + capabilities  
3. Persistence migration framework  
4. Vault abstraction  
5. Privacy/audit stubs  
6. Test/CI scaffolding  
7. Developer docs update  

## 9. Risks

- Over-building inert modules — mitigate by creating boundaries only as empty namespaces/docs until owning milestone.

## 10. Out of scope

Conversations REST, widget, Hub, adapters, migration cutover, AI, visual polish, availability.

## 11. Definition of done

Charter acceptance criteria met; green CI foundations; Product Owner closure.
