# SPEC — eMCP (Laravel MCP integration for Evolution CMS)

## Scope of This SPEC
Цей документ фіксує **обовʼязкові технічні контракти** для реалізації `eMCP`.

Status markers:
- `SPEC Version`: `1.0-contract`
- `Runtime Status`: `Gate C baseline validated in demo runtime; RC-1 hardening pending`

Normative hierarchy:
- `SPEC.md` defines platform-wide MUST/SHOULD rules.
- `TOOLSET.md` defines canonical public tool contract (`evo.content.*`, `evo.model.*`).
- `DOCS*.md` are implementation and usage guides; if conflict occurs, `SPEC.md` + `TOOLSET.md` win.

Current validation snapshot (2026-03-03):
- `make demo-all` PASS (install + smoke + runtime integration).
- `php artisan emcp:test` PASS for `initialize` and `tools/list`.
- Runtime HTTP integration PASS for `/api/v1/mcp/{server}`.
- Verified content-read tool flow in runtime (`evo.content.search`, `evo.content.root_tree`, `evo.content.get`).
- One-click verification writes `demo/logs.md` with request/response evidence, negative transport/security probes (`401/403/413/415/409/429`) and model-safety sanity (`evo.model.get(User)` without sensitive fields).

Open RC-1 validation scope:
- live CI runtime integration jobs are wired for `release/*` pushes (`demo-runtime-proof`, `runtime-integration`), but branch-protection required-check enforcement must be configured in repository settings;
- live async `sTask` e2e checks (queue lifecycle/progress/failover) are not yet enforced in CI;
- live stream/rate-limit infra checks remain pending as RC evidence.

## 0. Джерела
- `/Users/dmi3yy/PhpstormProjects/Extras/LaravelMcp` — upstream `laravel/mcp`.
- `/Users/dmi3yy/PhpstormProjects/Extras/LaravelAi` — upstream SDK packaging patterns consumed via Evo thin wrappers.
- `/Users/dmi3yy/PhpstormProjects/Extras/eAi` — Evo thin-wrapper pattern для Laravel package.
- `/Users/dmi3yy/PhpstormProjects/Extras/sApi` — API kernel, JWT middleware, route provider discovery.
- `/Users/dmi3yy/PhpstormProjects/Extras/sTask` — async worker/task model.
- `/Users/dmi3yy/PhpstormProjects/Extras/dAi` — manager-side AI/orchestration consumer.
- `/Users/dmi3yy/PhpstormProjects/Extras/ColimaOpenclaw` — contract discipline patterns (deterministic gates, doc sync, operability checks).
- `/Users/dmi3yy/PhpstormProjects/Extras/ePasskeys` — permissions migrations + publish flatten pattern.
- `/Users/dmi3yy/PhpstormProjects/Extras/eFilemanager` — publish + shim/autoload.files pattern.
- `/Users/dmi3yy/PhpstormProjects/Extras/sLang` — lang/multilingual package pattern.
- `/Users/dmi3yy/PhpstormProjects/Extras/evolution/core/src/Models` — Evo domain models (`SiteContent`, TVs, users, ACL entities).
- `/Users/dmi3yy/PhpstormProjects/Extras/eMCP/TOOLSET.md` — canonical public tool contract v1.

## 1. Архітектура (high-level)
`eMCP` = thin integration layer:
- Upstream protocol/runtime: `laravel/mcp`.
- Evo adapter layer: provider, routes, config paths, permissions, logging.
- Access layer:
- manager mode (ACL Evo).
- API mode через `sApi` (JWT scopes).
- Async layer: `sTask` worker (`emcp_dispatch`) для long-running викликів.

### 1.1 Обовʼязкові принципи
- Не форкати upstream `laravel/mcp` код.
- Не тягнути `laravel/framework`/`illuminate/foundation`.
- Evo-specific сумісність реалізується тільки адаптерами `eMCP`.
- Безпека за замовчуванням: deny-by-default.

### 1.2 Minimal Boot Contract (MVP Gate)
У чистому Evo (без `Passport`) пакет MUST:
- встановлюватися і завантажуватись без fatals/warnings на boot;
- реєструвати щонайменше один `web` MCP server з `config('mcp.servers')`;
- обробляти `POST` JSON-RPC `initialize`;
- обробляти `POST` JSON-RPC `tools/list`;
- повертати `405` на `GET` для MCP transport route;
- коректно передавати `MCP-Session-Id`;
- enforce manager ACL: запит без permission `emcp` MUST отримувати `403`.

### 1.3 Delivery Order (Risk-First, Mandatory)
Реалізація виконується по гейтах:
- Gate A: `web` transport + manager route + manager ACL (`emcp`) + `initialize/tools:list` + `405`.
- Gate B: API access layer (scope engine, basic rate limit, `sApi` integration).
- Gate C: async layer (`sTask`, payload contract, failover, idempotency).
- Gate D: hardening (audit/redaction/denylist) + DX commands.

### 1.4 Contract-First implementation profile (MUST)
Архітектурний профіль реалізації фіксується як data-first pipeline:
- `DATA DIVISION` -> contracts/data schemas (`TOOLSET.md` + runtime validators).
- `PROCEDURE DIVISION` -> tool handlers (`1 tool = 1 explicit procedure`).
- `ENVIRONMENT DIVISION` -> runtime guards (`ACL/scopes/rate/limits/redaction/idempotency`).
- `FILE SECTION` -> projection/mapping layer для стабільних response layouts.

