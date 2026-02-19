# DOCS — eMCP (Evolution CMS + Laravel MCP)

This document describes how `eMCP` integrates `laravel/mcp` into Evolution CMS.
It is the implementation-oriented guide aligned with `PRD.md` and `SPEC.md`.

Contract boundary:
- `SPEC.md` and `TOOLSET.md` are normative.
- `DOCS.md` describes how to implement and operate those contracts.

## 1) Overview
`eMCP` is a thin Evo-native adapter around `laravel/mcp`.

Core goals:
- keep upstream MCP runtime and protocol behavior
- adapt registration/config/routes for Evo architecture
- enforce ACL/scopes and enterprise security controls
- support both internal manager usage and external API clients

Integration layers:
- **Protocol layer**: `laravel/mcp` (Server, Registrar, transports, JSON-RPC methods)
- **Adapter layer**: `eMCP` provider, registry, middleware, publishing
- **API layer**: optional `sApi` route provider with JWT scopes
- **Async layer**: optional `sTask` worker (`emcp_dispatch`)

## 2) Delivery Order (Risk-First)
Implementation order is mandatory:
- Gate A: web transport + manager route + manager ACL (`emcp`) + `initialize` + `tools/list` + `GET=405`
- Gate B: API access layer (scope engine, basic rate limit, `sApi` provider)
- Gate C: async (`sTask` worker, payload contract, failover, idempotency)
- Gate D: optional Passport compatibility
- Gate E: security hardening + DX commands

Minimal first release is Gate A only.

## User Guide

## 3) Requirements
Mandatory:
- Evolution CMS 3.5.2+
- PHP 8.4+
- Composer 2.2+

Optional:
- `seiger/sapi` for external API access
- `seiger/stask` for async execution
- `laravel/passport` for OAuth-compatible mode

## 4) Install
From Evo `core` directory:

```bash
cd core
php artisan package:installrequire evolution-cms/emcp "*"
php artisan migrate
```

## 5) Publish Resources
Auto-publish may be enabled by installer flows, but explicit publish is recommended:

```bash
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs
```

Published targets:
- `core/custom/config/cms/settings/eMCP.php`
- `core/custom/config/mcp.php`
- `core/stubs/mcp-server.stub`
- `core/stubs/mcp-tool.stub`
- `core/stubs/mcp-resource.stub`
- `core/stubs/mcp-prompt.stub`

## 6) Configuration Reference

## 6.1 `core/custom/config/cms/settings/eMCP.php`
Recommended baseline:

Phase labels:
- `[MVP]` `enable`, `mode.internal`, `route.manager_prefix`, `acl.permission`.
- `[Gate B+]` `mode.api`, `auth.*`, `rate_limit.*`, `limits.*`, `security.allow_servers`, `security.deny_tools`, `domain.*`, `actor.*`.
- `[Gate C+]` `queue.*`, async/idempotency behavior.
- `[Gate E]` `logging.audit_enabled`, hardening/redaction tuning.

```php
return [
    'enable' => true,

    'mode' => [
        'internal' => true,
        'api' => true,
    ],

    'route' => [
        'manager_prefix' => 'emcp',
        'api_prefix' => 'mcp',
    ],

    'auth' => [
        'mode' => 'sapi_jwt',
        'require_scopes' => true,
        'scope_map' => [
            'mcp:read' => ['initialize', 'ping', 'tools/list', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get', 'completion/complete'],
            'mcp:call' => ['tools/call'],
            'mcp:admin' => ['admin/*'],
        ],
    ],

    'acl' => [
        'permission' => 'emcp',
    ],

    'queue' => [
        'driver' => 'stask',
        'failover' => 'sync',
    ],

    'rate_limit' => [
        'enabled' => true,
        'per_minute' => 60,
    ],

    'limits' => [
        'max_payload_kb' => 256,
    ],

    'logging' => [
        'channel' => 'emcp',
        'audit_enabled' => true,
        'redact_keys' => ['authorization', 'token', 'jwt', 'secret', 'cookie', 'password', 'api_key'],
    ],

    'security' => [
        'allow_servers' => ['*'],
        'deny_tools' => [],
        'enable_write_tools' => false,
    ],

    'domain' => [
        'content' => [
            'max_depth' => 6,
            'max_limit' => 100,
            'max_offset' => 5000,
        ],
        'models' => [
            'allow' => [
                'SiteTemplate', 'SiteTmplvar', 'SiteTmplvarContentvalue',
                'SiteSnippet', 'SitePlugin', 'SiteModule', 'Category',
                'User', 'UserAttribute', 'UserRole', 'Permissions', 'PermissionsGroups', 'RolePermissions',
            ],
        ],
    ],

    'actor' => [
        'mode' => 'initiator',
        'service_username' => 'MCP',
        'service_role' => 'MCP',
        'block_login' => true,
    ],
];
```

