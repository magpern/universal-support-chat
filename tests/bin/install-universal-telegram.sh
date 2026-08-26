#!/usr/bin/env bash
# Installs the REAL universal-telegram plugin source (from the sibling
# checkout mounted at /universal-telegram by
# docker/docker-compose.interop.yml, on its merged main branch) into the
# WordPress core plugin directory prepared by tests/bin/install-wp.sh, and
# runs its own `composer install` so its own vendor/autoload.php exists.
# Mirrors the WooCommerce-zip mechanism in install-wp.sh, except the
# source is a local checkout, not a downloaded release, per the interop
# harness's own constraint (real merged plugin code, not a fixture) — the
# identical pattern Universal Telegram's own
# tests/bin/install-support-chat.sh already established for the reverse
# direction.
#
# Usage: tests/bin/install-universal-telegram.sh
# Requires: WP_CORE_DIR exported (source /tmp/usc-wp-env.sh first).
set -euo pipefail

: "${WP_CORE_DIR:?WP_CORE_DIR is not set. Run tests/bin/install-wp.sh and source /tmp/usc-wp-env.sh first.}"

UT_SRC="${UT_SRC:-/universal-telegram}"
UT_DEST="${WP_CORE_DIR}/wp-content/plugins/universal-telegram"

if [ ! -f "${UT_SRC}/universal-telegram.php" ]; then
    echo "Universal Telegram source not found at ${UT_SRC} (expected the sibling checkout mounted by docker-compose.interop.yml)." >&2
    exit 1
fi

if [ ! -d "${UT_SRC}/vendor" ]; then
    echo "Installing Universal Telegram's own Composer dependencies..."
    composer install --no-interaction --no-progress --working-dir="${UT_SRC}"
fi

mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
rm -f "${UT_DEST}"
ln -s "${UT_SRC}" "${UT_DEST}"

echo "Universal Telegram linked into ${UT_DEST} -> ${UT_SRC}"
