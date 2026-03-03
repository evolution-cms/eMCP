#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)
cd "${REPO_ROOT}"

DEMO_DIR=${DEMO_DIR:-demo}
ARTIFACT_LOG_PATH=${CLEAN_INSTALL_LOG_PATH:-${DEMO_DIR}/clean-install.log}
RUNTIME_LOG_PATH=${CLEAN_INSTALL_RUNTIME_LOG_PATH:-/tmp/emcp-clean-install.log}

mkdir -p "$(dirname "${RUNTIME_LOG_PATH}")"
: > "${RUNTIME_LOG_PATH}"

sync_log_artifact() {
  if [ -n "${ARTIFACT_LOG_PATH}" ]; then
    mkdir -p "$(dirname "${ARTIFACT_LOG_PATH}")"
    cp "${RUNTIME_LOG_PATH}" "${ARTIFACT_LOG_PATH}" 2>/dev/null || true
  fi
}

log() {
  printf '%s\n' "$1" >>"${RUNTIME_LOG_PATH}"
}

run_step() {
  step_name="$1"
  shift

  log "[clean-install] ${step_name}"
  if "$@" >>"${RUNTIME_LOG_PATH}" 2>&1; then
    log "[clean-install] ${step_name}: PASS"
    return 0
  fi

  log "[clean-install] ${step_name}: FAIL"
  return 1
}

if ! run_step "demo-clean" make demo-clean; then
  sync_log_artifact
  echo "[clean-install] demo-clean failed. See ${RUNTIME_LOG_PATH}" >&2
  tail -n 120 "${RUNTIME_LOG_PATH}" >&2 || true
  exit 1
fi

if ! run_step "demo-install" make demo; then
  sync_log_artifact
  echo "[clean-install] demo install failed. See ${RUNTIME_LOG_PATH}" >&2
  tail -n 120 "${RUNTIME_LOG_PATH}" >&2 || true
  exit 1
fi

if ! run_step "demo-verify" make demo-verify; then
  sync_log_artifact
  echo "[clean-install] demo verify failed. See ${RUNTIME_LOG_PATH}" >&2
  tail -n 120 "${RUNTIME_LOG_PATH}" >&2 || true
  exit 1
fi

if ! grep -F "[demo-verify] PASS:" "${RUNTIME_LOG_PATH}" >/dev/null 2>&1; then
  sync_log_artifact
  echo "[clean-install] PASS marker from demo-verify not found in ${RUNTIME_LOG_PATH}" >&2
  tail -n 120 "${RUNTIME_LOG_PATH}" >&2 || true
  exit 1
fi

sync_log_artifact
echo "[clean-install] PASS (log: ${RUNTIME_LOG_PATH})"