## 6.2 `core/custom/config/mcp.php`
`eMCP` uses this file as the MCP server registry.

Example:

```php
return [
    'redirect_domains' => [
        '*',
    ],

    'servers' => [
        [
            'handle' => 'content',
            'transport' => 'web',
            'route' => '/mcp/content',
            'class' => App\Mcp\Servers\ContentServer::class,
            'enabled' => true,
            'auth' => 'sapi_jwt',
            'scopes' => ['mcp:read', 'mcp:call'],
        ],
        [
            'handle' => 'content-local',
            'transport' => 'local',
            'class' => App\Mcp\Servers\ContentServer::class,
            'enabled' => true,
        ],
    ],
];
```

Validation rules:
- `handle` must be unique
- `class` must exist and extend `Laravel\Mcp\Server`
- `transport` must be `web` or `local`
- `enabled=true` to register

Optional per-server overrides:
- `scope_map`
- `limits.max_payload_kb`
- `limits.max_result_items`
- `rate_limit.per_minute`
- `security.deny_tools`

Route semantics:
- Gate A manager endpoint is `/{manager_prefix}/{handle}`.
- `servers[*].route` is the web transport route binding and is externally relevant in API mode (Gate B+).

## 7) Server Registration Model
Upstream Laravel MCP expects `routes/ai.php`.
`eMCP` replaces this with config-first registration for Evo.

Mapping:
- `transport=web` -> `Mcp::web(route, class)`
- `transport=local` -> `Mcp::local(handle, class)`

This keeps upstream runtime behavior while fitting Evo package architecture.

## 7.0) Extension Points (Ecosystem)
- Add server entries in `core/custom/config/mcp.php` with unique `handle`.
- Extend per-server policy via `scope_map`, `limits`, `rate_limit`, `security.deny_tools`.
- Add tools/resources/prompts through generators (`make:mcp-*`) and shared stubs.

## 7.1) Evo Domain Tool Profiles
Canonical source for tool names/params/examples/errors is `TOOLSET.md`.

Primary domain profile is document tree access via `SiteContent`:
- `evo.content.search`
- `evo.content.get`
- `evo.content.root_tree`
- `evo.content.descendants`
- `evo.content.ancestors`
- `evo.content.children`
- `evo.content.siblings`

Advanced (post-MVP) tree tools:
- `evo.content.neighbors`
- `evo.content.prev_siblings`
- `evo.content.next_siblings`
- `evo.content.children_range`
- `evo.content.siblings_range`

TV support is part of the contract:
- `with_tvs` maps to `withTVs`
- structured `tv_filters` maps to `tvFilter`
- structured `tv_order` maps to `tvOrderBy`
- `tags_data` maps to `tagsData`
- `order_by_date` maps to `orderByDate`

Safety constraints:
- reject raw `tvFilter` DSL strings from client payloads
- allow only approved operators/casts
- enforce `depth/limit/offset` caps from config

Model catalog profile (read-only by default):
- `evo.model.list`
- `evo.model.get`

Default allowed models:
- `SiteTemplate`, `SiteTmplvar`, `SiteTmplvarContentvalue`
- `SiteSnippet`, `SitePlugin`, `SiteModule`, `Category`
- `User`, `UserAttribute`, `UserRole`, `Permissions`, `PermissionsGroups`, `RolePermissions`

Sensitive fields are always excluded/redacted:
- `password`, `cachepwd`, `verified_key`, `refresh_token`, `access_token`, `sessionid`

## Implementation Notes

## 8) Access Control

## 8.1 Evo permissions
Migrations should create:
- permission group: `eMCP` (or project-selected shared group)
- permissions:
- `emcp` (access)
- `emcp_manage` (management actions)
- `emcp_dispatch` (async dispatch)

Delivery gates:
- Gate A (MVP): `emcp` is required.
- Gate B+: add `emcp_manage`.
- Gate C+: add `emcp_dispatch`.

Default assignment:
- role `1` (admin) receives all `emcp*` permissions

## 8.2 API scope policy (sApi mode)
Minimum scope policy:
- `mcp:read`: `initialize`, `ping`, list/read/get methods
- `mcp:call`: `tools/call`
- `mcp:admin`: server admin/service actions

Rules:
- If `auth.require_scopes=true`, scope check is mandatory.
- `*` in token scopes grants full MCP access.

## 9) Auth Modes
Supported modes:
- `sapi_jwt` (default): uses `sApi` JWT middleware attributes
- `passport` (optional): OAuth-compatible mode (`mcp:use`)
- `none` (restricted/internal scenarios only)

Behavior contract:
- Passport mode is optional and must degrade safely if Passport is absent.
- Missing optional auth dependencies must not break package boot.

## 10) Routes and Transports

