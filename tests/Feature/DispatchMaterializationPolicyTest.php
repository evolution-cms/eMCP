<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    final class Cache
    {
        /** @var array<string, mixed> */
        public static array $store = [];

        public static function get(string $key): mixed
        {
            return self::$store[$key] ?? null;
        }

        public static function put(string $key, mixed $value, mixed $ttl = null): void
        {
            self::$store[$key] = $value;
        }
    }

    final class Schema
    {
        public static bool $hasTasksTable = true;

        public static function hasTable(string $table): bool
        {
            return $table === 's_tasks' && self::$hasTasksTable;
        }
    }

    final class Log
    {
        public static function channel(string $channel): object
        {
            return new class {
                public function info(string $message, array $context = []): void
                {
                }
            };
        }
    }
}

namespace Illuminate\Support {
    final class Str
    {
        public static function is(string $pattern, string $value): bool
        {
            if ($pattern === '*') {
                return true;
            }

            $quoted = preg_quote($pattern, '/');
            $regex = '/^' . str_replace('\\*', '.*', $quoted) . '$/u';

            return (bool)preg_match($regex, $value);
        }

        public static function uuid(): string
        {
            return '00000000-0000-4000-8000-000000000777';
        }
    }
}

namespace Illuminate\Http {
    final class HeaderBag
    {
        /** @var array<string, string> */
        private array $headers = [];

        public function set(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }

        public function get(string $name, mixed $default = null): mixed
        {
            return $this->headers[strtolower($name)] ?? $default;
        }
    }

    final class AttributeBag
    {
        /** @var array<string, mixed> */
        private array $attrs = [];

