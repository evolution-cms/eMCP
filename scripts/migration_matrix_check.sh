#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)
cd "${REPO_ROOT}"

DB_DRIVER=${1:-sqlite}

case "${DB_DRIVER}" in
  sqlite)
    EVO_DB_TYPE="sqlite"
    EVO_DB_NAME="database.sqlite"
    EVO_DB_HOST=""
    EVO_DB_PORT=""
    EVO_DB_USER=""
    EVO_DB_PASSWORD=""
    ;;
  mysql)
    EVO_DB_TYPE="mysql"
    EVO_DB_NAME="${EVO_DB_NAME:-emcp_demo}"
    EVO_DB_HOST="${EVO_DB_HOST:-127.0.0.1}"
    EVO_DB_PORT="${EVO_DB_PORT:-3306}"
    EVO_DB_USER="${EVO_DB_USER:-emcp}"
    EVO_DB_PASSWORD="${EVO_DB_PASSWORD:-emcp}"
    ;;
  pgsql)
    EVO_DB_TYPE="pgsql"
    EVO_DB_NAME="${EVO_DB_NAME:-emcp_demo}"
    EVO_DB_HOST="${EVO_DB_HOST:-127.0.0.1}"
    EVO_DB_PORT="${EVO_DB_PORT:-5432}"
    EVO_DB_USER="${EVO_DB_USER:-emcp}"
    EVO_DB_PASSWORD="${EVO_DB_PASSWORD:-emcp}"
    ;;
  *)
    echo "[migration-matrix] Unsupported DB driver: ${DB_DRIVER}" >&2
    echo "Usage: scripts/migration_matrix_check.sh [sqlite|mysql|pgsql]" >&2
    exit 1
    ;;
esac

echo "[migration-matrix] Running migration matrix check for driver=${DB_DRIVER}"

make demo-clean

EVO_DB_TYPE="${EVO_DB_TYPE}" \
EVO_DB_NAME="${EVO_DB_NAME}" \
EVO_DB_HOST="${EVO_DB_HOST}" \
EVO_DB_PORT="${EVO_DB_PORT}" \
EVO_DB_USER="${EVO_DB_USER}" \
EVO_DB_PASSWORD="${EVO_DB_PASSWORD}" \
make demo

EMCP_MIGRATION_CHECK_ENABLED=1 EMCP_CORE_DIR="${REPO_ROOT}/demo/core" composer run test:integration:migrations

echo "[migration-matrix] PASS for driver=${DB_DRIVER}"