## 10.1 Manager routes
Under `mgr` middleware and `emcp` permission:
- `POST /{manager_prefix}/{server}`
- `POST /{manager_prefix}/{server}/dispatch`

Rules:
- `GET` on MCP endpoint returns `405`
- `POST` expects JSON-RPC body
- pass through `MCP-Session-Id`

## 10.2 API routes (sApi)
Via `McpRouteProvider` (`RouteProviderInterface`):
- `POST /mcp/{server}`
- `POST /mcp/{server}/dispatch`

Recommended middleware chain:
- `sapi.jwt`
- `emcp.scope`
- `emcp.actor`
- `emcp.rate`

Error handling policy:
- transport/auth/middleware failures -> HTTP status (`401/403/405/413/415`) with non-JSON-RPC error body.
- JSON-RPC dispatch failures -> HTTP `200` + JSON-RPC `error` (`-32700`, `-32600`, `-32601`, `-32602`, `-32603`).

For exact normative mapping and error body shape, use `SPEC.md`.

## 10.3 Streaming
When MCP method streams iterable responses, response must use:
- `Content-Type: text/event-stream`
- optional `MCP-Session-Id` response header

Environment notes (Gate B+):
- Nginx/Proxy: disable buffering for MCP streaming routes.
- PHP-FPM/FastCGI: configure output buffering/flush for incremental event delivery.
- Proxy/FPM timeouts must be tuned for long-running streams.

## 11) Async via sTask

## 11.1 Worker registration
If `sTask` is installed, register worker:
- `identifier`: `emcp_dispatch`
- `scope`: `eMCP`
- `class`: `EvolutionCMS\eMCP\sTask\McpDispatchWorker`
- `active`: `true`

## 11.2 Payload contract
Async payload should include:
- `server_handle`
- `jsonrpc_method`
- `jsonrpc_params`
- `request_id`
- `session_id`
- `trace_id`
- `idempotency_key`
- `actor_user_id`
- `initiated_by_user_id`
- `context` (`mgr|api|cli`)
- `attempts`
- `max_attempts`

## 11.3 Failover
If `sTask` is unavailable:
- `queue.failover=sync` -> execute synchronously
- `queue.failover=fail` -> return controlled error

## 12) Actor Resolution
Context identity fields:
- `actor_user_id`
- `initiated_by_user_id`
- `context`
- `trace_id`

Resolution strategy:
- manager mode -> current manager user
- sApi mode -> JWT user (`sapi.jwt.user_id`) when available
- service mode -> dedicated service account (`actor.mode=service`)

## 13) Logging and Audit

## 13.1 Channel
`logging.channels.emcp` daily channel:
- file: `core/storage/logs/emcp-YYYY-MM-DD.log`
- rotation: `LOG_DAILY_DAYS`

## 13.2 Audit fields
Audit events should include:
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

## 13.3 Redaction
Never log raw values of:
- `authorization`
- `token`
- `jwt`
- `secret`
- `cookie`
- `password`
- `api_key`

## 14) Multilingual Support
Required language files:
- `lang/en/global.php`
- `lang/uk/global.php`
- `lang/ru/global.php`

Minimum keys:
- `title`
- `permissions_group`
- `permission_access`
- `permission_manage`
- `permission_dispatch`
- `errors.forbidden`
- `errors.scope_denied`
- `errors.server_not_found`
- `errors.invalid_payload`

## 15) Artisan Commands
Upstream MCP commands expected through adapter:

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool ListResourcesTool
php artisan make:mcp-resource DocsResource
php artisan make:mcp-prompt SummaryPrompt
php artisan mcp:start content-local
php artisan mcp:inspector content-local
```

Planned eMCP operational commands:

```bash
php artisan emcp:test
php artisan emcp:sync-workers
php artisan emcp:list-servers
```

## 16) Troubleshooting
- **401/403 on API calls**:
  check JWT scopes and `auth.require_scopes`.

- **403 in manager**:
  verify role has `emcp` permission.

- **Server not found**:
  verify `mcp.php` entry (`enabled`, `handle`, class path).

- **Streaming not working**:
  check server/client supports SSE and proxy buffering settings.

- **Async dispatch not running**:
  verify `sTask` installed, worker registered, and worker process active.

## 17) Quick File Map
- Product requirements: `PRD.md`
- Technical contract: `SPEC.md`
- Canonical tool contract: `TOOLSET.md`
- Quick start: `README.md`, `README.uk.md`
- Deep docs: `DOCS.md`, `DOCS.uk.md`
- Execution plan: `TASKS.md`
- Security review: `SECURITY_CHECKLIST.md`
- Threat model: `THREAT_MODEL.md`
- Formal audit: `PLATFORM_AUDIT.md`
- Architecture freeze gate: `ARCHITECTURE_FREEZE_CHECKLIST.md`