        public function set(string $key, mixed $value): void
        {
            $this->attrs[$key] = $value;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->attrs[$key] ?? $default;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->attrs);
        }
    }

    final class JsonBag
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data)
        {
        }

        /** @return array<string, mixed> */
        public function all(): array
        {
            return $this->data;
        }
    }

    class Request
    {
        public HeaderBag $headers;
        public AttributeBag $attributes;

        /** @var array<string, mixed> */
        private array $json;
        private string $content;

        public function __construct(string $content = '')
        {
            $this->headers = new HeaderBag();
            $this->attributes = new AttributeBag();
            $this->content = $content;
            $decoded = json_decode($content, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }

        public function json(): JsonBag
        {
            return new JsonBag($this->json);
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function header(string $name, mixed $default = null): mixed
        {
            return $this->headers->get($name, $default);
        }

        public function ip(): string
        {
            return '127.0.0.1';
        }
    }

    class JsonResponse
    {
        public HeaderBag $headers;

        /** @param array<string, mixed> $payload */
        public function __construct(private array $payload, private int $status)
        {
            $this->headers = new HeaderBag();
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getContent(): string
        {
            return (string)json_encode($this->payload, JSON_UNESCAPED_SLASHES);
        }
    }
}

namespace Seiger\sTask\Models {
    final class sTaskModel
    {
        public const TASK_STATUS_QUEUED = 'queued';

        public static int $nextId = 5000;

        /** @var array<int, array<string, mixed>> */
        public static array $created = [];

        public int $id = 0;

        public static function query(): object
        {
            return new class {
                /** @param array<string, mixed> $payload */
                public function create(array $payload): sTaskModel
                {
                    $model = new sTaskModel();
                    $model->id = sTaskModel::$nextId;
                    sTaskModel::$nextId++;
                    sTaskModel::$created[] = $payload;

                    return $model;
                }
            };
        }
    }
}

namespace EvolutionCMS\eMCP\Services {
    class ServerRegistry
    {
        public function resolveWebServerClassByHandle(string $handle): ?string
        {
            return $handle === 'content' ? 'DummyServer' : null;
        }
    }

    class McpExecutionService
    {
        /** @param array<string, mixed> $meta */
        public function call(array $meta): array
        {
            return ['http_status' => 200, 'response' => ['ok' => true], 'trace_id' => (string)($meta['trace_id'] ?? '')];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\Schema;
    use Seiger\sTask\Models\sTaskModel;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** @var array<string, mixed> $configValues */
    $configValues = [];

    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            global $configValues;

            return $configValues[$key] ?? $default;
        }
    }

    if (!function_exists('response')) {
        function response(): object
        {
            return new class {
                /** @param array<string, mixed> $payload */
                public function json(array $payload, int $status = 200): \Illuminate\Http\JsonResponse
                {
                    return new \Illuminate\Http\JsonResponse($payload, $status);
                }
            };
        }
    }

    if (!function_exists('now')) {
        function now(): object
        {
            return new class {
                public function toIso8601String(): string
                {
                    return '2026-03-03T00:00:00+00:00';
                }
            };
        }
    }

    require_once __DIR__ . '/../../src/Support/TraceContext.php';
    require_once __DIR__ . '/../../src/Support/TransportError.php';
    require_once __DIR__ . '/../../src/Support/RateLimitIdentityResolver.php';
    require_once __DIR__ . '/../../src/Support/Redactor.php';
    require_once __DIR__ . '/../../src/Services/AuditLogger.php';
    require_once __DIR__ . '/../../src/Services/SecurityPolicy.php';
    require_once __DIR__ . '/../../src/Services/IdempotencyStore.php';
    require_once __DIR__ . '/../../src/Http/Controllers/McpDispatchController.php';

    $configValues = [
        'cms.settings.eMCP.trace.header' => 'X-Trace-Id',
        'cms.settings.eMCP.trace.generate_if_missing' => true,
        'cms.settings.eMCP.idempotency.header' => 'Idempotency-Key',
        'cms.settings.eMCP.idempotency.ttl_seconds' => 86400,
        'cms.settings.eMCP.idempotency.storage' => 'cache',
        'cms.settings.eMCP.queue.driver' => 'stask',
        'cms.settings.eMCP.queue.failover' => 'sync',
        'cms.settings.eMCP.security.allow_servers' => ['content'],
        'cms.settings.eMCP.security.deny_tools' => ['evo.model.*'],
        'cms.settings.eMCP.security.enable_write_tools' => false,
        'cms.settings.eMCP.logging.audit_enabled' => true,
        'cms.settings.eMCP.logging.channel' => 'emcp',
        'mcp.servers' => [
            ['handle' => 'content', 'limits' => ['max_payload_kb' => 256], 'security' => ['deny_tools' => []]],
        ],
    ];

    $controller = new \EvolutionCMS\eMCP\Http\Controllers\McpDispatchController(
        new \EvolutionCMS\eMCP\Services\ServerRegistry(),
        new \EvolutionCMS\eMCP\Services\SecurityPolicy(),
        new \EvolutionCMS\eMCP\Services\IdempotencyStore(),
        new \EvolutionCMS\eMCP\Services\McpExecutionService(),
        new \EvolutionCMS\eMCP\Services\AuditLogger(new \EvolutionCMS\eMCP\Support\Redactor(['token', 'secret', 'password']))
    );

    Schema::$hasTasksTable = true;
    sTaskModel::$created = [];

    $request = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'm1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1, 'offset' => 0]],
    ], JSON_UNESCAPED_SLASHES));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Idempotency-Key', 'materialize-k1');
    $request->headers->set('MCP-Session-Id', 'session-42');
    $request->headers->set('X-Trace-Id', 'trace-materialize-1');
    $request->attributes->set('emcp.actor_user_id', 99);
    $request->attributes->set('emcp.context', 'api');

    $response = $controller($request, 'content');
    assertTrue($response->getStatusCode() === 202, 'Dispatch should materialize async task in queue mode');
    assertTrue(count(sTaskModel::$created) === 1, 'Exactly one task must be materialized');

    $task = sTaskModel::$created[0] ?? [];
    $meta = is_array($task['meta'] ?? null) ? $task['meta'] : [];

    $requiredMetaKeys = [
        'server_handle',
        'jsonrpc_method',
        'jsonrpc_params',
        'request_id',
        'session_id',
        'trace_id',
        'idempotency_key',
        'actor_user_id',
        'initiated_by_user_id',
        'context',
        'attempts',
        'max_attempts',
        'payload_hash',
    ];

    foreach ($requiredMetaKeys as $key) {
        assertTrue(array_key_exists($key, $meta), "Missing materialized meta key: {$key}");
    }

    assertTrue(($meta['server_handle'] ?? null) === 'content', 'meta.server_handle must match route handle');
    assertTrue(($meta['jsonrpc_method'] ?? null) === 'tools/call', 'meta.jsonrpc_method must capture JSON-RPC method');
    assertTrue(is_array($meta['jsonrpc_params'] ?? null), 'meta.jsonrpc_params must be normalized array');
    assertTrue(($meta['request_id'] ?? null) === 'm1', 'meta.request_id must capture JSON-RPC id');
    assertTrue(($meta['session_id'] ?? null) === 'session-42', 'meta.session_id must capture MCP-Session-Id');
    assertTrue(($meta['trace_id'] ?? null) === 'trace-materialize-1', 'meta.trace_id must capture trace context');
    assertTrue(($meta['idempotency_key'] ?? null) === 'materialize-k1', 'meta.idempotency_key must capture header key');
    assertTrue((int)($meta['actor_user_id'] ?? 0) === 99, 'meta.actor_user_id must capture actor id');
    assertTrue((int)($meta['initiated_by_user_id'] ?? 0) === 99, 'meta.initiated_by_user_id must capture initiator');
    assertTrue(($meta['context'] ?? null) === 'api', 'meta.context must capture request context');
    assertTrue((int)($meta['attempts'] ?? -1) === 0, 'meta.attempts must initialize to 0');
    assertTrue((int)($meta['max_attempts'] ?? 0) >= 1, 'meta.max_attempts must be positive');
    assertTrue(trim((string)($meta['payload_hash'] ?? '')) !== '', 'meta.payload_hash must be populated');

    $requestDenied = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'm2',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.model.list', 'arguments' => ['model' => 'User', 'limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestDenied->headers->set('Content-Type', 'application/json');
    $requestDenied->headers->set('X-Trace-Id', 'trace-materialize-2');

    $deniedResponse = $controller($requestDenied, 'content');
    assertTrue($deniedResponse->getStatusCode() === 403, 'Denied tool must be rejected before task materialization');
    assertTrue(count(sTaskModel::$created) === 1, 'Denied tool must not create additional tasks');

    $requestMissingMethod = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'm3',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestMissingMethod->headers->set('Content-Type', 'application/json');

    $missingMethodResponse = $controller($requestMissingMethod, 'content');
    assertTrue($missingMethodResponse->getStatusCode() === 400, 'Missing method must fail with 400');

    echo "Dispatch materialization guardrail checks passed.\n";
}
