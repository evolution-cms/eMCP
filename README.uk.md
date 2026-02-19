<p align="center">
<a href="https://packagist.org/packages/evolution-cms/emcp"><img src="https://img.shields.io/packagist/dt/evolution-cms/emcp" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/evolution-cms/emcp"><img src="https://img.shields.io/packagist/v/evolution-cms/emcp" alt="Latest Stable Version"></a>
<img src="https://img.shields.io/badge/license-MIT-green" alt="License">
</p>

# eMCP для Evolution CMS

`eMCP` — інтеграційний шар Evolution CMS для `laravel/mcp`.

Пакет адаптує Laravel MCP під runtime Evo:
- Evo-native publish конфігів
- доступ через manager ACL і sApi scopes
- опційне async виконання через sTask
- без залежності від Laravel app skeleton
- Evo domain MCP tools для дерева документів (`SiteContent` + TVs)

Реалізація стартує з жорсткого MVP gate:
- web transport
- manager mode
- `initialize` + `tools/list`

Повний технічний опис: `DOCS.uk.md` (UA) і `DOCS.md` (EN).
Канонічний публічний tool contract: `TOOLSET.md`.
Versioning і BC policy: `PRD.md` (розділ `API Stability Policy`).

## Вимоги
- Evolution CMS 3.5.2+
- PHP 8.4+
- Composer 2.2+

Опційно:
- `seiger/sapi` для зовнішнього MCP API
- `seiger/stask` для async dispatch
- `laravel/passport` для OAuth-compatible режиму

## Встановлення
З директорії `core` вашого Evo:

```bash
cd core
php artisan package:installrequire evolution-cms/emcp "*"
php artisan migrate
```

## Publish конфігів і stubs

```bash
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs
```

Після publish:
- `core/custom/config/cms/settings/eMCP.php`
- `core/custom/config/mcp.php`
- `core/stubs/mcp-*.stub`

## Перевірка встановлення (1 хвилина)
Для Gate A використовуй manager endpoint `/{manager_prefix}/{server_handle}` (за замовчуванням: `/emcp/content`).
Gate A захищений manager ACL, тому перевірка виконується з manager-сесією користувача, який має permission `emcp`.

1. Перевірити, що `GET` на MCP endpoint повертає `405`:

```bash
curl -i -X GET http://localhost/<MANAGER_PREFIX>/<SERVER_HANDLE> \
  -H 'Cookie: evo_session=<MANAGER_SESSION_COOKIE>'
```

2. Перевірити `initialize` JSON-RPC:

```bash
curl -i -X POST http://localhost/<MANAGER_PREFIX>/<SERVER_HANDLE> \
  -H 'Cookie: evo_session=<MANAGER_SESSION_COOKIE>' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0.0"}}}'
```

Очікування:
- HTTP `200` для валідного `initialize`.
- У відповіді присутній `MCP-Session-Id`.
- На `GET` стабільно повертається `405`.

## Реєстрація MCP серверів (Evo style)
На відміну від стандартного `routes/ai.php` у Laravel, в eMCP сервери реєструються з конфігу.

Приклад `core/custom/config/mcp.php`:

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

Нотатки:
- У Gate A manager endpoint лишається `/{manager_prefix}/{handle}` (наприклад `/emcp/content`).
- `servers[*].route` використовується при реєстрації web transport і стає зовнішньо релевантним у API mode (Gate B+).

## Модель доступу
- Внутрішній доступ (manager): permission `emcp`
- API доступ (через sApi): JWT scopes (`mcp:read`, `mcp:call`, `mcp:admin`)
- Optional Passport mode: сумісність з `mcp:use`, якщо Passport встановлено
- Domain read tools (`evo.content.*`, `evo.model.*`) за замовчуванням read-only

## Evo Domain Tools (Planned Contract)
- `evo.content.search|get|root_tree|descendants|ancestors|children|siblings`
- Post-MVP: `evo.content.neighbors|prev_siblings|next_siblings|children_range|siblings_range`
- TV-aware запити через структуровані `with_tvs`, `tv_filters`, `tv_order`
- `evo.model.list|get` для allowlist моделей Evo з маскуванням чутливих полів

## Artisan команди
Команди з Laravel MCP (через eMCP adapter):

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool ListResourcesTool
php artisan make:mcp-resource DocsResource
php artisan make:mcp-prompt SummaryPrompt
php artisan mcp:start content-local
```

Заплановані операційні команди eMCP:
- `php artisan emcp:test`
- `php artisan emcp:sync-workers`
- `php artisan emcp:list-servers`

## Async через sTask
Якщо `queue.driver=stask` і `sTask` встановлений, довгі MCP виклики виконуються через воркер `emcp_dispatch`.
Якщо `sTask` відсутній — fallback визначається `queue.failover` (`sync` або `fail`).

## Безпека
- Зберігайте секрети у `.env` або `core/custom/config/*`.
- Аудит-логи мають маскувати токени/секрети.
- Для production увімкніть allowlist серверів і denylist tools.

## Security Defaults
- deny-by-default для manager/API без явних прав.
- `security.enable_write_tools=false` за замовчуванням.
- Redaction чутливих ключів у логах обов'язковий.
- Для API (Gate B+) обов'язкові scopes (`mcp:read|call|admin`).
- Ліміти `depth/limit/payload` мають бути увімкнені.

Security release checklist: `SECURITY_CHECKLIST.md`.
Threat model: `THREAT_MODEL.md`.
Architecture freeze: `ARCHITECTURE_FREEZE_CHECKLIST.md`.

## Ліцензія
MIT (`LICENSE`).
