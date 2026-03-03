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
        public static function hasTable(string $table): bool
        {
            return false;
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
            return '00000000-0000-4000-8000-000000000000';
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
                    return '2026-03-02T00:00:00+00:00';
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
        'cms.settings.eMCP.queue.driver' => 'array',
        'cms.settings.eMCP.queue.failover' => 'fail',
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

    // Case 1: idempotency conflict
    $idempotency->put('k-conflict', [
        'payload_hash' => 'other-hash',
        'task_id' => 42,
        'trace_id' => 'trace-old',
        'status' => 'accepted',
    ]);

    $requestConflict = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'r1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestConflict->headers->set('Content-Type', 'application/json');
    $requestConflict->headers->set('Idempotency-Key', 'k-conflict');
    $requestConflict->headers->set('X-Trace-Id', 'trace-1');
    $requestConflict->headers->set('X-Request-Id', 'req-1');

    $respConflict = $controller($requestConflict, 'content');
    assertTrue($respConflict->getStatusCode() === 409, 'Expected 409 on idempotency conflict');
    $conflictPayload = json_decode($respConflict->getContent(), true);
    assertTrue(($conflictPayload['error']['code'] ?? null) === 'idempotency_conflict', 'Expected idempotency_conflict error code');

    // Case 2: idempotency reuse (same hash)
    $metaForReuse = [
        'server_handle' => 'content',
        'jsonrpc_method' => 'tools/call',
        'jsonrpc_params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
        'actor_user_id' => null,
    ];
    $reuseHash = $idempotency->payloadHash($metaForReuse);
    $idempotency->put('k-reuse', [
        'payload_hash' => $reuseHash,
        'task_id' => 77,
        'trace_id' => 'trace-reuse',
        'status' => 'accepted',
    ]);

    $requestReuse = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'r2',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestReuse->headers->set('Content-Type', 'application/json');
    $requestReuse->headers->set('Idempotency-Key', 'k-reuse');
    $requestReuse->headers->set('X-Trace-Id', 'trace-2');
    $requestReuse->headers->set('X-Request-Id', 'req-2');

    $respReuse = $controller($requestReuse, 'content');
    assertTrue($respReuse->getStatusCode() === 202, 'Expected 202 on idempotency reuse for accepted task');
    $reusePayload = json_decode($respReuse->getContent(), true);
    assertTrue(($reusePayload['reused'] ?? false) === true, 'Expected reused=true');
    assertTrue((int)($reusePayload['task_id'] ?? 0) === 77, 'Expected existing task id to be reused');

    // Case 3: allow_servers deny
    $configValues['cms.settings.eMCP.security.allow_servers'] = ['other-server'];

    $requestDenied = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'r3',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $requestDenied->headers->set('Content-Type', 'application/json');
    $requestDenied->headers->set('X-Trace-Id', 'trace-3');
    $requestDenied->headers->set('X-Request-Id', 'req-3');

    $respDenied = $controller($requestDenied, 'content');
    assertTrue($respDenied->getStatusCode() === 403, 'Expected 403 when server is denied by policy');
    $deniedPayload = json_decode($respDenied->getContent(), true);
    assertTrue(($deniedPayload['error']['code'] ?? null) === 'server_denied', 'Expected server_denied error code');

    echo "Dispatch idempotency behavior checks passed.\n";
}
