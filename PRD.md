# PRD — eMCP (Master-Слепок + Product Instance)

## 0. Призначення документа
Цей документ є одночасно:
- master-структурою для створення PRD у продукті;
- конкретним PRD для `eMCP` як офіційного MCP platform layer в Evolution CMS.

Нормативна ієрархія:
- `PRD.md` визначає продуктову рамку, цілі, scope, acceptance, метрики.
- `SPEC.md` визначає технічні MUST/SHOULD контракти реалізації.
- `TOOLSET.md` визначає публічний контракт tool names/params/responses/errors.

## 0.1 Поточний статус delivery (as of 2026-03-03)
Підтверджений стан у workspace:
- `make demo-all` проходить end-to-end у `demo`.
- Інсталяційний pipeline (`install/publish/migrate`) проходить стабільно.
- `php artisan emcp:test` (`initialize`, `tools/list`) -> PASS.
- Runtime HTTP integration (`tests/Integration/RuntimeIntegrationHttpTest.php`) -> PASS.
- Підтверджено API шлях читання даних з БД через MCP (`evo.content.search`, `evo.content.root_tree`, `evo.content.get`).
- Автоматично генерується `demo/logs.md` з деталями токена, MCP запитів/відповідей, manual-check командами і негативними probe-кейсами (`401/403/413/415/409/429`, `evo.model.get(User)` sanity).
- `demo/logs.md` додатково включає локальний `sTask` lifecycle proof (`queued -> completed`) через `php artisan stask:worker`.
- Додано RC-hardening test pack у `composer run test`: unit (`ScopePolicy`, `ServerRegistry`), async failover behavior, SiteContent tree/TV contracts, security guardrails, docs/config/commands consistency, upstream adapter smoke.
- Додано streaming policy hardening для PHP-FPM/proxy (`text/event-stream`, `Cache-Control`, `X-Accel-Buffering`) і тести покриття.
- Додано migration matrix automation (`sqlite/mysql/pgsql`) у CI та локальний wrapper `scripts/migration_matrix_check.sh`.
- Додано clean-install validation script (`scripts/clean_install_validation.sh`) і інтеграцію в `demo-runtime-proof`.
- Додано reproducible simulation benchmark suite + leaderboard artifacts (`scripts/benchmark/*`, `build/benchmarks/*`).
- Додано optional advanced tree tools (`neighbors`, `prev/next siblings`, `children/siblings range`) з контрактними та runtime перевірками.
- Додано secret-controlled live hardening probes у `runtime-integration` (negative checks, model sanity, optional `sTask` lifecycle з external-worker режимом).

Залишок до RC-1 (core platform hardening):
- branch protection required-check enforcement для CI runtime jobs (`demo-runtime-proof`, `runtime-integration`) на `release/*`;
- live async checks для `sTask` (progress/result/retry/failover);
- live stream/rate-limit операційні перевірки на зовнішньому target infra;
- cut first RC tag після approval/checklist gate.

