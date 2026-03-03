# OPERATIONS — eMCP Runbook

This runbook is for operators of eMCP environments.
It complements `DOCS.md`/`DOCS.uk.md` with day-2 operational checks.

## 0) Runtime Status Marker
- Target profile: `SPEC 1.0-contract`.
- Current runtime baseline: `Gate C baseline implemented`.
- Meaning: manager/API transport path and async dispatch endpoints are implemented; production hardening and full integration coverage remain in progress.

## 0.1) Known Runtime Limitations (Post-baseline)
- Full async behavior depends on installed/healthy `sTask` tables and worker runtime.
- If `queue.driver=stask` is unavailable, dispatch follows configured failover (`sync|fail`).
- Current test contour validates contract/structure; full end-to-end integration matrix is still pending.

## 1) Mode Profiles

## 1.1 Manager-only profile
Set in `core/custom/config/cms/settings/eMCP.php`:

```php
'mode' => [
    'internal' => true,
    'api' => false,
],
```

Expected:
- manager endpoint works: `POST /{manager_prefix}/{server}`.
- API endpoint disabled.

## 1.2 API-only profile
Set:

```php
'mode' => [
    'internal' => false,
    'api' => true,
],
```

Expected:
- API endpoint works with JWT scopes.
- manager endpoint should be considered non-operational for external consumers.

## 1.3 Hybrid profile (recommended default)
Set:

```php
'mode' => [
    'internal' => true,
    'api' => true,
],
```

Expected:
- internal manager flow and external API flow both work.

## 2) Health Checks

## 2.1 Registry and smoke checks
Run:

```bash
php artisan emcp:list-servers
php artisan emcp:test
```

Expected:
- at least one enabled server is listed.
- `emcp:test` passes `initialize` + `tools/list` checks.

## 2.2 API JWT access check
Call:

```bash
curl -i -X POST http://localhost/<SAPI_BASE_PATH>/<SAPI_VERSION>/mcp/<SERVER_HANDLE> \
  -H 'Authorization: Bearer <JWT>' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"ops","version":"1.0.0"}}}'
```

Expected:
- `200` for valid JWT + scopes.
- `401/403` for missing/invalid JWT or scopes.

## 2.3 Rate-limit check
Send burst requests to one server/method identity.

Expected:
- after configured threshold, response `429` with `Retry-After`.
- behavior follows global/per-server `rate_limit.per_minute`.

## 3) Async / sTask Checks

## 3.1 Worker registration health
Check worker table for `emcp_dispatch`.

Expected target behavior (Gate C):
- worker exists, active, class points to `EvolutionCMS\eMCP\sTask\McpDispatchWorker`.

Current known status:
- runtime provider auto-registers the worker and dispatch path is implemented.
- if `sTask` is missing, dispatch behavior is controlled by `queue.failover`.

## 3.2 Dispatch endpoint check
Call:

```bash
curl -i -X POST http://localhost/<...>/mcp/<SERVER_HANDLE>/dispatch \
  -H 'Authorization: Bearer <JWT>' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: test-key-1' \
  -d '{"method":"tools/call","params":{"name":"evo.content.search","arguments":{"limit":1}}}'
```

Expected target behavior (Gate C):
- accepted async task with task identifier and trace metadata.

Current known status:
- dispatch endpoint is implemented; expected status depends on runtime profile:
- `202` for queued async task.
- `200` for sync failover completion.
- `409` for idempotency conflict.

## 4) Idempotency Conflict Check

Target behavior (Gate C):
- same `Idempotency-Key` + same payload hash -> reuse existing task/result.
- same `Idempotency-Key` + different payload hash -> `409`.

Operational test steps (after Gate C):
1. Submit dispatch A with key `k1`.
2. Submit dispatch B with key `k1` and same payload.
3. Submit dispatch C with key `k1` and modified payload.

Expected:
- A accepted.
- B reused/deduplicated.
- C rejected with `409`.

## 5) Streaming Infrastructure Check

Preconditions:
- `stream.enabled=true` (global or per-server).
- reverse proxy buffering disabled for MCP routes.

Checks:
1. When `stream.enabled=false`, streaming attempt must be rejected.
2. When enabled, response should be SSE (`text/event-stream`).
3. Verify heartbeat cadence and timeout headers.

Expected:
- stream policy follows config constraints (`max_stream_seconds`, `heartbeat_seconds`, `abort_on_disconnect`).

## 6) Incident Triage Quick Map
- `401`: JWT/auth context issue.
- `403`: ACL/scope/policy denial.
- `404`: unknown server handle.
- `405`: wrong HTTP method (GET on MCP transport route).
- `409`: idempotency conflict (Gate C).
- `413`: payload/result too large.
- `415`: unsupported media type.
- `429`: rate limited.
- `500`: internal runtime failure.

## 7) Release-Day Minimum Checklist
1. `composer run check` passes.
2. `make test` passes.
3. `composer run ci:check` passes.
4. `emcp:test` passes against target environment.
5. manager and API initialize checks pass.
6. rate-limit check returns deterministic `429`.
7. security defaults are confirmed (`enable_write_tools=false`, deny-by-default).
8. `composer run test:integration:clean-install` passes and writes `demo/clean-install.log`.
9. migration matrix checks pass (`scripts/migration_matrix_check.sh sqlite|mysql|pgsql`).
10. benchmark artifacts are generated (`composer run benchmark:run` + `composer run benchmark:leaderboard`).

## 8) Ecosystem Interop Quick Path
1. Validate API exposure path (`sApi`):
- obtain JWT with required `mcp:*` scopes.
- call `POST /{SAPI_BASE_PATH}/{SAPI_VERSION}/mcp/{server}`.
2. Validate worker readiness path (`sTask`):
- run `php artisan emcp:sync-workers`.
- confirm `emcp_dispatch` worker registration state.
3. Validate consumer path (`eAi`/`dAi`):
- run `tools/list` and verify canonical tools are visible.
- execute one read tool call (`evo.content.search` or `evo.model.get`) through manager/API.
- verify response contract (`meta.toolsetVersion`) and trace header propagation.

## 9) Runtime Integration Script
Use the built-in integration harness when target environment credentials are available:

```bash
EMCP_INTEGRATION_ENABLED=1 \
EMCP_BASE_URL="https://example.org" \
EMCP_SERVER_HANDLE="content" \
EMCP_API_PATH="/api/v1/mcp/{server}" \
EMCP_API_TOKEN="<jwt>" \
EMCP_DISPATCH_CHECK=1 \
composer run test:integration:runtime
```

Optional manager checks:
- add `EMCP_MANAGER_PATH` and `EMCP_MANAGER_COOKIE`.
- if both API and manager credentials are provided, script validates both paths.

Optional hardening checks:
- set `EMCP_RUNTIME_NEGATIVE=1` for 401/403/413/415/429 probes.
- set `EMCP_RUNTIME_MODEL_SANITY=1` for `evo.model.get(User)` leakage sanity.
- set `EMCP_RUNTIME_NEGATIVE_REQUIRE_RATE_LIMIT=1` to fail if 429 is not observed.

Optional live sTask lifecycle proof:
- enable with `EMCP_STASK_LIFECYCLE_CHECK=1`.
- if target env has its own worker, also set `EMCP_STASK_EXPECT_EXTERNAL_WORKER=1`.
- if worker must be started by the test host, set:
  - `EMCP_STASK_WORKER_CMD="php artisan stask:worker"`
  - `EMCP_STASK_WORKER_CWD="/path/to/evo/core"`
  - optional `EMCP_STASK_POLL_ATTEMPTS` (default: `20`).
