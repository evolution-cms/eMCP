<p align="center">
<a href="https://packagist.org/packages/evolution-cms/emcp"><img src="https://img.shields.io/packagist/dt/evolution-cms/emcp" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/evolution-cms/emcp"><img src="https://img.shields.io/packagist/v/evolution-cms/emcp" alt="Latest Stable Version"></a>
<img src="https://img.shields.io/badge/license-MIT-green" alt="License">
</p>

# eMCP for Evolution CMS

`eMCP` is the Evolution CMS integration layer for `laravel/mcp`.

It adapts Laravel MCP to Evo runtime with:
- Evo-native config publishing
- manager ACL and sApi scope controls
- optional async dispatch through sTask
- no Laravel app skeleton requirement
- Evo domain MCP tools for document tree (`SiteContent` + TVs)

Implementation starts with a strict MVP gate:
- web transport
- manager mode
- `initialize` + `tools/list`

If you need full architecture and contracts, see `DOCS.md` (EN) or `DOCS.uk.md` (UA).
Public canonical tool contract: `TOOLSET.md`.
Versioning and BC policy: `PRD.md` (`API Stability Policy` section).

## Requirements
- Evolution CMS 3.5.2+
- PHP 8.4+
- Composer 2.2+

Optional:
- `seiger/sapi` for external MCP API access
- `seiger/stask` for async MCP dispatch
- `laravel/passport` for OAuth-compatible mode

## Install
From your Evo `core` directory:

```bash
cd core
php artisan package:installrequire evolution-cms/emcp "*"
php artisan migrate
```

## Publish Config and Stubs

```bash
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs
```

Published files:
- `core/custom/config/cms/settings/eMCP.php`
- `core/custom/config/mcp.php`
- `core/stubs/mcp-*.stub`

## Install Verification (1 minute)
For Gate A, use manager endpoint `/{manager_prefix}/{server_handle}` (default: `/emcp/content`).
Gate A is manager ACL protected, so run checks as a logged-in manager with `emcp` permission (session cookie required).

1. Verify `GET` returns `405` on MCP endpoint:

```bash
curl -i -X GET http://localhost/<MANAGER_PREFIX>/<SERVER_HANDLE> \
  -H 'Cookie: evo_session=<MANAGER_SESSION_COOKIE>'
```

2. Verify JSON-RPC `initialize`:

```bash
curl -i -X POST http://localhost/<MANAGER_PREFIX>/<SERVER_HANDLE> \
  -H 'Cookie: evo_session=<MANAGER_SESSION_COOKIE>' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0.0"}}}'
```

Expected:
- HTTP `200` for valid `initialize`.
- `MCP-Session-Id` present in response headers.
- stable HTTP `405` on `GET`.

## Register MCP Servers (Evo style)
Unlike Laravel default `routes/ai.php`, eMCP registers servers from config.

Example in `core/custom/config/mcp.php`:

```php
return [
    'redirect_domains' => ['*'],

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

Notes:
- Gate A manager endpoint is still `/{manager_prefix}/{handle}` (for example `/emcp/content`).
- `servers[*].route` is used by web transport registration and becomes externally relevant in API mode (Gate B+).

## Access Model
- Manager/internal access: Evo permission `emcp`
- API access (via sApi): JWT scopes (`mcp:read`, `mcp:call`, `mcp:admin`)
- Optional Passport mode: `mcp:use` compatibility when Passport is installed
- Domain reads (`evo.content.*`, `evo.model.*`) are read-only by default

## Evo Domain Tools (Planned Contract)
- `evo.content.search|get|root_tree|descendants|ancestors|children|siblings`
- Post-MVP: `evo.content.neighbors|prev_siblings|next_siblings|children_range|siblings_range`
- TV-aware queries via structured `with_tvs`, `tv_filters`, `tv_order`
- `evo.model.list|get` for allowlisted Evo models with sensitive-field masking

## Artisan Commands
From Laravel MCP (available via eMCP adapter):

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool ListResourcesTool
php artisan make:mcp-resource DocsResource
php artisan make:mcp-prompt SummaryPrompt
php artisan mcp:start content-local
```

Planned eMCP operational commands:
- `php artisan emcp:test`
- `php artisan emcp:sync-workers`
- `php artisan emcp:list-servers`

## Async (sTask-first)
If `queue.driver=stask` and `sTask` is installed, eMCP can run long MCP calls via worker `emcp_dispatch`.
If sTask is missing, fallback behavior follows `queue.failover` (`sync` or `fail`).

## Security Notes
- Keep secrets in `.env` or `core/custom/config/*`.
- Audit logs must redact tokens/secrets.
- Use tool denylist and server allowlist for production hardening.

## Security Defaults
- deny-by-default for manager/API without explicit access.
- `security.enable_write_tools=false` by default.
- sensitive key redaction in logs is mandatory.
- API scope checks (`mcp:read|call|admin`) are required in Gate B+.
- `depth/limit/payload` limits should stay enabled.

Security release checklist: `SECURITY_CHECKLIST.md`.
Threat model: `THREAT_MODEL.md`.
Architecture freeze: `ARCHITECTURE_FREEZE_CHECKLIST.md`.

## License
MIT (`LICENSE`).
