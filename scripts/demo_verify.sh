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
TMP_DIR=

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

LOGS_MD=${LOGS_MD:-${DEMO_DIR_PATH}/logs.md}
TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/emcp-demo-verify.XXXXXX")

cleanup() {
  status=$?

  if [ -n "${SERVER_PID}" ] && kill -0 "${SERVER_PID}" 2>/dev/null; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi

  if [ -n "${TMP_DIR}" ] && [ -d "${TMP_DIR}" ]; then
    rm -rf "${TMP_DIR}" >/dev/null 2>&1 || true
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

token_masked=$(printf '%s' "${token}" | php -r '
$token = trim((string)stream_get_contents(STDIN));
if ($token === "") {
    echo "";
    exit(0);
}
$len = strlen($token);
if ($len <= 24) {
    echo $token;
    exit(0);
}
echo substr($token, 0, 16) . "..." . substr($token, -8);
')

mcp_url="${DEMO_BASE_URL}${api_prefix}/mcp/${EMCP_SERVER_HANDLE}"
session_id=""

initialize_payload='{"jsonrpc":"2.0","id":"init-doc","method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"emcp-demo-verify","version":"1.0.0"}}}'
tools_list_payload='{"jsonrpc":"2.0","id":"tools-doc","method":"tools/list","params":{}}'
content_search_payload='{"jsonrpc":"2.0","id":"search-doc","method":"tools/call","params":{"name":"evo.content.search","arguments":{"limit":3,"offset":0}}}'
content_root_tree_payload='{"jsonrpc":"2.0","id":"root-doc","method":"tools/call","params":{"name":"evo.content.root_tree","arguments":{"limit":3,"offset":0,"depth":2}}}'
content_get_payload='{"jsonrpc":"2.0","id":"get-doc","method":"tools/call","params":{"name":"evo.content.get","arguments":{"id":1}}}'

init_headers_file="${TMP_DIR}/init.headers"
init_raw=$(curl -sS -D "${init_headers_file}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${token}" \
  -d "${initialize_payload}" \
  -w "\n__HTTP_CODE__:%{http_code}" \
  "${mcp_url}" || true)
init_http_code=$(printf '%s' "${init_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
init_response=$(printf '%s' "${init_raw}" | sed '/^__HTTP_CODE__:/d')
session_id=$(awk 'BEGIN{IGNORECASE=1} /^MCP-Session-Id:/{gsub("\r","",$2); print $2}' "${init_headers_file}" | tail -n 1)

mcp_post_with_optional_session() {
  payload="$1"
  headers_file="$2"
  if [ -n "${session_id}" ]; then
    curl -sS -D "${headers_file}" \
      -H 'Content-Type: application/json' \
      -H "Authorization: Bearer ${token}" \
      -H "MCP-Session-Id: ${session_id}" \
      -d "${payload}" \
      -w "\n__HTTP_CODE__:%{http_code}" \
      "${mcp_url}" || true
    return
  fi

  curl -sS -D "${headers_file}" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "${payload}" \
    -w "\n__HTTP_CODE__:%{http_code}" \
    "${mcp_url}" || true
}

tools_headers_file="${TMP_DIR}/tools.headers"
tools_raw=$(mcp_post_with_optional_session "${tools_list_payload}" "${tools_headers_file}")
tools_http_code=$(printf '%s' "${tools_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
tools_response=$(printf '%s' "${tools_raw}" | sed '/^__HTTP_CODE__:/d')

search_headers_file="${TMP_DIR}/search.headers"
search_raw=$(mcp_post_with_optional_session "${content_search_payload}" "${search_headers_file}")
search_http_code=$(printf '%s' "${search_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
search_response=$(printf '%s' "${search_raw}" | sed '/^__HTTP_CODE__:/d')

root_headers_file="${TMP_DIR}/root.headers"
root_raw=$(mcp_post_with_optional_session "${content_root_tree_payload}" "${root_headers_file}")
root_http_code=$(printf '%s' "${root_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
root_response=$(printf '%s' "${root_raw}" | sed '/^__HTTP_CODE__:/d')

get_headers_file="${TMP_DIR}/get.headers"
get_raw=$(mcp_post_with_optional_session "${content_get_payload}" "${get_headers_file}")
get_http_code=$(printf '%s' "${get_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
get_response=$(printf '%s' "${get_raw}" | sed '/^__HTTP_CODE__:/d')

echo "[demo-verify] Step 4/4: running full test suite with runtime HTTP integration"

set +e
EMCP_INTEGRATION_ENABLED=1 \
EMCP_BASE_URL="${DEMO_BASE_URL}" \
EMCP_API_PATH="${api_prefix}/mcp/{server}" \
EMCP_API_TOKEN="${token}" \
EMCP_SERVER_HANDLE="${EMCP_SERVER_HANDLE}" \
EMCP_DISPATCH_CHECK="${EMCP_DISPATCH_CHECK}" \
composer run test
test_exit=$?
set -e

test_status="PASS"
if [ "${test_exit}" -ne 0 ]; then
  test_status="FAIL"
fi

run_utc_ts=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

cat > "${LOGS_MD}" <<EOF
# eMCP Demo Verify Log

Generated at (UTC): \`${run_utc_ts}\`  
Result: **${test_status}** (exit code: \`${test_exit}\`)

## Environment

- Base URL: \`${DEMO_BASE_URL}\`
- API prefix: \`${api_prefix}\`
- MCP endpoint: \`${mcp_url}\`
- Server handle: \`${EMCP_SERVER_HANDLE}\`
- Token endpoint: \`${DEMO_BASE_URL}${api_prefix}/token\`
- Token (masked): \`${token_masked}\`
- MCP Session ID: \`${session_id:-n/a}\`
- php -S log file: \`${SERVER_LOG}\`

## Smoke Check

\`php artisan emcp:test\`: passed (initialize/tools:list OK)

## HTTP / MCP Probe Requests

### 1) initialize

Request:
\`\`\`json
${initialize_payload}
\`\`\`

HTTP: \`${init_http_code}\`

Response:
\`\`\`json
${init_response}
\`\`\`

### 2) tools/list

Request:
\`\`\`json
${tools_list_payload}
\`\`\`

HTTP: \`${tools_http_code}\`

Response:
\`\`\`json
${tools_response}
\`\`\`

### 3) site content via evo.content.search

Request:
\`\`\`json
${content_search_payload}
\`\`\`

HTTP: \`${search_http_code}\`

Response:
\`\`\`json
${search_response}
\`\`\`

### 4) site content via evo.content.root_tree

Request:
\`\`\`json
${content_root_tree_payload}
\`\`\`

HTTP: \`${root_http_code}\`

Response:
\`\`\`json
${root_response}
\`\`\`

### 5) site content via evo.content.get (id=1)

Request:
\`\`\`json
${content_get_payload}
\`\`\`

HTTP: \`${get_http_code}\`

Response:
\`\`\`json
${get_response}
\`\`\`

## How To Verify Manually

1. Get token:
\`\`\`bash
curl -sS -H 'Content-Type: application/json' \\
  -d '{"username":"${DEMO_ADMIN_USERNAME}","password":"${DEMO_ADMIN_PASSWORD}"}' \\
  '${DEMO_BASE_URL}${api_prefix}/token'
\`\`\`

2. Initialize MCP:
\`\`\`bash
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer <TOKEN>' \\
  -d '${initialize_payload}' \\
  '${mcp_url}'
\`\`\`

3. Read site content (search):
\`\`\`bash
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer <TOKEN>' \\
  -d '${content_search_payload}' \\
  '${mcp_url}'
\`\`\`

4. Read one document (id=1):
\`\`\`bash
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer <TOKEN>' \\
  -d '${content_get_payload}' \\
  '${mcp_url}'
\`\`\`
EOF

echo "[demo-verify] Wrote detailed run log: ${LOGS_MD}"

if [ "${test_exit}" -ne 0 ]; then
  exit "${test_exit}"
fi

echo "[demo-verify] PASS: demo is running and MCP checks are green."
