#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

php_version="$(sc_parse_flag --php-version "$@")"
php_version="${php_version:-8.1}"

sc_compose_run "$php_version" vendor/bin/phpunit -c phpunit.xml.dist
