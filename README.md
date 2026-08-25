# Universal Support Chat

Self-contained WordPress support-chat plugin: website widget, conversations, tickets, waiting queue, WordPress Hub inbox and operator replies, support hours, privacy controls, and future chat AI.

**Universal Support Chat must work fully without Universal Telegram.** Telegram (or any channel) is an optional adapter for escalated operator workflows only. Creating, storing, replying to, or resolving a support conversation must never require Telegram.

## Status

Foundation **documentation freeze**. Runtime plugin code is not present yet. Implementation begins only after frozen milestone plans and the approved program sequence (Support Chat docs → Universal Telegram supersession/adapter docs → SC-M00+ / Adapter M1 code).

## Requirements (planned runtime)

- WordPress 6.9+ (target; confirmed in SC-M00)
- PHP 8.1+ (target; confirmed in SC-M00)
- No hard dependency on WooCommerce or Universal Telegram

## Documentation

- Governance: [`docs/governance.md`](docs/governance.md)
- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Master plan and **R1–R7** product requirements: [`docs/master-plan.md`](docs/master-plan.md)
- Milestone charters: [`docs/milestones/`](docs/milestones/)
- Architecture decision records: [`docs/adr/`](docs/adr/)
- Implementation plans: [`docs/plans/`](docs/plans/)
- Canonical Contract v1: [`docs/adr/0005-canonical-support-channel-contract-v1.md`](docs/adr/0005-canonical-support-channel-contract-v1.md)
- Test strategy: [`docs/testing/`](docs/testing/)
- Closure records: [`docs/closure/`](docs/closure/)

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
