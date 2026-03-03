# DOCS — eMCP (Evolution CMS + Laravel MCP)

Цей документ описує, як `eMCP` інтегрує `laravel/mcp` в Evolution CMS.
Це реалізаційний гайд, узгоджений з `PRD.md` і `SPEC.md`.

Contract boundary:
- `SPEC.md` і `TOOLSET.md` є нормативними.
- `DOCS.uk.md` описує імплементацію та експлуатацію цих контрактів.
- `OPERATIONS.md` є day-2 runbook для операторів (profiles, health checks, triage).

Status marker:
- Цільовий контракт: `SPEC 1.0-contract`.
- Runtime baseline в поточному репозиторії: `Gate C baseline implemented (full validation pending)`.

## 1) Огляд
`eMCP` — thin Evo-native адаптер над `laravel/mcp`.

Базові цілі:
- зберегти upstream MCP runtime і протокол
- адаптувати реєстрацію/config/routes під архітектуру Evo
- забезпечити ACL/scopes і enterprise безпеку
- підтримати внутрішнє (manager) та зовнішнє (API) використання

Шари інтеграції:
- **Protocol layer**: `laravel/mcp` (Server, Registrar, transports, JSON-RPC methods)
- **Adapter layer**: `eMCP` provider, registry, middleware, publishing
- **API layer**: опційний `sApi` route provider з JWT scopes
- **Async layer**: опційний `sTask` worker (`emcp_dispatch`)

## 2) Порядок реалізації (risk-first)
Порядок реалізації обовʼязковий:
- Gate A: web transport + manager route + manager ACL (`emcp`) + `initialize` + `tools/list` + `GET=405`
- Gate B: API access layer (scope engine, basic rate limit, `sApi` provider)
- Gate C: async (`sTask` worker, payload contract, failover, idempotency)
- Gate D: optional Passport compatibility
- Gate E: security hardening + DX commands

Перший реліз — тільки Gate A.

## 2.1) Контракт Сумісності З Laravel MCP
eMCP зберігає upstream-поведінку `laravel/mcp` як базу:
- `GET` на MCP transport route повертає `405`.
- `POST` обробляє JSON-RPC повідомлення.
- `MCP-Session-Id` проходить наскрізно request/response.
- `202` зберігається для no-reply notification flows.
- SSE-відповідь має `text/event-stream`, коли streaming увімкнено.
- Upstream command surface (`make:mcp-*`, `mcp:start`, `mcp:inspector`) доступний без змін.

eMCP-логіка є додатковою (ACL/scopes/policies), а не переписуванням протоколу.

## 2.2) Карта Взаємодії В Екосистемі
- `sApi`: зовнішня експозиція MCP endpoint через route providers і JWT scopes.
- `sTask`: async dispatch довгих MCP викликів (`emcp_dispatch` worker).
- `eAi`: AI runtime може споживати eMCP tools у manager/API режимі.
- `dAi`: manager-side orchestration UI може споживати стабільні eMCP tool contracts.

Правило boundary:
- eMCP надає протокол/runtime/policy контракти.
- orchestration-концепції реалізуються у пакетах-споживачах, не в ядрі eMCP.

## 2.3) Швидкий Шлях: Один MCP Server, Внутрішній + Зовнішній
1. Згенеруй базові класи:
```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool HealthTool
```
Згенеровані класи потрапляють у `core/custom/app/Mcp/...`.
2. Додай server entry у `core/custom/config/mcp.php`.
3. Перевір manager/internal виклик:
- `POST /{manager_prefix}/{handle}` з manager-сесією і permission `emcp`.
4. Перевір зовнішній API виклик (якщо встановлено `sApi`):
- `POST /{SAPI_BASE_PATH}/{SAPI_VERSION}/mcp/{handle}`
- `Authorization: Bearer <jwt>` з потрібними `mcp:*` scopes.
5. Опційний async режим:
- `queue.driver=stask` + dispatch endpoint + task progress.

## Посібник користувача

## 3) Вимоги
Обовʼязково:
- Evolution CMS 3.5.2+
- PHP 8.4+
- Composer 2.2+

