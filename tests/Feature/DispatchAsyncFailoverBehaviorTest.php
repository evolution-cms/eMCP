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
        public static bool $hasTasksTable = false;

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
            return '00000000-0000-4000-8000-000000000123';
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

        public static int $nextId = 1000;

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
            return [
                'http_status' => 200,
                'response' => ['ok' => true],
                'trace_id' => (string)($meta['trace_id'] ?? ''),
            ];
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
        'cms.settings.eMCP.security.deny_tools' => [],
        'cms.settings.eMCP.security.enable_write_tools' => false,
        'cms.settings.eMCP.logging.audit_enabled' => true,
        'cms.settings.eMCP.logging.channel' => 'emcp',
        'mcp.servers' => [
            ['handle' => 'content', 'limits' => ['max_payload_kb' => 256], 'security' => ['deny_tools' => []]],
        ],
    ];

    $registry = new \EvolutionCMS\eMCP\Services\ServerRegistry();
    $security = new \EvolutionCMS\eMCP\Services\SecurityPolicy();
    $idempotency = new \EvolutionCMS\eMCP\Services\IdempotencyStore();
    $execution = new \EvolutionCMS\eMCP\Services\McpExecutionService();
    $audit = new \EvolutionCMS\eMCP\Services\AuditLogger(
        new \EvolutionCMS\eMCP\Support\Redactor(['token', 'secret', 'password'])
    );

    $controller = new \EvolutionCMS\eMCP\Http\Controllers\McpDispatchController(
        $registry,
        $security,
        $idempotency,
        $execution,
        $audit
    );

    Schema::$hasTasksTable = true;
    sTaskModel::$created = [];

    $requestAsync = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'async-1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestAsync->headers->set('Content-Type', 'application/json');
    $requestAsync->headers->set('Idempotency-Key', 'k-async-1');
    $requestAsync->headers->set('X-Trace-Id', 'trace-async-1');

    $respAsync = $controller($requestAsync, 'content');
    assertTrue($respAsync->getStatusCode() === 202, 'Expected 202 for async dispatch when sTask queue is available');
    $payloadAsync = json_decode($respAsync->getContent(), true);
    assertTrue(($payloadAsync['status'] ?? null) === 'accepted', 'Expected accepted status for async dispatch');
    assertTrue((int)($payloadAsync['task_id'] ?? 0) >= 1000, 'Expected queued task id for async dispatch');
    assertTrue(($payloadAsync['reused'] ?? null) === false, 'Expected reused=false for first async dispatch');
    assertTrue(count(sTaskModel::$created) === 1, 'Expected exactly one queued sTask record');

    Schema::$hasTasksTable = false;
    $configValues['cms.settings.eMCP.queue.failover'] = 'sync';

    $requestSync = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'sync-1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestSync->headers->set('Content-Type', 'application/json');
    $requestSync->headers->set('X-Trace-Id', 'trace-sync-1');

    $respSync = $controller($requestSync, 'content');
    assertTrue($respSync->getStatusCode() === 200, 'Expected 200 for sync failover when async queue unavailable');
    $payloadSync = json_decode($respSync->getContent(), true);
    assertTrue(($payloadSync['status'] ?? null) === 'completed', 'Expected completed status in sync failover mode');
    assertTrue(is_array($payloadSync['result'] ?? null), 'Expected embedded result payload in sync failover mode');
    assertTrue(trim((string)($payloadSync['idempotency_key'] ?? '')) !== '', 'Expected generated idempotency key for sync failover');

    $configValues['cms.settings.eMCP.queue.failover'] = 'fail';

    $requestFail = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'fail-1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestFail->headers->set('Content-Type', 'application/json');
    $requestFail->headers->set('X-Trace-Id', 'trace-fail-1');

    $respFail = $controller($requestFail, 'content');
    assertTrue($respFail->getStatusCode() === 503, 'Expected 503 when async queue unavailable and failover=fail');
    $payloadFail = json_decode($respFail->getContent(), true);
    assertTrue(($payloadFail['error']['code'] ?? null) === 'async_unavailable', 'Expected async_unavailable error code');

    echo "Dispatch async/failover behavior checks passed.\n";
}
