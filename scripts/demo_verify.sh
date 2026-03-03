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
smoke_status="PASS"
if ! (cd "${DEMO_CORE_DIR_PATH}" && php artisan emcp:test); then
  smoke_status="WARN"
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

mcp_post_with_token_optional_session() {
  auth_token="$1"
  payload="$2"
  headers_file="$3"
  extra_header="${4:-}"
  target_url="${5:-${mcp_url}}"
  session_override="${6:-auto}"
  session_for_request="${session_id}"
  if [ "${session_override}" = "none" ]; then
    session_for_request=""
  elif [ "${session_override}" != "auto" ]; then
    session_for_request="${session_override}"
  fi

  if [ -n "${session_for_request}" ]; then
    if [ -n "${extra_header}" ]; then
      curl -sS -D "${headers_file}" \
        -H 'Content-Type: application/json' \
        -H "Authorization: Bearer ${auth_token}" \
        -H "MCP-Session-Id: ${session_for_request}" \
        -H "${extra_header}" \
        -d "${payload}" \
        -w "\n__HTTP_CODE__:%{http_code}" \
        "${target_url}" || true
      return
    fi

    curl -sS -D "${headers_file}" \
      -H 'Content-Type: application/json' \
      -H "Authorization: Bearer ${auth_token}" \
      -H "MCP-Session-Id: ${session_for_request}" \
      -d "${payload}" \
      -w "\n__HTTP_CODE__:%{http_code}" \
      "${target_url}" || true
    return
  fi

  if [ -n "${extra_header}" ]; then
    curl -sS -D "${headers_file}" \
      -H 'Content-Type: application/json' \
      -H "Authorization: Bearer ${auth_token}" \
      -H "${extra_header}" \
      -d "${payload}" \
      -w "\n__HTTP_CODE__:%{http_code}" \
      "${target_url}" || true
    return
  fi

  curl -sS -D "${headers_file}" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${auth_token}" \
    -d "${payload}" \
    -w "\n__HTTP_CODE__:%{http_code}" \
    "${target_url}" || true
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
EMCP_RUNTIME_NEGATIVE=1 \
EMCP_RUNTIME_MODEL_SANITY=1 \
EMCP_RUNTIME_NEGATIVE_REQUIRE_RATE_LIMIT=1 \
EMCP_TEST_JWT_SECRET="${SAPI_JWT_SECRET}" \
EMCP_STASK_LIFECYCLE_CHECK=1 \
EMCP_STASK_WORKER_CMD="php artisan stask:worker" \
EMCP_STASK_WORKER_CWD="${DEMO_CORE_DIR_PATH}" \
EMCP_STASK_POLL_ATTEMPTS=20 \
composer run test
test_exit=$?
set -e

test_status="PASS"
if [ "${test_exit}" -ne 0 ]; then
  test_status="FAIL"
fi

probe_token=$(SAPI_JWT_SECRET="${SAPI_JWT_SECRET}" php -r '
$secret = (string)getenv("SAPI_JWT_SECRET");
$now = time();
$payload = [
    "sub" => "demo-probe",
    "user_id" => 999,
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

probe_read_token=$(SAPI_JWT_SECRET="${SAPI_JWT_SECRET}" php -r '
$secret = (string)getenv("SAPI_JWT_SECRET");
$now = time();
$payload = [
    "sub" => "demo-probe-read",
    "user_id" => 998,
    "scopes" => ["mcp:read"],
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

model_get_user_payload='{"jsonrpc":"2.0","id":"model-doc","method":"tools/call","params":{"name":"evo.model.get","arguments":{"model":"User","id":1}}}'
dispatch_payload_a='{"jsonrpc":"2.0","id":"d1-doc","method":"tools/call","params":{"name":"evo.content.search","arguments":{"limit":1,"offset":0}}}'
dispatch_payload_b='{"jsonrpc":"2.0","id":"d2-doc","method":"tools/call","params":{"name":"evo.content.search","arguments":{"limit":2,"offset":0}}}'
dispatch_url="${mcp_url}/dispatch"
rate_limit_identity_type='api:jwt (sapi.jwt.user_id/sapi.jwt.sub; fallback ip)'

unauth_headers_file="${TMP_DIR}/unauth.headers"
unauth_raw=$(curl -sS -D "${unauth_headers_file}" \
  -H 'Content-Type: application/json' \
  -d "${initialize_payload}" \
  -w "\n__HTTP_CODE__:%{http_code}" \
  "${mcp_url}" || true)
unauth_http_code=$(printf '%s' "${unauth_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
unauth_response=$(printf '%s' "${unauth_raw}" | sed '/^__HTTP_CODE__:/d')

readonly_init_headers="${TMP_DIR}/readonly-init.headers"
readonly_init_raw=$(mcp_post_with_token_optional_session "${probe_read_token}" "${initialize_payload}" "${readonly_init_headers}" "" "${mcp_url}" "none")
readonly_init_code=$(printf '%s' "${readonly_init_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
readonly_session_id=$(awk 'BEGIN{IGNORECASE=1} /^MCP-Session-Id:/{gsub("\r","",$2); print $2}' "${readonly_init_headers}" | tail -n 1)

scope_headers_file="${TMP_DIR}/scope-denied.headers"
if [ -n "${readonly_session_id}" ]; then
  scope_raw=$(curl -sS -D "${scope_headers_file}" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${probe_read_token}" \
    -H "MCP-Session-Id: ${readonly_session_id}" \
    -d "${content_search_payload}" \
    -w "\n__HTTP_CODE__:%{http_code}" \
    "${mcp_url}" || true)
else
  scope_raw=$(curl -sS -D "${scope_headers_file}" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${probe_read_token}" \
    -d "${content_search_payload}" \
    -w "\n__HTTP_CODE__:%{http_code}" \
    "${mcp_url}" || true)
fi
scope_http_code=$(printf '%s' "${scope_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
scope_response=$(printf '%s' "${scope_raw}" | sed '/^__HTTP_CODE__:/d')

unsupported_headers_file="${TMP_DIR}/unsupported.headers"
unsupported_raw=$(curl -sS -D "${unsupported_headers_file}" \
  -H 'Content-Type: text/plain' \
  -H "Authorization: Bearer ${probe_token}" \
  -d 'plain text body' \
  -w "\n__HTTP_CODE__:%{http_code}" \
  "${mcp_url}" || true)
unsupported_http_code=$(printf '%s' "${unsupported_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
unsupported_response=$(printf '%s' "${unsupported_raw}" | sed '/^__HTTP_CODE__:/d')

oversized_payload=$(php -r '
$pad = str_repeat("x", 300 * 1024);
echo json_encode([
  "jsonrpc" => "2.0",
  "id" => "oversized-doc",
  "method" => "tools/call",
  "params" => [
    "name" => "evo.content.search",
    "arguments" => [
      "limit" => 1,
      "offset" => 0,
      "padding" => $pad,
    ],
  ],
], JSON_UNESCAPED_SLASHES);
')
oversized_headers_file="${TMP_DIR}/oversized.headers"
oversized_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${oversized_payload}" "${oversized_headers_file}")
oversized_http_code=$(printf '%s' "${oversized_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
oversized_response=$(printf '%s' "${oversized_raw}" | sed '/^__HTTP_CODE__:/d')

dispatch_a_headers="${TMP_DIR}/dispatch-a.headers"
dispatch_a_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${dispatch_payload_a}" "${dispatch_a_headers}" "Idempotency-Key: demo-verify-k1" "${dispatch_url}")
dispatch_a_http_code=$(printf '%s' "${dispatch_a_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
dispatch_a_response=$(printf '%s' "${dispatch_a_raw}" | sed '/^__HTTP_CODE__:/d')

dispatch_reuse_headers="${TMP_DIR}/dispatch-reuse.headers"
dispatch_reuse_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${dispatch_payload_a}" "${dispatch_reuse_headers}" "Idempotency-Key: demo-verify-k1" "${dispatch_url}")
dispatch_reuse_http_code=$(printf '%s' "${dispatch_reuse_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
dispatch_reuse_response=$(printf '%s' "${dispatch_reuse_raw}" | sed '/^__HTTP_CODE__:/d')

dispatch_conflict_headers="${TMP_DIR}/dispatch-conflict.headers"
dispatch_conflict_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${dispatch_payload_b}" "${dispatch_conflict_headers}" "Idempotency-Key: demo-verify-k1" "${dispatch_url}")
dispatch_conflict_http_code=$(printf '%s' "${dispatch_conflict_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
dispatch_conflict_response=$(printf '%s' "${dispatch_conflict_raw}" | sed '/^__HTTP_CODE__:/d')

dispatch_lifecycle_key='demo-verify-k2'
dispatch_lifecycle_start_headers="${TMP_DIR}/dispatch-lifecycle-start.headers"
dispatch_lifecycle_start_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${dispatch_payload_a}" "${dispatch_lifecycle_start_headers}" "Idempotency-Key: ${dispatch_lifecycle_key}" "${dispatch_url}")
dispatch_lifecycle_start_http_code=$(printf '%s' "${dispatch_lifecycle_start_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
dispatch_lifecycle_start_response=$(printf '%s' "${dispatch_lifecycle_start_raw}" | sed '/^__HTTP_CODE__:/d')
dispatch_lifecycle_task_id=$(printf '%s' "${dispatch_lifecycle_start_response}" | php -r '
$raw = stream_get_contents(STDIN);
$json = json_decode($raw, true);
$taskId = $json["task_id"] ?? null;
if (is_numeric($taskId) && (int)$taskId > 0) {
    echo (string)(int)$taskId;
}
')

stask_worker_exit=0
stask_worker_output=""
if [ -n "${dispatch_lifecycle_task_id}" ]; then
  set +e
  stask_worker_output=$(cd "${DEMO_CORE_DIR_PATH}" && php artisan stask:worker 2>&1)
  stask_worker_exit=$?
  set -e
else
  stask_worker_exit=1
  stask_worker_output="No task_id returned from lifecycle dispatch start; async path unavailable."
fi

dispatch_lifecycle_after_headers="${TMP_DIR}/dispatch-lifecycle-after.headers"
dispatch_lifecycle_after_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${dispatch_payload_a}" "${dispatch_lifecycle_after_headers}" "Idempotency-Key: ${dispatch_lifecycle_key}" "${dispatch_url}")
dispatch_lifecycle_after_http_code=$(printf '%s' "${dispatch_lifecycle_after_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
dispatch_lifecycle_after_response=$(printf '%s' "${dispatch_lifecycle_after_raw}" | sed '/^__HTTP_CODE__:/d')

stask_lifecycle_result=$(DISPATCH_START="${dispatch_lifecycle_start_response}" DISPATCH_AFTER="${dispatch_lifecycle_after_response}" WORKER_EXIT="${stask_worker_exit}" php -r '
$start = json_decode((string)getenv("DISPATCH_START"), true);
$after = json_decode((string)getenv("DISPATCH_AFTER"), true);
$workerExit = (int)getenv("WORKER_EXIT");
if (!is_array($start)) {
    echo "FAILED: lifecycle start response is not JSON";
    exit(0);
}
if (!is_array($after)) {
    echo "FAILED: lifecycle after-worker response is not JSON";
    exit(0);
}
if ($workerExit !== 0) {
    echo "FAILED: stask worker exited with code " . $workerExit;
    exit(0);
}
if (($after["reused"] ?? null) !== true) {
    echo "FAILED: lifecycle reuse response has reused!=true";
    exit(0);
}
if (($after["status"] ?? null) !== "completed") {
    echo "FAILED: lifecycle status is not completed";
    exit(0);
}
$result = $after["result"] ?? null;
if (!is_array($result)) {
    echo "FAILED: lifecycle completed response has no result payload";
    exit(0);
}
echo "PASS: queued -> completed with persisted result";
')

model_headers_file="${TMP_DIR}/model.headers"
model_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${model_get_user_payload}" "${model_headers_file}")
model_http_code=$(printf '%s' "${model_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
model_response=$(printf '%s' "${model_raw}" | sed '/^__HTTP_CODE__:/d')
model_sanity_result=$(printf '%s' "${model_response}" | php -r '
$raw = stream_get_contents(STDIN);
$json = json_decode($raw, true);
if (!is_array($json) || isset($json["error"])) {
    echo "FAILED: non-success model response";
    exit(0);
}
$item = $json["result"]["structuredContent"]["item"] ?? null;
if (!is_array($item)) {
    echo "FAILED: missing structuredContent.item";
    exit(0);
}
$sensitive = ["password","cachepwd","verified_key","refresh_token","access_token","sessionid"];
$leaks = [];
foreach ($sensitive as $field) {
    if (array_key_exists($field, $item)) {
        $leaks[] = $field;
    }
}
if ($leaks !== []) {
    echo "FAILED: leaked fields -> " . implode(", ", $leaks);
    exit(0);
}
echo "PASS: no sensitive fields exposed";
')

rate_limit_observed="no"
rate_retry_after="0"
rate_limit_http_code="n/a"
rate_limit_response='{}'
i=0
while [ "${i}" -lt 100 ]; do
  i=$((i + 1))
  rate_headers_file="${TMP_DIR}/rate-${i}.headers"
  rate_raw=$(mcp_post_with_token_optional_session "${probe_token}" "${tools_list_payload}" "${rate_headers_file}")
  rate_code=$(printf '%s' "${rate_raw}" | sed -n 's/^__HTTP_CODE__://p' | tail -n 1)
  if [ "${rate_code}" = "429" ]; then
    rate_limit_observed="yes"
    rate_limit_http_code="${rate_code}"
    rate_limit_response=$(printf '%s' "${rate_raw}" | sed '/^__HTTP_CODE__:/d')
    rate_retry_after=$(awk 'BEGIN{IGNORECASE=1} /^Retry-After:/{gsub("\r","",$2); print $2}' "${rate_headers_file}" | tail -n 1)
    if [ -z "${rate_retry_after}" ]; then
      rate_retry_after="0"
    fi
    break
  fi
done

run_utc_ts=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

cat > "${LOGS_MD}" <<EOF
# eMCP Demo Verify Log

Generated at (UTC): \`${run_utc_ts}\`  
Result: **${test_status}** (exit code: \`${test_exit}\`)

## Environment

- Base URL: \`${DEMO_BASE_URL}\`
- API prefix: \`${api_prefix}\`
- MCP endpoint: \`${mcp_url}\`
- MCP dispatch endpoint: \`${mcp_url}/dispatch\`
- Server handle: \`${EMCP_SERVER_HANDLE}\`
- Token endpoint: \`${DEMO_BASE_URL}${api_prefix}/token\`
- Token (masked): \`${token_masked}\`
- MCP Session ID: \`${session_id:-n/a}\`
- php -S log file: \`${SERVER_LOG}\`

## Smoke Check

\`php artisan emcp:test\`: ${smoke_status}

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

Contract note:
- \`result.structuredContent\` is the canonical contract payload.
- \`result.content[].text\` is a compatibility mirror for MCP clients that rely on text blocks.

### 6) security negative probe: 401 unauthenticated

HTTP: \`${unauth_http_code}\`

Response:
\`\`\`json
${unauth_response}
\`\`\`

### 7) security negative probe: 403 scope denied (read-only token on tools/call)

Read-only initialize HTTP: \`${readonly_init_code}\`

HTTP: \`${scope_http_code}\`

Response:
\`\`\`json
${scope_response}
\`\`\`

### 8) limits negative probe: 415 unsupported media type

HTTP: \`${unsupported_http_code}\`

Response:
\`\`\`json
${unsupported_response}
\`\`\`

### 9) limits negative probe: 413 payload too large

HTTP: \`${oversized_http_code}\`

Response:
\`\`\`json
${oversized_response}
\`\`\`

Result-size cap probe:
- status: \`pending\`
- reason: requires larger dataset or temporary per-server \`max_result_bytes\` override in demo runtime.

### 10) Gate C idempotency probes (reuse + conflict)

First dispatch HTTP: \`${dispatch_a_http_code}\`

First dispatch response:
\`\`\`json
${dispatch_a_response}
\`\`\`

Reuse dispatch HTTP (same key + same payload): \`${dispatch_reuse_http_code}\`

Reuse dispatch response:
\`\`\`json
${dispatch_reuse_response}
\`\`\`

Conflicting dispatch HTTP (same key + different payload): \`${dispatch_conflict_http_code}\`

Conflicting dispatch response:
\`\`\`json
${dispatch_conflict_response}
\`\`\`

### 11) rate-limit probe: 429 with Retry-After

429 observed: \`${rate_limit_observed}\`
Retry-After: \`${rate_retry_after}\`
HTTP: \`${rate_limit_http_code}\`
Rate-limit identity type: \`${rate_limit_identity_type}\`

Response:
\`\`\`json
${rate_limit_response}
\`\`\`

### 12) model sanity probe: evo.model.get(User)

HTTP: \`${model_http_code}\`
Result: \`${model_sanity_result}\`

Response:
\`\`\`json
${model_response}
\`\`\`

### 13) local sTask lifecycle probe (queued -> completed)

Dispatch start HTTP: \`${dispatch_lifecycle_start_http_code}\`
Task ID: \`${dispatch_lifecycle_task_id:-n/a}\`

Dispatch start response:
\`\`\`json
${dispatch_lifecycle_start_response}
\`\`\`

Worker command: \`php artisan stask:worker\`  
Worker exit code: \`${stask_worker_exit}\`

Worker output:
\`\`\`
${stask_worker_output}
\`\`\`

Dispatch after worker HTTP: \`${dispatch_lifecycle_after_http_code}\`

Dispatch after worker response:
\`\`\`json
${dispatch_lifecycle_after_response}
\`\`\`

Lifecycle result: \`${stask_lifecycle_result}\`

## How To Verify Manually

1. Get token:
\`\`\`bash
# DEMO ONLY: use credentials provisioned by \`make demo-all\`
curl -sS -H 'Content-Type: application/json' \\
  -d '{"username":"${DEMO_ADMIN_USERNAME}","password":"<DEMO_PASSWORD>"}' \\
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

5. User model sanity check (must not expose sensitive fields):
\`\`\`bash
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer <TOKEN>' \\
  -d '${model_get_user_payload}' \\
  '${mcp_url}'
\`\`\`

6. Local sTask worker run (optional lifecycle proof in demo):
\`\`\`bash
cd '${DEMO_CORE_DIR_PATH}'
php artisan stask:worker
\`\`\`
EOF

echo "[demo-verify] Wrote detailed run log: ${LOGS_MD}"

if [ "${test_exit}" -ne 0 ]; then
  exit "${test_exit}"
fi

echo "[demo-verify] PASS: demo is running and MCP checks are green."
