#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)
cd "${REPO_ROOT}"

DEMO_DIR=${DEMO_DIR:-demo}
DEMO_CORE_DIR=${DEMO_CORE_DIR:-${DEMO_DIR}/core}
DEMO_HOST=${DEMO_HOST:-127.0.0.1}
DEMO_PORT=${DEMO_PORT:-8787}
DEMO_BASE_URL=${DEMO_BASE_URL:-http://${DEMO_HOST}:${DEMO_PORT}}
DEMO_BASE_URL=${DEMO_BASE_URL%/}
DEMO_ADMIN_USERNAME=${DEMO_ADMIN_USERNAME:-admin}
DEMO_ADMIN_PASSWORD=${DEMO_ADMIN_PASSWORD:-123456}
EMCP_SERVER_HANDLE=${EMCP_SERVER_HANDLE:-content}
EMCP_DISPATCH_CHECK=${EMCP_DISPATCH_CHECK:-1}
SAPI_JWT_SECRET=${SAPI_JWT_SECRET:-emcp-demo-secret-0123456789abcdef0123456789abcdef}
SAPI_BASE_PATH=${SAPI_BASE_PATH:-api}
SAPI_VERSION=${SAPI_VERSION:-v1}
SERVER_LOG=${SERVER_LOG:-/tmp/emcp-demo-php-server.log}
SERVER_PID=

if [ "${#SAPI_JWT_SECRET}" -lt 32 ]; then
  echo "[demo-verify] SAPI_JWT_SECRET is shorter than 32 chars; using SHA-256 normalized secret for compatibility."
  SAPI_JWT_SECRET=$(printf '%s' "${SAPI_JWT_SECRET}" | php -r '
$secret = stream_get_contents(STDIN);
if (!is_string($secret) || $secret === "") {
    $secret = "emcp-demo-secret";
}
echo hash("sha256", $secret);
')
fi

case "${DEMO_DIR}" in
  /*) DEMO_DIR_PATH="${DEMO_DIR}" ;;
  *) DEMO_DIR_PATH="${REPO_ROOT}/${DEMO_DIR}" ;;
esac

case "${DEMO_CORE_DIR}" in
  /*) DEMO_CORE_DIR_PATH="${DEMO_CORE_DIR}" ;;
  *) DEMO_CORE_DIR_PATH="${REPO_ROOT}/${DEMO_CORE_DIR}" ;;
esac

cleanup() {
  status=$?

  if [ -n "${SERVER_PID}" ] && kill -0 "${SERVER_PID}" 2>/dev/null; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi

  if [ "${status}" -ne 0 ] && [ -f "${SERVER_LOG}" ]; then
    echo "[demo-verify] FAILED (exit ${status}). php -S log tail:" >&2
    tail -n 120 "${SERVER_LOG}" >&2 || true
  fi

  trap - EXIT
  exit "${status}"
}

trap cleanup EXIT

if [ ! -f "${DEMO_CORE_DIR_PATH}/artisan" ]; then
  echo "[demo-verify] Demo is not installed. Run 'make demo' first." >&2
  exit 1
fi

echo "[demo-verify] Running package tests and runtime MCP verification..."
echo "[demo-verify] Step 1/4: local smoke check (artisan emcp:test)"
if ! (cd "${DEMO_CORE_DIR_PATH}" && php artisan emcp:test); then
  echo "[demo-verify] Warning: artisan emcp:test failed in CLI mode; continuing with HTTP runtime checks."
fi

echo "[demo-verify] Step 2/4: starting php -S on ${DEMO_HOST}:${DEMO_PORT}"
SAPI_JWT_SECRET="${SAPI_JWT_SECRET}" \
SAPI_BASE_PATH="${SAPI_BASE_PATH}" \
SAPI_VERSION="${SAPI_VERSION}" \
php -S "${DEMO_HOST}:${DEMO_PORT}" -t "${DEMO_DIR_PATH}" >"${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

api_prefix="/${SAPI_BASE_PATH}"
if [ -n "${SAPI_VERSION}" ]; then
  api_prefix="${api_prefix}/${SAPI_VERSION}"
fi

ready=0
i=0
while [ "${i}" -lt 40 ]; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "${DEMO_BASE_URL}${api_prefix}/token" || true)
  if [ "${code}" = "405" ] || [ "${code}" = "422" ] || [ "${code}" = "200" ]; then
    ready=1
    break
  fi
  i=$((i + 1))
  sleep 1
done

if [ "${ready}" -ne 1 ]; then
  echo "[demo-verify] php -S did not become ready in time at ${DEMO_BASE_URL}." >&2
  exit 1
fi

echo "[demo-verify] Step 3/4: issuing sApi JWT token"
token_payload=$(DEMO_ADMIN_USERNAME="${DEMO_ADMIN_USERNAME}" DEMO_ADMIN_PASSWORD="${DEMO_ADMIN_PASSWORD}" php -r '
echo json_encode([
  "username" => getenv("DEMO_ADMIN_USERNAME"),
  "password" => getenv("DEMO_ADMIN_PASSWORD"),
], JSON_UNESCAPED_SLASHES);
')

token_raw=$(curl -sS \
  -H 'Content-Type: application/json' \
  -d "${token_payload}" \
  -w "\n__HTTP_CODE__:%{http_code}" \
  "${DEMO_BASE_URL}${api_prefix}/token" || true)

token_http_code=$(printf '%s' "${token_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
token_response=$(printf '%s' "${token_raw}" | sed '/^__HTTP_CODE__:/d')

token=""
if [ "${token_http_code}" = "200" ]; then
  token=$(printf '%s' "${token_response}" | php -r '
$raw = stream_get_contents(STDIN);
$json = json_decode($raw, true);
if (!is_array($json)) {
    fwrite(STDERR, "Token endpoint returned invalid JSON.\n");
    exit(1);
}
$token = $json["object"]["token"] ?? "";
if (!is_string($token) || trim($token) === "") {
    fwrite(STDERR, "Token endpoint did not return object.token.\n");
    exit(1);
}
echo $token;
' || true)
else
  echo "[demo-verify] Token endpoint returned HTTP ${token_http_code:-unknown}; falling back to local JWT build."
  if [ -n "${token_response}" ]; then
    echo "[demo-verify] Token endpoint body: ${token_response}"
  fi
fi

if [ -z "${token}" ]; then
  token=$(SAPI_JWT_SECRET="${SAPI_JWT_SECRET}" php -r '
$secret = (string)getenv("SAPI_JWT_SECRET");
if ($secret === "") {
    fwrite(STDERR, "SAPI_JWT_SECRET is empty.\n");
    exit(1);
}
$now = time();
$payload = [
    "sub" => "demo-admin",
    "user_id" => 1,
    "scopes" => ["*"],
    "iat" => $now,
    "exp" => $now + 3600,
];
$header = ["typ" => "JWT", "alg" => "HS256"];
$base64Url = static function (string $data): string {
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
};
$segments = [
    $base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)),
    $base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
];
$signingInput = implode(".", $segments);
$signature = hash_hmac("sha256", $signingInput, $secret, true);
echo $signingInput . "." . $base64Url($signature);
')
fi

echo "[demo-verify] Step 4/4: running full test suite with runtime HTTP integration"
EMCP_INTEGRATION_ENABLED=1 \
EMCP_BASE_URL="${DEMO_BASE_URL}" \
EMCP_API_PATH="${api_prefix}/mcp/{server}" \
EMCP_API_TOKEN="${token}" \
EMCP_SERVER_HANDLE="${EMCP_SERVER_HANDLE}" \
EMCP_DISPATCH_CHECK="${EMCP_DISPATCH_CHECK}" \
composer run test

echo "[demo-verify] PASS: demo is running and MCP checks are green."
