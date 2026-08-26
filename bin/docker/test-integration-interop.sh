#!/usr/bin/env bash
# Runs the SC<->UT cross-plugin interoperability harness
# (tests/integration/Interop): a real, disposable WordPress install with
# BOTH plugins' real source loaded (universal-support-chat from this
# checkout, universal-telegram from the sibling checkout mounted by
# docker/docker-compose.interop.yml), proving real interop against
# Universal Telegram's actual, merged `LegacyExportServiceV1` (ADR-0008).
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

wp_version="$(sc_parse_flag --wp-version "$@")"
wp_version="${wp_version:-6.9}"

php_version="$(sc_parse_flag --php-version "$@")"
php_version="${php_version:-8.1}"

sc_compose_run_interop "$php_version" bash -c "
    set -euo pipefail
    tests/bin/install-wp.sh '${wp_version}'
    source /tmp/usc-wp-env.sh
    tests/bin/install-universal-telegram.sh
    vendor/bin/phpunit -c phpunit-interop.xml.dist
"