Опційно:
- `seiger/sapi` для зовнішнього API доступу
- `seiger/stask` для async виконання
- `laravel/passport` для OAuth-compatible режиму

## 4) Встановлення
З директорії `core` Evo:

```bash
cd core
php artisan package:installrequire evolution-cms/emcp "*"
php artisan migrate
```

## 5) Publish ресурсів
Auto-publish може бути увімкнений інсталером, але краще явно виконати:

```bash
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config
php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs
```

Цільові шляхи publish:
- `core/custom/config/cms/settings/eMCP.php`
- `core/custom/config/mcp.php`
- `core/stubs/mcp-server.stub`
- `core/stubs/mcp-tool.stub`
- `core/stubs/mcp-resource.stub`
- `core/stubs/mcp-prompt.stub`

## 6) Довідник конфігів

## 6.1 `core/custom/config/cms/settings/eMCP.php`
Рекомендований baseline:

Мітки по фазах:
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
        'max_result_items' => 100,
        'max_result_bytes' => 1048576,
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
            'max_offset' => 5000,
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
`eMCP` використовує цей файл як registry MCP серверів.

Приклад:

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
            'class' => EvolutionCMS\eMCP\Servers\ContentServer::class,
            'enabled' => true,
            'auth' => 'sapi_jwt',
            'scopes' => ['mcp:read', 'mcp:call'],
        ],
        [
            'handle' => 'content-local',
            'transport' => 'local',
            'class' => EvolutionCMS\eMCP\Servers\ContentServer::class,
            'enabled' => false,
        ],
    ],
];
```

Нотатка:
- `content-local` за замовчуванням вимкнений, щоб уникнути конфлікту дубльованих tool names з `content`.
- Локальний transport вмикай лише коли конфліктні сервери вимкнені або мають різні tool names.

Правила валідації:
- `handle` має бути унікальним
- `class` має існувати і наслідувати `Laravel\Mcp\Server`
- `transport` лише `web` або `local`
- реєструються тільки `enabled=true`

Опційні per-server overrides:
- `scope_map`
- `limits.max_payload_kb`
- `limits.max_result_items`
- `rate_limit.per_minute`
- `security.deny_tools`

Семантика route:
- У Gate A manager endpoint має формат `/{manager_prefix}/{handle}`.
- `servers[*].route` є route-привʼязкою web transport і зовнішньо актуальний у API mode (Gate B+).

## 6.3 Конфіг-Пресети (Практично)
Використовуй ці пресети, щоб швидко стартувати:

- `manager-only`:
  - `mode.internal=true`, `mode.api=false`
  - тільки manager endpoint (`/{manager_prefix}/{handle}`)
- `api-only`:
  - `mode.internal=false`, `mode.api=true`
  - потрібні `sApi` + JWT scopes
- `hybrid` (рекомендований дефолт):
  - `mode.internal=true`, `mode.api=true`
  - один і той самий tool contract для manager і API споживачів

Async-доповнення (для будь-якого пресету):
- `queue.driver=stask` для довгих викликів.
- `queue.failover=sync` для безпечного fallback, якщо `sTask` відсутній.

## 7) Модель реєстрації серверів
Upstream Laravel MCP очікує `routes/ai.php`.
`eMCP` замінює це на config-first модель для Evo.

Мапінг:
- `transport=web` -> `Mcp::web(route, class)`
- `transport=local` -> `Mcp::local(handle, class)`

Це зберігає upstream runtime behavior і відповідає архітектурі Evo-пакетів.

## 7.0) Extension Points (Ecosystem)
- Додати server entries у `core/custom/config/mcp.php` з унікальним `handle`.
- Розширювати per-server policy через `scope_map`, `limits`, `rate_limit`, `security.deny_tools`.
- Додавати tools/resources/prompts через генератори (`make:mcp-*`) і спільні stubs.

## 7.1) Evo Domain Tool Profiles
Канонічне джерело tool names/params/examples/errors: `TOOLSET.md`.

Головний доменний профіль: робота з деревом документів через `SiteContent`:
- `evo.content.search`
- `evo.content.get`
- `evo.content.root_tree`
- `evo.content.descendants`
- `evo.content.ancestors`
- `evo.content.children`
- `evo.content.siblings`

Розширені (post-MVP) tree tools:
- `evo.content.neighbors`
- `evo.content.prev_siblings`
- `evo.content.next_siblings`
- `evo.content.children_range`
- `evo.content.siblings_range`

Підтримка TV входить у контракт:
- `with_tvs` мапиться на `withTVs`
- структурований `tv_filters` мапиться на `tvFilter`
- структурований `tv_order` мапиться на `tvOrderBy`
- `tags_data` мапиться на `tagsData`
- `order_by_date` мапиться на `orderByDate`

Обмеження безпеки:
- заборонити raw `tvFilter` DSL-рядки у клієнтському payload
- дозволити тільки whitelist операторів/cast
- примусово обмежувати `depth/limit/offset` через config

Contract-first стиль виконання:
- кожен tool call іде по pipeline `validate -> authorize -> query -> map -> paginate`
- один tool має відповідати одному явному handler/procedure
- приховані side-effect у transport/controller поза pipeline небажані

Профіль orchestration execution (post-MVP):
- `Intent -> PolicyCheck -> Task(s) -> EvidenceTrace -> ApprovalGate`
- дії планувальника мають бути обмежені policy-valid action set
- зв'язок intent/task/evidence має бути аудитопридатним end-to-end

Профіль model catalog (read-only за замовчуванням):
- `evo.model.list`
- `evo.model.get`

Default allowlist моделей:
- `SiteTemplate`, `SiteTmplvar`, `SiteTmplvarContentvalue`
- `SiteSnippet`, `SitePlugin`, `SiteModule`, `Category`
- `User`, `UserAttribute`, `UserRole`, `Permissions`, `PermissionsGroups`, `RolePermissions`

Чутливі поля завжди маскуються/не віддаються:
- `password`, `cachepwd`, `verified_key`, `refresh_token`, `access_token`, `sessionid`

## Нотатки з імплементації

## 8) Контроль доступу

## 8.1 Evo permissions
Міграції мають створити:
- permission group: `eMCP` (або спільну групу за рішенням проєкту)
- permissions:
- `emcp` (access)
- `emcp_manage` (керування)
- `emcp_dispatch` (async dispatch)

Етапи rollout:
- Gate A (MVP): обовʼязковий `emcp`.
- Gate B+: додати `emcp_manage`.
- Gate C+: додати `emcp_dispatch`.

Дефолтне призначення:
- роль `1` (admin) отримує всі `emcp*` permissions

## 8.2 Scope policy (sApi режим)
Мінімальна політика scopes:
- `mcp:read`: `initialize`, `ping`, list/read/get методи
- `mcp:call`: `tools/call`
- `mcp:admin`: admin/service дії

Правила:
- якщо `auth.require_scopes=true`, перевірка scope обовʼязкова
- `*` у token scopes дає повний MCP доступ

## 9) Режими аутентифікації
Підтримувані режими:
- `sapi_jwt` (default): використовує JWT атрибути `sApi`
- `passport` (optional): OAuth-compatible режим (`mcp:use`)
- `none` (лише для обмежених внутрішніх сценаріїв)

Контракт:
- Passport режим опційний і має безпечно деградувати, якщо Passport не встановлено
- відсутність optional залежностей не повинна ламати boot пакета

## 10) Routes і transports

## 10.1 Manager routes
Під `mgr` middleware і permission `emcp`:
- `POST /{manager_prefix}/{server}`
- `POST /{manager_prefix}/{server}/dispatch`

Правила:
- `GET` на MCP endpoint повертає `405`
- `POST` приймає JSON-RPC body
- підтримується `MCP-Session-Id`

## 10.2 API routes (sApi)
Через `McpRouteProvider` (`RouteProviderInterface`):
- `POST /mcp/{server}`
- `POST /mcp/{server}/dispatch`

Рекомендований middleware chain:
- `emcp.jwt`
- `emcp.scope`
- `emcp.actor`
- `emcp.rate`

`McpRouteProvider` прибирає upstream `sapi.jwt` з MCP route і використовує `emcp.jwt` як єдиний JWT middleware.

Error handling policy:
- transport/auth/middleware помилки -> HTTP status (`401/403/405/413/415`) у non-JSON-RPC форматі.
- JSON-RPC dispatch помилки -> HTTP `200` + JSON-RPC `error` (`-32700`, `-32600`, `-32601`, `-32602`, `-32603`).

Для точного нормативного mapping і формату помилки використовуй `SPEC.md`.

## 10.3 Streaming
Якщо MCP метод стрімить iterable responses, відповідь має бути:
- `Content-Type: text/event-stream`
- опційно `MCP-Session-Id` у response headers

Environment notes (Gate B+):
- Nginx/Proxy: вимкнути buffering для MCP streaming маршрутів.
- PHP-FPM/FastCGI: керувати output buffering/flush, щоб події відправлялись інкрементально.
- Timeout-и proxy/FPM мають бути узгоджені з тривалими stream-викликами.

## 11) Async через sTask

## 11.1 Реєстрація воркера
Якщо `sTask` встановлений, реєструється воркер:
- `identifier`: `emcp_dispatch`
- `scope`: `eMCP`
- `class`: `EvolutionCMS\eMCP\sTask\McpDispatchWorker`
- `active`: `true`

## 11.2 Payload contract
Async payload має містити:
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
Якщо `sTask` відсутній:
- `queue.failover=sync` -> синхронне виконання
- `queue.failover=fail` -> контрольована помилка

## 12) Actor resolution
Контекстні поля ідентичності:
- `actor_user_id`
- `initiated_by_user_id`
- `context`
- `trace_id`

Стратегія резолву:
- manager mode -> поточний manager user
- sApi mode -> JWT user (`sapi.jwt.user_id`) якщо є
- service mode -> окремий service account (`actor.mode=service`)

## 13) Логи і аудит

## 13.1 Канал
`logging.channels.emcp` (daily):
- файл: `core/storage/logs/emcp-YYYY-MM-DD.log`
- ротація: `LOG_DAILY_DAYS`

## 13.2 Поля аудиту
Аудит-події мають містити:
- `timestamp`
- `request_id`
- `trace_id`
- `server_handle`
- `method`
- `status`
- `actor_user_id`
- `context`
- `duration_ms`
- `task_id` (для async)

## 13.3 Redaction
Ніколи не логувати raw значення:
- `authorization`
- `token`
- `jwt`
- `secret`
- `cookie`
- `password`
- `api_key`

## 14) Мультимовність
Обовʼязкові мовні файли:
- `lang/en/global.php`
- `lang/uk/global.php`
- `lang/ru/global.php`

Мінімальні ключі:
- `title`
- `permissions_group`
- `permission_access`
- `permission_manage`
- `permission_dispatch`
- `errors.forbidden`
- `errors.scope_denied`
- `errors.server_not_found`
- `errors.invalid_payload`

## 15) Artisan команди
Очікувані upstream команди через adapter:

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool ListResourcesTool
php artisan make:mcp-resource DocsResource
php artisan make:mcp-prompt SummaryPrompt
php artisan mcp:start content-local
php artisan mcp:inspector content-local
```

Перед `mcp:start content-local` увімкни `content-local` у `core/custom/config/mcp.php` і вимкни конфліктні сервери, якщо вони мають однакові tool names.

Доступні операційні команди eMCP:

```bash
php artisan emcp:test
php artisan emcp:list-servers
php artisan emcp:sync-workers
composer run governance:update-lock
composer run ci:check
EMCP_INTEGRATION_ENABLED=1 EMCP_BASE_URL="https://example.org" EMCP_API_PATH="/api/v1/mcp/{server}" EMCP_API_TOKEN="<jwt>" composer run test:integration:runtime
```

## 16) Troubleshooting
- **401/403 на API викликах**:
  перевір JWT scopes і `auth.require_scopes`.

- **403 у manager**:
  перевір, що роль має permission `emcp`.

- **Server not found**:
  перевір запис у `mcp.php` (`enabled`, `handle`, клас).

- **Streaming не працює**:
  перевір підтримку SSE у клієнта і proxy buffering.

- **Async dispatch не стартує**:
  перевір встановлення `sTask`, реєстрацію воркера і запущений worker process.

## 17) Карта файлів
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
