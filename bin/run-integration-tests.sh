#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INTEGRATION_DIR="${ROOT_DIR}/tests/integration"
WP_VERSION="${WP_VERSION:-7.0.1}"
WP_CORE_DIR="${WP_CORE_DIR:-${TMPDIR:-/tmp}/muster-wordpress-${WP_VERSION}}"

export WP_CORE_DIR

if [[ ! -f "${WP_CORE_DIR}/wp-settings.php" ]]; then
    if ! command -v wp >/dev/null 2>&1; then
        echo "WP-CLI is required to download WordPress ${WP_VERSION}." >&2
        exit 1
    fi

    wp core download --version="${WP_VERSION}" --path="${WP_CORE_DIR}" --skip-content --force
fi

composer install --working-dir="${INTEGRATION_DIR}" --no-interaction --prefer-dist
"${INTEGRATION_DIR}/vendor/bin/phpunit" -c "${INTEGRATION_DIR}/phpunit.xml.dist"