Execution pipeline for every tool call (MUST):
- `validate -> authorize -> query -> map -> paginate -> respond -> audit`.
- Hidden side effects in transport/controller layer are forbidden.
- Canonical tools MUST implement explicit stage separation (`validate`, `authorize`, `query`, `map`, `paginate`, `respond`, `audit`) so each stage is independently testable.

### 1.5 Ecosystem interop contract (MUST)
`eMCP` is a neutral platform layer and MUST remain concept-agnostic.

Interop boundaries:
- `LaravelMcp` compatibility: preserve upstream transport/protocol semantics.
- `sApi` compatibility: external MCP routes via `RouteProviderInterface` + JWT attributes/scopes.
- `sTask` compatibility: async execution through worker/task model without custom queue framework coupling.
- `eAi`/`dAi` compatibility: consumers use eMCP tool contracts; core eMCP MUST NOT depend on one orchestration concept.

Design rule:
- eMCP defines execution contracts and policy boundaries.
- packages above eMCP implement orchestration strategies (planner/guild/workflow/etc.) independently.
- Runtime MUST expose no orchestration-specific persistence entities (`Intent`, `PolicyCheck`, `EvidenceTrace`, etc.) unless extension profile is explicitly enabled.

## 2. Структура пакета
```text
eMCP/
├─ composer.json
├─ PRD.md
├─ SPEC.md
├─ config/
│  ├─ mcp.php
│  └─ eMCPSettings.php
├─ database/
│  └─ migrations/
│     ├─ *_add_emcp_permissions.php
│     └─ *_add_emcp_role_permissions.php (optional if split)
├─ lang/
│  ├─ en/global.php
│  ├─ uk/global.php
│  └─ ru/global.php
├─ plugins/
│  └─ eMCPPlugin.php
├─ src/
│  ├─ eMCPServiceProvider.php
│  ├─ LaravelMcp/
│  │  └─ McpServiceProvider.php
│  ├─ Http/
│  │  ├─ mgrRoutes.php
│  │  └─ Controllers/
│  ├─ Api/
│  │  └─ Routes/McpRouteProvider.php
│  ├─ Middleware/
│  │  ├─ EnsureMcpPermission.php
│  │  ├─ EnsureMcpScopes.php
│  │  ├─ ResolveMcpActor.php
│  │  └─ RateLimitMcpRequests.php
│  ├─ Services/
│  │  ├─ ServerRegistry.php
│  │  ├─ McpExecutionService.php
│  │  ├─ ScopePolicy.php
│  │  └─ AuditLogger.php
│  ├─ Support/
│  │  ├─ AutoloadShims.php
│  │  ├─ ConfigPath.php
│  │  └─ Redactor.php
│  └─ sTask/
│     └─ McpDispatchWorker.php
├─ stubs/
│  ├─ mcp-server.stub
│  ├─ mcp-tool.stub
│  ├─ mcp-resource.stub
│  └─ mcp-prompt.stub
└─ README.md
```

## 3. composer.json
### 3.1 Mandatory
- `name`: `evolution-cms/emcp`
- `type`: `evolutioncms-plugin`
- `require`:
- `php: ^8.4`
- `evolution-cms/evolution: ^3.5.2`
- `laravel/mcp: ^0.5`

### 3.2 Required integrations
- `seiger/sapi` (^1.0, для API mode).
- `seiger/stask` (^1.0, для async mode).

