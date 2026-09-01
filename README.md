# Universal Support Chat

Self-contained WordPress support-chat plugin: website widget, conversations, tickets, waiting queue, WordPress Hub inbox and operator replies, support hours, privacy controls, and future chat AI.

**Universal Support Chat must work fully without Universal Telegram.** Telegram (or any channel) is an optional adapter for escalated operator workflows only.

## Status

**SC-M02 Widget and Hub Replies** — minimal authenticated visitor widget and WordPress Hub inbox with Support-team replies. Works without Universal Telegram.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- No hard dependency on WooCommerce or Universal Telegram

## Development

This repository is developed through Docker; host PHP/Composer are not required.

```
bin/docker/composer.sh install --no-interaction
bin/docker/phpcs.sh
bin/docker/phpstan.sh
bin/docker/test-unit.sh
bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1
bin/docker/composer.sh run-script check-doc-links
bin/docker/build-release-package.sh          # build the deployable plugin ZIP + checksum
```

## Releases

Tag-triggered: pushing `vX.Y.Z` on `main` builds `universal-support-chat-<version>.zip`
+ `.zip.sha256` in CI and publishes them as GitHub Release assets. Nothing
generated is committed. Full procedure — including the canonical version
source, package contents, and recovery steps — is in
[`docs/RELEASE.md`](docs/RELEASE.md).

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
- Release process: [`docs/RELEASE.md`](docs/RELEASE.md)

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
