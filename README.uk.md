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

Стиль дизайну:
- contract-first (`TOOLSET.md` + валідатори)
- декларативний config-first реєстр серверів (`config/mcp.php`)
- явний handler pipeline (`validate -> authorize -> query -> map -> paginate`)

Повний технічний опис: `DOCS.uk.md` (UA) і `DOCS.md` (EN).
Канонічний публічний tool contract: `TOOLSET.md`.
Versioning і BC policy: `PRD.md` (розділ `API Stability Policy`).
Операційний runbook: `OPERATIONS.md`.

## Вимоги
- Evolution CMS 3.5.2+
- PHP 8.4+
- Composer 2.2+
- `seiger/sapi` 1.x (встановлюється як залежність)
- `seiger/stask` 1.x (встановлюється як залежність)

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

## Швидкий Старт (Внутрішній + Зовнішній)
Базовий контракт нейтральний до конкретної концепції і спирається спочатку на стандартний Laravel MCP.

1. Створи класи MCP server/tool:

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool HealthTool
```
Згенеровані класи створюються у `core/custom/app/Mcp/...`.

2. Зареєструй сервер у `core/custom/config/mcp.php` (`servers[]`).
3. Перевір manager/internal route:
- `POST /{manager_prefix}/{handle}` з manager-сесією і permission `emcp`.
4. Увімкни зовнішній API режим (якщо встановлено `sApi`):
- залиш `mode.api=true` у `core/custom/config/cms/settings/eMCP.php`
- викликай `POST /{SAPI_BASE_PATH}/{SAPI_VERSION}/mcp/{handle}` з Bearer JWT і потрібними `mcp:*` scopes.
- JWT можна отримати через `POST /{SAPI_BASE_PATH}/{SAPI_VERSION}/token` (sApi token endpoint).
5. Опційний async:
- встанови `queue.driver=stask`, перевір наявність `sTask`, використовуй dispatch endpoint для довгих задач.

## Design Philosophy (Optional Reading)
### Навіщо Існує Цей Продукт (4 Базові Питання, Арістотель)
Це короткий спосіб зрозуміти eMCP як продукт, а не просто набір файлів.

1. Матеріальна причина: з чого воно складається (жорсткі межі):
- protocol/runtime від `laravel/mcp`
- Evo adapter layer (`ServiceProvider`, registry, routes, middleware, publish)
- опційні інтеграції доступу та async (`sApi`, `sTask`)
- канонічні контракти (`SPEC.md`, `TOOLSET.md`)

2. Формальна причина: яка форма робить це продуктом (а не компонентами):
- єдиний execution contract від запиту до аудованої відповіді
- єдина policy-модель доступу manager/API (`ACL + scopes + limits`)
- версійований публічний tool contract для споживачів екосистеми
- стабільна extension-модель для сторонніх пакетів

3. Рушійна причина: що приводить систему в рух (workflow + triggers):
- внутрішній тригер: manager MCP виклик (`/{manager_prefix}/{handle}`)
- зовнішній тригер: API MCP виклик (`/{SAPI_BASE_PATH}/{SAPI_VERSION}/mcp/{handle}`)
- async тригер: dispatch у воркер `sTask` для довгих операцій
- життєвий тригер: install/publish/register/test

4. Цільова причина: заради чого це зроблено саме так:
- зберегти семантику Laravel MCP без розривів
- зробити інтеграцію з Evo явною та операційно простою
- підтримати внутрішнє і зовнішнє використання MCP одночасно
- дати нейтральний фундамент для різних orchestration-концепцій поверх eMCP

### Концептуальна Лінза (для архітектурних рішень)
- теорія множин: CMS-дані моделюються як ієрархія множин (site -> nodes -> attributes)
- Пеано/індукція: workflow моделюється як послідовність станів і переходів
- межі Геделя: самореферентні rule-системи потребують жорстких меж між runtime і meta-level

Практичний висновок:
- eMCP лишається contract/runtime шаром
- orchestration-логіка виноситься у пакети-споживачі
- policy/audit/human-gate запобігають небезпечним рекурсивним петлям

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

Нотатки:
- У Gate A manager endpoint лишається `/{manager_prefix}/{handle}` (наприклад `/emcp/content`).
- `servers[*].route` використовується при реєстрації web transport і стає зовнішньо релевантним у API mode (Gate B+).
- `content-local` за замовчуванням вимкнений, щоб уникнути конфлікту дубльованих tool names з `content`.

## Модель доступу
- Внутрішній доступ (manager): permission `emcp`
- API доступ (через sApi): JWT scopes (`mcp:read`, `mcp:call`, `mcp:admin`)
- Domain read tools (`evo.content.*`, `evo.model.*`) за замовчуванням read-only

## Взаємодія В Екосистемі
eMCP є MCP platform layer для екосистеми Evo:
- `LaravelMcp`: upstream протокол/рантайм (зберігаємо як є).
- `sApi`: зовнішній API kernel + JWT + route-provider discovery.
- `sTask`: async виконання через worker/task модель і прогрес.
- `eAi`: AI runtime може викликати MCP tools через manager або API режим.
- `dAi`: manager-side orchestration UI споживає eMCP tools як стабільний контракт.

Це дає нейтральну декларативну основу: один MCP фундамент для різних orchestration-концепцій.

## Evo Domain Tools
- Реалізовано зараз: `evo.content.search|get|root_tree|descendants|ancestors|children|siblings`
- Post-MVP: `evo.content.neighbors|prev_siblings|next_siblings|children_range|siblings_range`
- TV-aware запити через структуровані `with_tvs`, `tv_filters`, `tv_order`
- `evo.model.list|get` реалізовано з явним per-model allowlist projection і додатковим sensitive blacklist захистом

## Artisan команди
Команди з Laravel MCP (через eMCP adapter):

```bash
php artisan make:mcp-server ContentServer
php artisan make:mcp-tool ListResourcesTool
php artisan make:mcp-resource DocsResource
php artisan make:mcp-prompt SummaryPrompt
php artisan mcp:start content-local
```

Для `mcp:start content-local` спочатку увімкни `content-local` у `core/custom/config/mcp.php` і вимкни конфліктні сервери, якщо вони мають однакові tool names.

Операційні команди eMCP:
- `php artisan emcp:test`
- `php artisan emcp:list-servers`
- `php artisan emcp:sync-workers`
- `composer run governance:update-lock`
- `composer run ci:check`

## Перевірки репозиторію (для першого запуску у package workspace)
Якщо перевіряєш цей репозиторій напряму:

```bash
composer run check
make test
composer run ci:check
```

Ці перевірки валідовують `composer.json` і виконують PHP syntax lint по всіх файлах пакета.

One-click демо + повна MCP перевірка:

```bash
make demo-all
```

Ця ціль встановлює demo Evo, запускає `php -S`, видає sApi JWT, виконує `php artisan emcp:test`, а потім `composer run test` з увімкненою HTTP runtime integration перевіркою.

Опційна runtime integration перевірка (проти розгорнутого середовища):

```bash
EMCP_INTEGRATION_ENABLED=1 \
EMCP_BASE_URL="https://example.org" \
EMCP_API_PATH="/api/v1/mcp/{server}" \
EMCP_API_TOKEN="<jwt>" \
EMCP_SERVER_HANDLE="content" \
EMCP_DISPATCH_CHECK=1 \
composer run test:integration:runtime
```

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