### 3.3 Autoload
- PSR-4 namespace `EvolutionCMS\eMCP\`.
- `autoload.files` MUST include `src/Support/AutoloadShims.php`.

### 3.4 Extra
- Provider: `EvolutionCMS\eMCP\eMCPServiceProvider`.
- For sApi discovery (if package installed in environment), `extra.sapi.route_providers` SHOULD expose `McpRouteProvider` descriptor.

Example descriptor:
```json
{
  "extra": {
    "sapi": {
      "route_providers": [
        {
          "class": "EvolutionCMS\\eMCP\\Api\\Routes\\McpRouteProvider",
          "endpoint": "mcp",
          "version": "v1"
        }
      ]
    }
  }
}
```

## 4. Конфіг

## 4.1 `config/eMCPSettings.php` (publish -> `core/custom/config/cms/settings/eMCP.php`)
Required keys:
- `enable`: bool, default `true`.
- `mode.internal`: bool, default `true`.
- `mode.api`: bool, default `true`.
- `route.manager_prefix`: string, default `emcp`.
- `route.api_prefix`: string, default `mcp`.
- `auth.mode`: `sapi_jwt|none`, default `sapi_jwt`.
- `auth.require_scopes`: bool, default `true`.
- `auth.scope_map`: array (див. 7.2).
- `acl.permission`: string, default `emcp`.
- `queue.driver`: `stask|sync`, default `stask`.
- `queue.failover`: `sync|fail`, default `sync`.
- `rate_limit.enabled`: bool, default `true`.
- `rate_limit.per_minute`: int, default `60`.
- `limits.max_payload_kb`: int, default `256`.
- `limits.max_result_items`: int, default `100`.
- `limits.max_result_bytes`: int, default `1048576`.
- `stream.enabled`: bool, default `false` (Gate B+).
- `stream.max_stream_seconds`: int, default `120`.
- `stream.heartbeat_seconds`: int, default `15`.
- `stream.abort_on_disconnect`: bool, default `true`.
- `logging.channel`: string, default `emcp`.
- `logging.audit_enabled`: bool, default `true`.
- `logging.redact_keys`: array.
- `security.allow_servers`: array, default `['*']`.
- `security.deny_tools`: array, default `[]`.
- `security.enable_write_tools`: bool, default `false`.
- `actor.mode`: `initiator|service`, default `initiator`.
- `actor.service_username`: string, default `MCP`.
- `actor.service_role`: string, default `MCP`.
- `actor.block_login`: bool, default `true`.
- `trace.header`: string, default `X-Trace-Id`.
- `trace.generate_if_missing`: bool, default `true`.
- `idempotency.header`: string, default `Idempotency-Key`.
- `idempotency.ttl_seconds`: int, default `86400`.
- `idempotency.storage`: `stask_meta|cache`, default `stask_meta`.
- `domain.content.max_depth`: int, default `6`.
- `domain.content.max_limit`: int, default `100`.
- `domain.content.max_offset`: int, default `5000`.
- `domain.models.max_offset`: int, default `5000`.
- `domain.models.allow`: array, default allowlist (див. 10.4).

## 4.2 `config/mcp.php` (publish -> `core/custom/config/mcp.php`)
Базовий upstream config + eMCP extensions:
- `redirect_domains` (upstream).
- `servers` (eMCP extension):
```php
'servers' => [
  [
    'handle' => 'content',
    'transport' => 'web',
    'route' => '/mcp/content',
    'class' => EvolutionCMS\eMCP\Servers\ContentServer::class,
    'enabled' => true,
    'auth' => 'sapi_jwt',
    'scopes' => ['mcp:read', 'mcp:call'],
    'scope_map' => [
      'mcp:read' => ['initialize', 'tools/list', 'resources/read'],
      'mcp:call' => ['tools/call'],
    ],
    'limits' => [
      'max_payload_kb' => 128,
      'max_result_items' => 50,
    ],
    'rate_limit' => [
      'per_minute' => 30,
    ],
    'security' => [
      'deny_tools' => ['evo.write.*'],
    ],
  ],
  [
    'handle' => 'content-local',
    'transport' => 'local',
    'class' => EvolutionCMS\eMCP\Servers\ContentServer::class,
    'enabled' => false,
  ],
],
```

Note:
- `content-local` is disabled by default to keep global tool-name uniqueness.
- If you enable a local server with the same toolset, disable conflicting server entries first.

Per-server override policy:
- `scope_map` MAY override global scope mapping.
- `limits.*` MAY override global payload/result limits.
- `rate_limit.per_minute` MAY override global rate limit.
- `security.deny_tools` MAY extend per-server deny policy.

## 5. ServiceProvider strategy

## 5.1 `eMCPServiceProvider`
### register()
- `loadPluginsFrom()`.
- Merge `eMCPSettings` у `cms.settings.eMCP`.
- Register logging channel `emcp` (daily) if missing.
- Register middleware aliases (`emcp.permission`, `emcp.scope`, `emcp.actor`, `emcp.rate`).
- Register adapter provider (`EvolutionCMS\eMCP\LaravelMcp\McpServiceProvider`).

### boot()
- Merge `mcp.php` into `mcp` namespace.
- Load migrations.
- Load translations.
- Load manager routes.
- Publish resources (config/stubs/lang/views as needed).
- Run `flattenPublishDirectories()` after boot (as in `eAi/ePasskeys/eFilemanager`).
- Auto-register sTask worker when `sTask` is present.

## 5.2 Adapter provider `LaravelMcp\McpServiceProvider`
Purpose: Evo-safe replacement for upstream `Laravel\Mcp\Server\McpServiceProvider`.

Mandatory behavior:
- Register `Registrar` singleton.
- Register container callbacks for `Laravel\Mcp\Request` (`mcp.request` bridging).
- Register MCP commands (`mcp:start`, `mcp:inspector`, `make:mcp-*`).
- Register publish groups without Laravel app skeleton assumptions.
- Register routes from eMCP registry instead of requiring `routes/ai.php`.
- Guard calls that may not exist in Evo runtime (`routesAreCached`, path helpers) with safe fallbacks.

## 5.3 Shims and aliasing
`src/Support/AutoloadShims.php` MUST alias upstream provider to adapter before provider registration:
- `Laravel\Mcp\Server\McpServiceProvider` -> `EvolutionCMS\eMCP\LaravelMcp\McpServiceProvider`.

Alias safety contract:
- Aliasing is allowed only for provider interception, not for runtime MCP primitives.
- Intercepted contract is strictly:
- provider boot lifecycle
- route source (`config` instead of `routes/ai.php`)
- publish paths and Evo-safe helper usage
- Before enabling alias in production, a spike MUST verify whether container/provider replacement without alias is viable.
- If non-alias replacement is not viable, keep alias and document the reason in `DOCS.md` + changelog.
- CI MUST include a regression test that fails if upstream provider FQCN changes or is no longer aliasable.
- Boot MUST fail fast with `RuntimeException` if expected upstream provider FQCN does not exist or `class_alias` interception cannot be applied.
- Exception message MUST include actionable hint: verify installed `laravel/mcp` version and update eMCP adapter/alias map.

## 6. Route registration contract

## 6.1 Manager routes (`src/Http/mgrRoutes.php`)
Under middleware `mgr` + `emcp.permission`:
- `POST /{manager_prefix}/{server}` -> JSON-RPC MCP endpoint.
- `POST /{manager_prefix}/{server}/dispatch` -> async submit to sTask (Gate C+).
- `GET /{manager_prefix}/servers` -> registry diagnostics (optional but recommended).

Rules:
- `GET` to MCP transport route returns `405`.
- `POST` accepts JSON only.
- Request must support `MCP-Session-Id` header passthrough.

## 6.2 API routes via sApi (`McpRouteProvider`)
`McpRouteProvider` MUST implement `Seiger\sApi\Contracts\RouteProviderInterface`.

Registered routes (inside sApi group):
- `POST /mcp/{server}` -> JSON-RPC endpoint.
- `POST /mcp/{server}/dispatch` -> async submit (Gate C+).

Middleware chain:
- `emcp.jwt` (adapter over `sApi` JWT middleware).
- `emcp.scope`.
- `emcp.actor`.
- `emcp.rate`.

Route-level policy:
- `McpRouteProvider` MUST remove upstream `sapi.jwt` middleware from MCP routes and use `emcp.jwt` as the single JWT/auth normalization middleware.

## 6.3 Server registry logic
`ServerRegistry` resolves active servers from `config('mcp.servers')`.

Validation rules:
- unique `handle`.
- `class` exists and extends `Laravel\Mcp\Server`.
- `transport` in `web|local`.
- `enabled=true` only gets registered.
- duplicate `handle` is forbidden: fail-fast in debug, warning + reject conflicting registration in production.

Registration mapping:
- `transport=web` -> `Mcp::web(route, class)`.
- `transport=local` -> `Mcp::local(handle, class)`.

Runtime policy resolution order:
- rate limit: `mcp.servers[*].rate_limit.per_minute` -> global `rate_limit.per_minute`.
- limits: `mcp.servers[*].limits.*` -> global `limits.*`.
- deny tools: per-server `security.deny_tools` + global `security.deny_tools` (union).

## 6.4 Tool registration rules (MUST)
- Tool names are globally unique within one MCP runtime.
- Duplicate tool name is a configuration error.
- In debug mode, duplicate tool registration MUST fail-fast.
- In production, duplicate tool registration MUST emit warning and reject second registration.
- Re-registration of existing tool name or server `handle` is forbidden.
- ServerRegistry MUST perform global tool-name uniqueness validation before boot completes.

## 6.5 Namespace governance policy (MUST)
- Namespace `evo.*` is reserved exclusively for Evolution CMS core toolset.
- Third-party ecosystem packages MUST NOT register tools in `evo.*`.
- Third-party ecosystem packages MUST use `vendor.domain.*` naming (for example `shop.catalog.*`, `crm.contact.*`).
- Name collision with existing tool name is a configuration error.
- In debug mode, name collision MUST fail-fast.
- In production, name collision MUST emit warning and reject conflicting registration.
- Namespace enforcement on registration:
- if tool name starts with `evo.` and source package is not core, registration MUST be rejected.
- debug mode: throw exception.
- production mode: warning + reject registration.

## 7. Auth, ACL, Scopes

## 7.1 Permissions (Evo ACL)
Migration MUST create:
- Permission group: `eMCP` (or existing `sPackages` by project decision; default in this SPEC: `eMCP`).
- Permissions:
- `emcp` (Access eMCP Interface)
- `emcp_manage` (Manage MCP servers)
- `emcp_dispatch` (Run async MCP tasks)

Delivery gates for permissions:
- Gate A (MVP) MUST include `emcp`.
- Gate B+ SHOULD include `emcp_manage`.
- Gate C+ SHOULD include `emcp_dispatch`.

Role assignment defaults:
- role_id `1` gets all `emcp*` permissions.

Migration style MUST follow robust idempotent pattern from `sTask/ePasskeys`:
- safe firstOrCreate/upsert.
- PostgreSQL sequence fix helper.
- reversible down().

## 7.2 Scope policy (API)
`EnsureMcpScopes` MUST map JSON-RPC method -> scope by policy table:
- `initialize`, `ping`, `tools/list`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, `completion/complete` -> require `mcp:read`.
- `tools/call` -> require `mcp:call`.
- server admin/service endpoints -> require `mcp:admin`.
- wildcard `*` bypasses specific checks.

Resolution order (MUST):
- per-server override from `mcp.servers[*].scope_map` (if defined)
- global `auth.scope_map`
- built-in default table (above)

This keeps secure defaults while preserving extensibility for ecosystem packages with custom methods.

## 7.3 sApi JWT mode
- `sapi_jwt` is the only external API auth mode.
- If API auth is disabled by config, fallback mode is `none` for restricted/internal scenarios.

## 7.4 Upstream compatibility window (MUST)
- Supported upstream window: `laravel/mcp ^0.5.x`.
- If upstream provider FQCN/signature changes, eMCP MUST ship adapter update before recommending upstream upgrade.
- Upgrade protocol remains mandatory: spike + smoke + alias regression.

## 8. Actor resolution and identity
`ResolveMcpActor` middleware responsibility:
- manager mode: actor = logged manager user.
- sApi mode: actor from `request->attributes['sapi.jwt.user_id']` if available.
- service mode: actor = service account user (auto-create optional).

Context fields propagated to logs/task payload:
- `actor_user_id`
- `initiated_by_user_id`
- `context` (`mgr|api|cli`)
- `trace_id`

### 8.1 Trace ID policy (MUST)
- Trace ID source priority: request header `trace.header` -> generated value.
- If `trace.generate_if_missing=true`, missing trace id MUST be generated as UUID v4.
- Generated or forwarded trace id MUST be returned in response header `trace.header`.
- Trace ID MUST be propagated to audit events and async payloads unchanged.

### 8.2 Rate limit identity policy (MUST)
Rate limit identity key resolution order:
- manager mode: `actor_user_id`.
- API mode: JWT subject/user id (for example `sapi.jwt.user_id`).
- fallback when identity is missing: client IP.

This policy MUST be consistent across middleware and async dispatch entrypoints.
Implementation MUST use one shared resolver function (for example `resolveRateLimitIdentity()`); duplicated logic is forbidden.

## 9. sTask integration

## 9.1 Worker registration
Plugin MUST auto-register worker in `s_workers` if `sTask` exists:
- `identifier`: `emcp_dispatch`
- `scope`: `eMCP`
- `class`: `EvolutionCMS\eMCP\sTask\McpDispatchWorker`
- `active`: true

## 9.2 Dispatch payload contract
Async payload (`meta`) MUST include:
- `server_handle`
- `jsonrpc_method`
- `jsonrpc_params`
- `request_id`
- `session_id`
- `trace_id`
- `idempotency_key`
- `actor_user_id`
- `initiated_by_user_id`
- `context`
- `attempts`
- `max_attempts`

Idempotency policy (MUST):
- Source priority: request header `idempotency.header` -> payload field `idempotency_key` -> generated hash of (`server_handle`,`method`,`params`,`actor_user_id`) for sync fallback only.
- Storage backend from `idempotency.storage`.
- TTL from `idempotency.ttl_seconds`.
- Persisted idempotency record MUST include payload hash.
- If the same `idempotency_key` is reused with identical payload hash, system MUST return existing task/result reference.
- If the same `idempotency_key` is reused with different payload hash, system MUST reject with HTTP `409 Conflict`.
- Conflict error body MUST follow non-JSON-RPC transport error format from section `10.1`.
- Conflict path MUST never create a new async task.

## 9.3 Worker behavior
`McpDispatchWorker`:
- validates payload and server allowlist.
- executes MCP call via `McpExecutionService`.
- writes progress (`TaskProgress`) and final result.
- marks task failed with sanitized error (no secrets).

If `sTask` unavailable:
- obey `queue.failover`.
- `sync` -> execute immediately.
- `fail` -> return controlled error.

## 10. Execution contract
`McpExecutionService::call(...)` MUST:
- accept JSON-RPC message + server handle.
- resolve registered server.
- execute request and return raw JSON-RPC response structure.
- preserve/return `MCP-Session-Id` when applicable.
- support streaming mode when method returns iterable stream.
- for `initialize`, include mandatory platform metadata (see `10.0`).

Safety:
- payload size limit check.
- tool denylist enforcement pre-call.

### 10.0 Initialize metadata contract (MUST)
`initialize` response MUST include:
- `serverInfo.platform = "eMCP"`.
- `serverInfo.platformVersion = <current-package-version>`.
- `capabilities.evo.toolsetVersion = "1.0"` (or current toolset version).
- This metadata is part of the public contract and required for version-aware ecosystem integrations.
- Missing metadata is a breaking contract change.

## 10.1 JSON-RPC + HTTP error mapping policy (MUST)
Error handling is split into two layers with a single fixed rule:
- Transport/auth/middleware errors return HTTP status codes and non-JSON-RPC error body.
- JSON-RPC dispatch errors return HTTP `200` with JSON-RPC `error` object.

Non-JSON-RPC error body (MUST):
```json
{
  "error": {
    "code": "forbidden",
    "message": "Forbidden",
    "trace_id": "..."
  }
}
```

Mapping table:
- invalid JSON body -> JSON-RPC `-32700` (HTTP 200).
- invalid JSON-RPC envelope -> JSON-RPC `-32600` (HTTP 200).
- method/server/tool not found -> JSON-RPC `-32601` (HTTP 200).
- invalid params/validation -> JSON-RPC `-32602` (HTTP 200).
- internal execution failure -> JSON-RPC `-32603` (HTTP 200, sanitized message).
- unauthenticated API request -> HTTP `401` (middleware layer, non-JSON-RPC).
- forbidden by ACL/scope -> HTTP `403` (middleware layer, non-JSON-RPC).
- idempotency key conflict (same key, different payload) -> HTTP `409` (non-JSON-RPC).
- method not allowed (GET on MCP route) -> HTTP `405`.
- payload too large -> HTTP `413`.
- unsupported media type -> HTTP `415`.

The project MUST NOT mix styles for the same failure class.
- A single error formatter MUST be used for `401`, `403`, `409`, `413`, `415`.
- Every non-JSON-RPC error body MUST include `trace_id`.
- Raw Laravel exception responses are forbidden.

## 10.2 Evo `SiteContent` tool profile (mandatory, Gate B+)
`eMCP` MUST ship (or provide canonical stubs for) tool names:
- `evo.content.search`
- `evo.content.get`
- `evo.content.root_tree`
- `evo.content.descendants`
- `evo.content.ancestors`
- `evo.content.children`
- `evo.content.siblings`

Behavior mapping to Evo model API:
- `evo.content.search` -> `SiteContent` query + optional `scopeActive|scopePublished|scopeWithoutProtected|scopeWithTVs|scopeTvFilter|scopeTvOrderBy|scopeTagsData|scopeOrderByDate`.
- `evo.content.root_tree` -> `scopeGetRootTree(depth)` + `withTVs(..., ':', true)` + `toTree`.
- `evo.content.descendants` -> `scopeDescendantsOf(id)` (optional depth cap via `where('depth', '<', maxDepth + 1)`).
- `evo.content.ancestors` -> `scopeAncestorsOf(id)` + default `orderBy('depth', 'desc')`.
- `evo.content.children` -> direct children (`parent = id`) or `getChildren()`.
- `evo.content.siblings` -> `scopeSiblingsOf(id)` and related sibling selectors when requested.

Advanced optional tools (Post-MVP):
- `evo.content.neighbors` -> `scopeNeighborsOf(id)`.
- `evo.content.prev_siblings` -> `scopePrevSiblingsOf(id)`.
- `evo.content.next_siblings` -> `scopeNextSiblingsOf(id)`.
- `evo.content.children_range` -> `scopeChildrenRangeOf(id, from, to)`.
- `evo.content.siblings_range` -> `scopeSiblingsRangeOf(id, from, to)`.

## 10.3 `SiteContent` request/validation contract
Input for `evo.content.*` MUST be structured JSON (not raw SQL fragments):
- `id` or `ids` for point lookups.
- `parent`, `depth`, `published`, `deleted`, `template`, `hidemenu`.
- `with_tvs`: array of TV names, supports default marker (`name:d`).
- `tv_filters`: array of objects `{tv, op, value, cast?, use_default?}`.
- `tv_order`: array of objects `{tv, dir, cast?, use_default?}`.
- `tags_data`: object `{tv_id, tags[]}` (maps to `scopeTagsData`).
- `order_by_date`: `asc|desc` (maps to `scopeOrderByDate`).
- `limit`, `offset`.

Validation rules (MUST):
- Reject raw `tvFilter` strings from client payload.
- Allowed operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `like`, `like-l`, `like-r`, `null`, `!null`.
- Allowed casts: `UNSIGNED`, `SIGNED`, `DECIMAL(p,s)`.
- Enforce `depth <= domain.content.max_depth`, `limit <= domain.content.max_limit`, `offset <= domain.content.max_offset`.
- Enforce allowlist for sortable base columns (`id`, `pagetitle`, `menuindex`, `createdon`, `pub_date`).
- Large responses MUST be paginated (`limit`/`offset`) and bounded by `limits.max_result_items`.
- Serialized response size MUST be bounded by `limits.max_result_bytes`; oversized responses return `413`.

## 10.4 Evo model catalog tool profile (Gate B+)
Read-only canonical tools:
- `evo.model.list`
- `evo.model.get`

`domain.models.allow` default allowlist:
- `SiteTemplate`
- `SiteTmplvar`
- `SiteTmplvarContentvalue`
- `SiteSnippet`
- `SitePlugin`
- `SiteModule`
- `Category`
- `User`
- `UserAttribute`
- `UserRole`
- `Permissions`
- `PermissionsGroups`
- `RolePermissions`

Allowlist governance (MUST):
- Any extension of default `domain.models.allow` requires security checklist review and explicit release-note approval.
- Any change that increases exposed fields for an allowlisted model requires allowlist test updates and BC/SemVer impact review before merge.

Model field exposure policy (MUST):
- Each allowlisted model MUST have explicit allowlist of public fields.
- `evo.model.list` and `evo.model.get` MUST return only allowlisted fields.
- Sensitive-field blacklist remains additional defense-in-depth and MUST still be enforced.
- Direct `model->toArray()` exposure without sanitizer/allowlist projection is forbidden.

Default model field allowlists (MUST):
- `SiteTemplate`: `id`, `templatename`, `description`, `editor_type`, `icon`, `category`, `locked`.
- `SiteTmplvar`: `id`, `name`, `caption`, `description`, `type`, `default_text`, `display`, `elements`, `rank`, `category`, `locked`.
- `SiteTmplvarContentvalue`: `id`, `contentid`, `tmplvarid`, `value`.
- `SiteSnippet`: `id`, `name`, `description`, `category`, `locked`, `disabled`, `createdon`, `editedon`.
- `SitePlugin`: `id`, `name`, `description`, `category`, `locked`, `disabled`, `createdon`, `editedon`.
- `SiteModule`: `id`, `name`, `description`, `category`, `disabled`, `createdon`, `editedon`.
- `Category`: `id`, `category`.
- `User`: `id`, `username`, `isfrontend`, `createdon`, `editedon`, `blocked`, `blockeduntil`, `blockedafter`.
- `UserAttribute`: `id`, `internalKey`, `fullname`, `email`, `phone`, `mobilephone`, `blocked`, `blockeduntil`, `blockedafter`, `failedlogincount`, `logincount`, `lastlogin`.
- `UserRole`: `id`, `name`, `description`, `frames`, `home`, `rank`, `locked`.
- `Permissions`: `id`, `name`, `description`.
- `PermissionsGroups`: `id`, `name`.
- `RolePermissions`: `id`, `role_id`, `permission`.

`evo.model.list` validation policy (MUST):
- `filters` accepts only structured JSON format: `{ "where": [ { "field": "...", "op": "...", "value": ... } ] }`.
- `filters.where[].field` MUST be allowlisted for the selected model.
- Allowed operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `like`, `like-l`, `like-r`, `null`, `!null`.
- Raw SQL fragments or raw query DSL MUST be rejected with JSON-RPC `-32602`.
- `offset` for model list MUST be bounded by `domain.models.max_offset`.

Sensitive-field policy (MUST):
- Never return raw fields: `password`, `cachepwd`, `verified_key`, `refresh_token`, `access_token`, `sessionid`.
- Redaction applies in API payloads and audit logs.
- Model writes are out of default profile.
- List endpoints MUST require pagination and obey `limits.max_result_items`.

## 10.5 Tool authorization matrix
- `evo.content.*` and `evo.model.*` read tools require `emcp` (manager mode).
- `evo.content.*` and `evo.model.*` read tools require `mcp:read` (API mode).
- Mutating tools (if introduced later) MUST live under explicit write namespace (e.g. `evo.write.*`).
- Mutating tools require `security.enable_write_tools=true`.
- Mutating tools require `emcp_manage` (manager mode).
- Mutating tools require `mcp:admin` (API mode).

Default behavior remains deny-by-default.

## 10.6 Streaming constraints (Gate B+)
- Streaming responses are out of MVP Gate A scope.
- Streaming is disabled by default.
- Streaming MUST be explicitly enabled via `stream.enabled=true`.
- Per-server streaming restrictions MAY apply and MUST be honored.
- Without explicit streaming enablement, streaming behavior is forbidden.
- When `stream.enabled=false`, any streaming response attempt MUST be rejected before dispatch.
- Runtime MUST enforce `stream.max_stream_seconds` and `stream.heartbeat_seconds` for active streams.
- Streaming enablement requires deployment notes for runtime environment:
- Nginx: disable response buffering for MCP routes (`proxy_buffering off` / `X-Accel-Buffering: no`).
- PHP-FPM/FastCGI: ensure flush path is enabled and output buffering is controlled.
- Reverse proxy timeouts MUST be configured to tolerate long-running streams.
- Backpressure and liveness policy (MUST):
- hard timeout via `stream.max_stream_seconds`.
- heartbeat event every `stream.heartbeat_seconds`.
- abort stream immediately when `stream.abort_on_disconnect=true` and client disconnect is detected.

## 10.7 Orchestration extension profile (Post-MVP SHOULD)
Для розширених сценаріїв execution beyond plain tool-calls, eMCP SHOULD підтримувати нейтральний envelope:
- `Intent` (what should be executed).
- `PolicyCheck` (which rules allowed/blocked action).
- `Task(s)` (materialized workflow steps).
- `EvidenceTrace` (context/facts used).
- `ApprovalGate` (approve/reject/escalate where required).

Rules:
- Any orchestrator/planner MUST act only within policy-valid action sets.
- Materialized tasks SHOULD keep traceable linkage to intent/policy/evidence IDs.
- Execution trace MUST stay auditable end-to-end.

## 11. Publish resources
Tags:
- `emcp-config`: `eMCPSettings.php` -> `core/custom/config/cms/settings/eMCP.php`.
- `emcp-mcp-config`: `mcp.php` -> `core/custom/config/mcp.php`.
- `emcp-stubs`: MCP stubs -> `core/stubs/`.
- `emcp-lang` (optional): lang overrides.

All publish dirs MUST support flattened output (recursive files mapping).

## 11.1 Ecosystem extension points (MUST)
- Package-to-package server registration:
- ecosystem package adds server definition into `core/custom/config/mcp.php` using unique `handle`.
- package may provide `scope_map`, `limits`, `rate_limit`, `security.deny_tools` per server.
- Tool/resource/prompt onboarding:
- use `make:mcp-*` generators and publish stubs as canonical path.
- package-specific tools MUST follow `vendor.domain.*` naming.
- Policy extension:
- scopes and deny rules can be extended per server without changing global defaults.

## 11.2 Ecosystem extension governance (MUST)
- Third-party package MAY add server via config registration.
- Third-party package MAY add tools/resources/prompts via generators/stubs under its own namespace.
- Third-party package MUST NOT modify global scope namespace `mcp:*`.
- Per-server overrides are limited to `scope_map`, `limits`, `rate_limit`, `security.deny_tools`.
- Third-party package MUST NOT globally override behavior of `evo.content.*` or `evo.model.*`.

## 12. Console commands
From upstream (must be available):
- `mcp:start`
- `mcp:inspector`
- `make:mcp-server`
- `make:mcp-tool`
- `make:mcp-resource`
- `make:mcp-prompt`

eMCP custom commands (recommended/MUST for operability):
- `emcp:test` (smoke test: initialize + one list call).
- `emcp:sync-workers` (repair worker registration).
- `emcp:list-servers` (runtime diagnostics of registry).

### 12.1 DX quickstart contract (SHOULD)
Documentation SHOULD include one canonical "internal + external in 5 minutes" path:
- generate one server + one tool via `make:mcp-*`,
- register in `core/custom/config/mcp.php`,
- verify manager endpoint,
- verify `sApi` endpoint with JWT scopes,
- optional async dispatch verification with `sTask`.

## 13. Logging and audit

## 13.1 Channel
`logging.channels.emcp` daily file:
- path: `core/storage/logs/emcp.log`
- rotation days from env (`LOG_DAILY_DAYS`).

## 13.2 Audit schema (JSON line)
Required fields:
- `timestamp`
- `request_id`
- `trace_id`
- `server_handle`
- `method`
- `status`
- `actor_user_id`
- `context`
- `duration_ms`
- `task_id` (if async)

Recommended orchestration fields (Post-MVP SHOULD):
- `intent_id`
- `evidence_id`
- `policy_result` (`allow|deny`)
- `approval_status` (`approved|rejected|escalated|n/a`)

## 13.3 Redaction
`Redactor` MUST mask keys:
- `authorization`, `token`, `jwt`, `secret`, `cookie`, `password`, `api_key`.

## 14. Multilingual contract
Mandatory translation files:
- `lang/en/global.php`
- `lang/uk/global.php`
- `lang/ru/global.php`

Required keys:
- `title`
- `permissions_group`
- `permission_access`
- `permission_manage`
- `permission_dispatch`
- `errors.forbidden`
- `errors.scope_denied`
- `errors.server_not_found`
- `errors.invalid_payload`

## 15. Failure modes
- Missing `sApi`: API mode disabled, manager mode still works.
- Missing `sTask`: async disabled or sync failover per config.
- Missing registered server: return JSON-RPC error `-32601`/domain error with safe message.
- Unsupported protocol version: upstream `Initialize` behavior preserved.
- Invalid scope: 403 with structured error.
- Alias interception failure: fail fast on boot with `RuntimeException` and actionable message.
- Idempotency key conflict (same key, different payload): HTTP 409.

## 16. Test plan (mandatory)

## 16.1 Unit
- ServerRegistry validation (duplicates, invalid class, disabled server).
- ScopePolicy mapping by JSON-RPC method.
- Redactor masks secret fields.
- `SiteContent` tool input validator (operators/casts/depth/limit caps).
- Model catalog field sanitizer (sensitive fields excluded).
- Error mapper: JSON-RPC code + HTTP status consistency.
- Alias shim guard: explicit failure when upstream provider FQCN changes.

## 16.2 Integration
- Provider boot in Evo without Laravel skeleton files.
- Manager MCP route handshake (`initialize`) and session header.
- GET returns 405.
- `tools/list`, `resources/read`, `tools/call` contracts.
- Streaming response headers/content type.
- `evo.content.search/get/root_tree/descendants/ancestors` on real `SiteContent`.
- TV filters/order and default TV fallback (`:d`) on controlled fixtures.
- Trace id propagation from request -> response -> audit.
- Golden fixtures for canonical tools (`initialize`, `tools/list`, `evo.content.search`, `evo.content.get`) MUST pass in CI.
- Golden fixtures MUST be versioned by `toolsetVersion`; fixture schema change requires version bump and changelog entry.

## 16.3 Security
- ACL deny without `emcp`.
- Scope deny without `mcp:call` for `tools/call`.
- Log output contains no raw tokens.
- Reject raw `tvFilter` DSL payload from clients.
- `evo.model.get User` does not expose hidden/token credentials.

## 16.4 Async
- `emcp_dispatch` worker auto-registration.
- Task create -> execute -> result persistence.
- Failover behavior when `sTask` is absent.
- Idempotency key deduplication within TTL.

## 16.5 Orchestration/policy evidence tests (Post-MVP SHOULD)
- Policy contract tests: invalid action MUST be rejected before task materialization.
- Intent-to-task traceability tests: every created task has intent/evidence linkage.
- Deterministic replay tests for control scenarios (same input -> same policy verdict class).
- Benchmark suite smoke: baseline strategy vs at least one planner strategy on fixed episode set.

## 17. Release gates
- Architecture freeze artifacts approved before Phase 1:
- `PLATFORM_AUDIT.md`
- `THREAT_MODEL.md`
- `ARCHITECTURE_FREEZE_CHECKLIST.md`
- All AC з PRD закриті.
- Smoke command `emcp:test` green.
- Migration up/down idempotent across MySQL/PostgreSQL/SQLite.
- README installation flow verified in clean Evo instance.
- Upstream update protocol observed (`laravel/mcp` spike + smoke + alias regression).

### 17.1 MVP release gate (v0.1)
- Passes Minimal Boot Contract (section 1.2).
- Includes only Gate A functionality.
- Enforces manager ACL (`emcp`) for MCP manager route.
- Does not require `Passport` to be functional.

## 18. Public contract stability (MUST)
- eMCP follows SemVer for public MCP behavior.
- `evo.content.*` and `evo.model.*` are stable public namespaces.
- Rename/removal of public namespaces or canonical tool names is allowed only in `MAJOR`.
- Changing error semantics requires `MAJOR`.
- Removing mandatory `initialize` platform metadata requires `MAJOR`.
- Adding new optional params/capabilities is allowed in `MINOR`.
- Making existing optional params mandatory in `MINOR` is forbidden.
- Breaking change rollout requires deprecation notice for at least one `MINOR`.