## 1. Контекст
- Цільовий пакет: `eMCP` у `/Users/dmi3yy/PhpstormProjects/Extras/eMCP`.
- Upstream MCP SDK: `/Users/dmi3yy/PhpstormProjects/Extras/LaravelMcp` (`laravel/mcp`).
- Snapshot upstream на **2026-02-17**: `v0.5.9`; протоколи `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Суміжні пакети інтеграції:
- `eAi` (thin-wrapper pattern),
- `sApi` (JWT/API kernel),
- `sTask` (async queue/workers),
- `dAi` (manager-side AI consumer).
- Операційні/контрактні референси екосистеми:
- `LaravelAi` (upstream SDK packaging style),
- `ColimaOpenclaw` (contract discipline: deterministic gates, doc-sync, operability-first checks).
- Доменна база Evo: `SiteContent` (closure table + TVs), template/tv/snippet/plugin/module/category/user/permissions моделі.

Формальний статус:
- `eMCP` є official platform layer для MCP в Evolution CMS.
- `eMCP` підпорядковується SemVer і BC policy.
- Підтримуване вікно сумісності upstream: `laravel/mcp ^0.5.x`.

## 2. Проблема
Потрібен стабільний enterprise-рівневий MCP шар для Evo, який:
- не форкає upstream `laravel/mcp`;
- працює в Evo runtime без Laravel app skeleton;
- дає контроль доступу (ACL/scopes/roles), аудит, rate limits, idempotency;
- дає публічний contract-first toolset для дерева документів і Evo-моделей;
- дозволяє будувати керовані orchestration-процеси поверх MCP без прив'язки до однієї концепції.

Наслідки без вирішення:
- нестабільні інтеграції пакетів;
- розрив між API-контрактом і реалізацією;
- високий ризик orchestration/policy drift для розширених сценаріїв;
- складна інтеграція dAi/eAi/sApi/sTask без єдиного execution contract.

## 3. Ціль
### 3.1 Бізнес-ціль
Стандартизувати MCP інтеграцію в Evolution CMS як довгострокову платформу з прогнозованою сумісністю.

### 3.2 Продуктова ціль
Побудувати `eMCP` як contract-first інтеграційний шар:
- Protocol/Adapter/API/Async розділені чіткими boundary;
- `TOOLSET.md` є канонічним data-contract;
- `evo.content.*` та `evo.model.*` дають стабільні read-профілі;
- optional async через `sTask` підтримує traceable Intent→Task→Workflow.

### 3.3 Вимірювані KPI
- `initialize` + `tools/list` + canonical `tools/call` сумісні з MCP клієнтами: `>= 99.5%` успішних запитів на smoke/control наборах.
- `evo.content.search/descendants` на контрольних наборах повертають валідну структуру дерева і TV-дані: `100%` відповідність golden fixtures.
- Доля async задач через `sTask`, що завершуються без ручного ретраю: `>= 95%`.
- Critical secret leakage у логах/відповідях: `0`.
- Для orchestration сценаріїв: `policy violations` у workflow не вище погодженого baseline.

## 4. Scope
### 4.1 In Scope
- Пакет `eMCP` (`ServiceProvider`, plugin, config, routes, lang, migrations, middleware, tools).
- Manager access layer (ACL `emcp`) і API access layer (`sApi` scopes).
- Optional async layer через `sTask` (`emcp_dispatch`, failover, idempotency).
- Публічний domain toolset:
- `evo.content.*` для tree/TV profile,
- `evo.model.list|get` для allowlisted model catalog.
- Contract-first валідація structured payload (без raw DSL/SQL fragments).
- Audit/logging/redaction/rate limit/limits governance.

### 4.2 Out of Scope (v1)
- Повний GUI-конструктор MCP schema/tool definitions.
- Marketplace MCP серверів.
- Повна standalone OAuth authorization server реалізація.
- Автоматичне ввімкнення write-tools без explicit opt-in.

### 4.3 Delivery Constraints
- Risk-first гейти: Gate A -> Gate B -> Gate C -> Gate D -> Gate E.
- MVP v0.1 обмежений: `web` + `manager` + `initialize/tools:list/405`.
- Жодної обов'язкової залежності на `Passport` для boot у clean Evo.

### 4.4 Ecosystem Compatibility (mandatory)
- Сумісність з `LaravelMcp`: протокольна поведінка (`GET=405`, `MCP-Session-Id`, JSON-RPC) не ламається.
- Сумісність з `sApi`: зовнішній доступ реалізується через route-provider + JWT scopes.
- Сумісність з `sTask`: async не створює власного queue-framework, а використовує worker/task модель.
- Сумісність з `eAi`/`dAi`: eMCP дає стабільний tool contract для споживачів, без прив'язки до конкретної orchestration стратегії.

### 4.5 Compliance Profiles (mandatory split)
- `Core Platform Contract` (required for eMCP v1 compliance):
- transport/protocol parity, registry, ACL/scopes/rate/limits, canonical `evo.content.*` + `evo.model.*`, BC/SemVer discipline.
- `Orchestration Extension Profile` (optional, post-MVP):
- Intent/Policy/Evidence/Approval/Simulation layers for higher-level consumers.
- Нормативний маркер: orchestration entities are **NOT required** for eMCP core compliance.

### 4.6 Non-Goals For v1 Core Platform
- Не додавати orchestration-specific persistence (`Intent`, `PolicyCheck`, `EvidenceTrace`, `SimulationEpisode`) у core runtime.
- Не додавати non-deterministic benchmark workloads у core до завершення RC-1 (дозволений лише reproducible simulation baseline evidence).
- Не ламати canonical domain toolset; optional additive tools допускаються тільки без breaking-змін.

## 5. Сутності
### 5.1 Platform Entities
- `McpServer` (handle, transport, policy overrides).
- `ToolContract` (name, args schema, response schema, error mapping, toolsetVersion).
- `ActorContext` (user/service actor, scopes, role, trace).
- `PolicyGuard` (ACL/scopes/rate/limits/denylist).
- `AuditEvent` (request, intent, outcome, redaction-safe fields).

### 5.2 Domain Entities (Evo)
- `SiteContent` як вузол дерева.
- `TV` як розширення запису (`with_tvs`, `tv_filters`, `tv_order`, `tags_data`).
- `ClosureRelation` (ancestors/descendants/depth/neighbors semantics).
- `ModelCatalogEntity` (allowlisted Evo models + field projection policy).

### 5.3 Orchestration Entities (optional execution profile)
- `Intent` (що має бути виконано, з versioned policy context).
- `EvidenceTrace` (які факти/контекст/правила застосовані).
- `Task` (керована дія у workflow, включно з async).
- `WorkflowState` (draft/validated/queued/running/done/failed/rejected).
- `PolicyCheckResult` (allow/deny + violated rules).
- `ApprovalGate` (approve/reject/escalate).
- `SimulationEpisode` та `StrategyScore` для benchmark/leaderboard.

## 6. Взаємодія
### 6.1 Базовий runtime chain
`Request -> Validate -> Authorize -> Execute -> Map -> Paginate -> Respond -> Audit`.

### 6.2 Станова модель виконання
- `received` -> `validated` -> `authorized` -> `executed` -> `responded`.
- Будь-який fail -> `rejected|failed` з traceable error class.

### 6.3 Orchestration execution chain (optional profile)
`Intent -> PolicyCheck -> Task(s) -> Workflow -> Audit/Evidence -> ApprovalGate (за правилом)`.

### 6.4 Closure/Table і TV semantics
- Tree traversal лише через структуровані selectors (`ancestors/descendants/children/siblings/...`).
- TV-фільтрація/сортування через typed structured payload, не через raw client DSL.

## 7. User Stories
- Як інтегратор, я підключаю `eMCP` і отримую сумісний MCP endpoint без Laravel skeleton.
- Як адміністратор, я керую доступом через `emcp` + `mcp:*` scopes і бачу audit trail.
- Як dAi/eAi агент, я читаю дерево `SiteContent` і TV-поля через стабільні tools без прямого SQL.
- Як SecOps, я гарантую redaction секретів і deny-by-default політику.
- Як власник платформи, я використовую eMCP як нейтральний execution-contract для різних orchestration підходів.
- Як RnD команда, я порівнюю стратегії через simulation episodes і leaderboard метрики.

## 8. Воркфлоу
### 8.1 Sync tool workflow (еталон)
- Кожен tool-call реалізує pipeline `validate -> authorize -> query -> map -> paginate`.
- `evo.content.search` є референсним сценарієм для tree + TV + pagination.

### 8.2 Async workflow (Gate C+)
- `dispatch` створює task payload із actor/context/trace/idempotency.
- Worker `emcp_dispatch` виконує виклик.
- Результат і прогрес фіксуються у task/result store.

### 8.3 Orchestration workflow (extension profile)
- Контекст збирається з документа, дерева, атрибутів, прав.
- Policy layer визначає `valid_actions`.
- Оркестратор (LLM або rule-based strategy) формує `Intent` лише в межах valid actions.
- Intent матеріалізується у `Task(s)`.
- Кожен крок має evidence trace і статус workflow.

### 8.4 Benchmark workflow
- Набір контрольних епізодів (incident/SLA/regulatory/resource conflict/risk zone).
- Запуск baseline + strategy A/B/C на тих самих епізодах.
- Обчислення composite score або Elo/TrueSkill.
- Публікація leaderboard і drift-порівняння.

## 9. Тригери
### 9.1 Продуктові
- Нова стратегічна ініціатива.
- Падіння ключових метрик або SLA.
- Запит від бізнесу/регулятора.

### 9.2 Технічні
- `initialize`, `tools/list`, `tools/call`, `dispatch`.
- Policy violations або idempotency conflict.
- Queue failover events, worker health degradation.

### 9.3 Orchestration
- Вхід нової policy версії.
- Detection policy drift або аномального рішення strategy.
- Approval gate approve/reject/escalate.

## 10. Нефункціональні вимоги
- Сумісність: Evolution CMS `^3.5.2`, PHP `^8.3`, Illuminate `12.*`.
- Заборона прямої залежності на `laravel/framework`/`illuminate/foundation`.
- Безпека: deny-by-default, redaction, allowlist/denylist.
- Продуктивність: bounded `depth/limit/offset`, bounded result size, pagination required.
- Масштабованість: async через `sTask`, deterministic failover.
- Спостережуваність: `trace_id`, `task_id`, `request_id` у кожному ключовому журналі/події.
- Тестованість: golden fixtures + policy tests + integrity checks.
- UX/DX: стабільні помилки, чіткі коди, передбачуваний контракт без прихованої магії.

## 11. Acceptance Criteria
### 11.1 MVP Gate (v0.1)
- AC1: Пакет встановлюється і завантажується в clean Evo без fatals.
- AC2: Publish створює `core/custom/config/mcp.php` і `core/custom/config/cms/settings/eMCP.php`.
- AC3: `GET` на MCP endpoint повертає `405`; `POST` обробляє JSON-RPC.
- AC4: `initialize` повертає валідний MCP handshake + `MCP-Session-Id`.
- AC5: `tools/list` працює стабільно для активного server handle.
- AC6: Manager route без `emcp` permission повертає `403`.

### 11.2 Post-MVP gates
- AC7: API route без потрібного scope повертає `401/403`.
- AC8: `mcp:read` дозволяє list/read, але блокує `tools/call` без `mcp:call`.
- AC9: Passport не використовується і не є необхідним для роботи пакета.
- AC10: `make:mcp-*` і `mcp:start` працюють у Evo runtime.
- AC11: Worker `emcp_dispatch` авто-реєструється, якщо `sTask` встановлений.
- AC12: Async payload містить actor/context/trace/idempotency поля.
- AC13: `queue.failover=sync` дає синхронний fallback при відсутності `sTask`.
- AC14: Локалізації `en/uk/ru` покривають manager/error/permissions ключі.
- AC15: Audit log не містить raw bearer token та секретів.

### 11.3 Domain/orchestration contract
- AC16: `evo.content.search|get|root_tree|descendants|ancestors|children|siblings` повертають валідні дані на `SiteContent`.
- AC17: `tv_filters`/`tv_order` працюють тільки з allowlisted operators/casts.
- AC18: Raw `tvFilter` DSL у клієнтському payload відхиляється.
- AC19: `evo.model.list|get` не віддають чутливі поля.
- AC20: Write-tools disabled-by-default і вимагають explicit allow + `emcp_manage` + `mcp:admin`.
- AC21: `initialize` повертає `serverInfo.platform/platformVersion` + `capabilities.evo.toolsetVersion`.
- AC22: Ecosystem package може підключити server/tool/policy без змін ядра eMCP.
- AC23: Per-server overrides (`scope_map`, `limits`, `rate_limit`, `security.deny_tools`) реально впливають на runtime.
Core compliance boundary:
- AC16-AC23 are mandatory for core platform compliance.
- AC24: Intent→Task workflow зберігає trace/evidence/policy-check статуси.
- AC25: Approval gate може approve/reject/escalate і це аудитується.
- AC26: Simulation benchmark повторюваний і порівнює baseline + альтернативні стратегії.
Extension compliance boundary:
- AC24-AC26 belong to orchestration extension profile and are not required for eMCP core compliance.

## 12. Метрики
### 12.1 Runtime
- Success rate MCP базових викликів (`initialize`, `tools/list`, canonical `tools/call`).
- P95 latency для `evo.content.search` і `evo.content.descendants`.
- Async completion rate / retry rate / failover rate.

### 12.2 Безпека
- Кількість policy violation блокувань.
- Кількість секретів, знайдених у логах/відповідях (ціль: `0`).

### 12.3 Orchestration effectiveness
- Time-to-decision і time-to-resolution.
- Частка задач, повернутих з аудиту як некоректні.
- Drift-detection lead time (на скільки раніше система помічає policy drift).
- Порівняння strategy score відносно baseline на контрольному наборі епізодів.

## 13. Формальна рамка PRD (master-структура)
Канонічна структура для будь-якого PRD:
1. Контекст
2. Проблема
3. Ціль
4. Scope
5. Сутності
6. Взаємодія
7. User Stories
8. Воркфлоу
9. Тригери
10. Нефункціональні вимоги
11. Acceptance Criteria
12. Метрики

Головний ланцюг мислення продуктом:
`Проблема -> Намір -> Механіка -> Результат`.

Чотири причини (why this shape):
- Матеріальна: з яких блоків складається система.
- Формальна: як блоки організовані у стани/переходи.
- Рушійна: які події запускають систему.
- Цільова: яку вимірювану цінність система створює.

## 14. Contract-First архітектурний профіль (COBOL mapping)
Відповідність "COBOL-style clarity" для eMCP:
- `DATA DIVISION` -> `Contracts/Data`:
- `TOOLSET.md` як публічний data-contract;
- typed DTO/validation contracts для аргументів/відповідей.
- `PROCEDURE DIVISION` -> `Procedures/Handlers`:
- 1 tool = 1 handler з pipeline `validate -> authorize -> query -> map -> paginate`.
- `ENVIRONMENT DIVISION` -> `Runtime/Config`:
- runtime guards: ACL/scopes/rate/limits/redaction/idempotency/failover.
- `FILE SECTION` -> `Mappers`:
- стабільні mapper'и для `SiteContent` + TV projection + tree projection.

Прийняті рішення для v1:
- canonical джерело контракту: `TOOLSET.md` + typed PHP validators у коді;
- eMCP лишається adapter-first платформою;
- будь-яка важка orchestration-логіка ізолюється у workflow/policy шар, не в transport layer;
- default шлях даних: Eloquent scopes; для hot-path допустимий query builder optimization без зміни публічного контракту.

## 15. Functional Contract (FR1-FR26)
Для узгодженості зі `SPEC.md` і `TASKS.md` фіксуються ID вимог.

### 15.1 Platform/Transport (FR1-FR15)
- FR1: Install via `php artisan package:installrequire evolution-cms/emcp "*"`.
- FR2: Publish config to `core/custom/config/mcp.php` and `core/custom/config/cms/settings/eMCP.php`.
- FR3: Config-first MCP server registration on boot.
- FR4: Support `web` transport (MVP), `local` transport (post-MVP).
- FR5: `MCP-Session-Id` passthrough mandatory; streaming only Gate B+.
- FR6: Manager access requires Evo permission `emcp`.
- FR7: API access via `sApi` + scope checks.
- FR8: Scope map minimum: `mcp:read`, `mcp:call`, `mcp:admin`.
- FR9: Passport не входить у runtime/auth модель eMCP.
- FR10: `sTask` worker `emcp_dispatch` for async calls.
- FR11: Async payload includes actor/context/audit fields.
- FR12: Safe audit log with redaction.
- FR13: Rate limits + payload size limits.
- FR14: Idempotency for async dispatch with `409` conflict semantics.
- FR15: Multilingual manager/error/permissions keys (`en/uk/ru`).

### 15.2 Domain/Extension (FR16-FR26)
- FR16: Canonical `evo.content.*` tool profile (`search/get/root_tree/descendants/ancestors/children/siblings`).
- FR17: TV support via structured params (`with_tvs`, `tv_filters`, `tv_order`, `tags_data`).
- FR18: Enforced limits (`depth`, `limit`, `offset`) and allowlisted sort columns.
- FR19: Strict operator/cast validation; no raw SQL/DSL injection surface.
- FR20: Read-only `evo.model.list|get` with model allowlist.
- FR21: Sensitive field masking/exclusion for protected entities.
- FR22: Write-tools disabled-by-default and require explicit multi-gate authorization.
- FR23: Advanced closure-table tools as optional additive profile (`neighbors`, `prev/next`, `*_range`).
- FR24: `initialize` returns mandatory platform metadata.
- FR25: Official extension points for ecosystem packages.
- FR26: Per-server runtime policy overrides are supported.

## 16. Ризики у форматі "докази зрілості"
### 16.1 Tree/closure-table maturity evidence
- Move/Copy/Delete обробляються транзакційно.
- Перевіряються інваріанти дерева: відсутність циклів, коректний `depth`, валідні ancestor/descendant зв'язки.
- Є регулярний integrity check/healthcheck і алерти при дрейфі.

### 16.2 TV/query maturity evidence
- Structured TV policy не допускає raw DSL у payload.
- Є performance-профілі для hot TV filters/order.
- Для heavy cases є стратегія оптимізації (індекси/materialized/denormalized projections).

### 16.3 Access/policy maturity evidence
- Чітко визначено модель прав (RBAC/ABAC/hybrid) і точку enforcement (query layer/global scope/middleware/service).
- Є policy tests: рішення не може пройти, якщо порушено rule.

### 16.4 Orchestration maturity evidence
- Є benchmark suite сценаріїв (інцидент, регуляторика, SLA, конфлікти пріоритетів, дефіцит ресурсів).
- Кожен сценарій має expected allowed-actions і forbidden-actions.
- Є leaderboard стратегій (baseline, LLM planner, LLM+retrieval, LLM+policy memory) з відтворюваними метриками.

## 17. API Stability Policy
- eMCP версіонується за SemVer.
- `evo.content.*` і `evo.model.*` є публічними stable namespace.
- Перейменування/видалення canonical tools можливе тільки в `MAJOR`.
- Зміна error semantics вимагає `MAJOR`.
- Додавання нових optional params/tools дозволене в `MINOR`.
- Заборонено робити optional -> required у `MINOR`.
- Для breaking змін обов'язковий deprecation цикл мінімум один `MINOR`.

## 18. Definition of Done і відкриті питання
### 18.1 DoD
- `PRD.md`, `SPEC.md`, `TOOLSET.md` узгоджені без контрактних конфліктів.
- MVP Gate A проходить end-to-end smoke (`initialize/tools:list/405/ACL`).
- Domain contract покритий golden fixtures і базовими security tests.
- Async/policy/orchestration гейти мають формалізовані AC і метрики.

### 18.2 Open questions (для наступного рев'ю)
- Чи виділяємо окрему таблицю `Intent` у v1, чи запускаємо через payload `sTask` + audit schema без нової таблиці.
- Яка стратегія benchmark даних пріоритетна першою: історичний replay чи синтетичні сценарії.
- Які саме policy dimensions входять у перший обов'язковий orchestration score.
