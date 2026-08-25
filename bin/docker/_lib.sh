#!/usr/bin/env bash
set -euo pipefail

SC_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SC_COMPOSE_FILE="${SC_REPO_ROOT}/docker/docker-compose.yml"

sc_parse_flag() {
    local flag="$1"
    shift
    for arg in "$@"; do
        if [[ "$arg" == "${flag}="* ]]; then
            echo "${arg#${flag}=}"
            return 0
        fi
    done
    echo ""
}

sc_compose_run() {
    local php_version="${1:-8.1}"
    shift
    PHP_VERSION="$php_version" docker compose -f "$SC_COMPOSE_FILE" run --rm php "$@"
}
