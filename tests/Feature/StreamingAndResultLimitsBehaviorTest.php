<?php

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    class HeaderBag
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

    class Response
    {
        public HeaderBag $headers;

        public function __construct(private string $content = '', private int $status = 200)
        {
            $this->headers = new HeaderBag();
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function setContent(string $content): void
        {
            $this->content = $content;
        }
    }

    class StreamedResponse extends Response
    {
        public function __construct(int $status = 200)
        {
            parent::__construct('', $status);
        }
    }
}

namespace Illuminate\Http {
    use Symfony\Component\HttpFoundation\Response;

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

    class JsonResponse extends Response
    {
        /** @param array<string, mixed> $payload */
        public function __construct(array $payload, int $status = 200)
        {
            parent::__construct((string)json_encode($payload, JSON_UNESCAPED_SLASHES), $status);
        }
    }
}

namespace Illuminate\Support\Facades {
    final class Log
    {
        public static function channel(string $channel): object
        {
            return new class {
                public function error(string $message, array $context = []): void
                {
                }

                public function info(string $message, array $context = []): void
                {
                }
            };
        }
    }
}

namespace Laravel\Mcp\Server\Transport {
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    final class HttpTransport
    {
        public static ?Response $nextResponse = null;

        public function __construct(Request $request, string $sessionId)
        {
        }

        public function run(): Response
        {
            if (self::$nextResponse === null) {
                throw new \RuntimeException('HttpTransport::$nextResponse is not configured');
            }

            return self::$nextResponse;
        }
    }
}

namespace EvolutionCMS\eMCP\Tests\Stubs {
    final class DummyServer
    {
        public function __construct(mixed $transport = null)
        {
        }

        public function start(): void
        {
        }
    }
}

namespace EvolutionCMS\eMCP\Services {
    class ServerRegistry
    {
        public function resolveWebServerClassByHandle(string $handle): ?string
        {
            return $handle === 'content' ? \EvolutionCMS\eMCP\Tests\Stubs\DummyServer::class : null;
        }
    }

    class SecurityPolicy
    {
        public function isServerAllowed(string $serverHandle): bool
        {
            return true;
        }

        /** @param array<string,mixed> $jsonRpc */
        public function resolveToolName(array $jsonRpc): ?string
        {
            return null;
        }

        public function isToolDenied(string $serverHandle, string $toolName): bool
        {
            return false;
        }
    }

    class AuditLogger
    {
        public function log(
            \Illuminate\Http\Request $request,
            string $server,
            string $method,
            int $status,
            float $startedAt,
            ?int $taskId = null,
            array $extra = []
        ): void {
        }
    }
}

namespace {
    use Laravel\Mcp\Server\Transport\HttpTransport;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpFoundation\StreamedResponse;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('config')) {
        /** @var array<string,mixed> $configValues */
        $configValues = [];

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

    if (!function_exists('app')) {
        function app(): object
        {
            return new class {
                public function make(string $class, array $params = []): object
                {
                    return new $class(...array_values($params));
                }

                public function runningInConsole(): bool
                {
                    return false;
                }
            };
        }
    }

    if (!function_exists('data_get')) {
        function data_get(array $target, string $key, mixed $default = null): mixed
        {
            $segments = explode('.', $key);
            $value = $target;
            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        }
    }

    require_once __DIR__ . '/../../src/Support/TraceContext.php';
    require_once __DIR__ . '/../../src/Support/TransportError.php';
    require_once __DIR__ . '/../../src/Http/Controllers/McpManagerController.php';

    /** @var array<string,mixed> $configValues */
    $configValues = [
        'app.debug' => false,
        'cms.settings.eMCP.trace.header' => 'X-Trace-Id',
        'cms.settings.eMCP.trace.generate_if_missing' => true,
        'cms.settings.eMCP.toolset_version' => '1.0',
        'cms.settings.eMCP.limits.max_payload_kb' => 256,
        'cms.settings.eMCP.limits.max_result_bytes' => 32,
        'cms.settings.eMCP.stream.enabled' => false,
        'cms.settings.eMCP.stream.max_stream_seconds' => 120,
        'cms.settings.eMCP.stream.heartbeat_seconds' => 15,
        'cms.settings.eMCP.stream.abort_on_disconnect' => true,
        'mcp.servers' => [
            ['handle' => 'content', 'limits' => ['max_result_bytes' => 32, 'max_payload_kb' => 256], 'stream' => ['enabled' => false]],
        ],
    ];

    $controller = new \EvolutionCMS\eMCP\Http\Controllers\McpManagerController(
        new \EvolutionCMS\eMCP\Services\ServerRegistry(),
        new \EvolutionCMS\eMCP\Services\SecurityPolicy(),
        new \EvolutionCMS\eMCP\Services\AuditLogger()
    );

    $initializeRequest = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 'i1',
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ], JSON_UNESCAPED_SLASHES));
    $initializeRequest->headers->set('Content-Type', 'application/json');
    $initializeRequest->headers->set('X-Trace-Id', 'trace-init-1');

    HttpTransport::$nextResponse = new Response((string)json_encode([
        'jsonrpc' => '2.0',
        'id' => 'i1',
        'result' => [
            'serverInfo' => ['name' => 'content-server'],
            'capabilities' => [],
        ],
    ], JSON_UNESCAPED_SLASHES), 200);

    $initializeResponse = $controller($initializeRequest, 'content');
    assertTrue($initializeResponse->getStatusCode() === 413, 'initialize response should hit result-size cap when max_result_bytes is tiny');
    $initializePayload = json_decode($initializeResponse->getContent(), true);
    assertTrue(($initializePayload['error']['code'] ?? null) === 'result_too_large', 'Expected result_too_large transport error');

    $configValues['cms.settings.eMCP.limits.max_result_bytes'] = 4096;
    $configValues['mcp.servers'][0]['limits']['max_result_bytes'] = 4096;

    HttpTransport::$nextResponse = new Response((string)json_encode([
        'jsonrpc' => '2.0',
        'id' => 'i2',
        'result' => [
            'serverInfo' => ['name' => 'content-server'],
            'capabilities' => [],
        ],
    ], JSON_UNESCAPED_SLASHES), 200);

    $initializeResponseOk = $controller($initializeRequest, 'content');
    assertTrue($initializeResponseOk->getStatusCode() === 200, 'initialize should pass when result size is within limits');
    $initBodyOk = json_decode($initializeResponseOk->getContent(), true);
    assertTrue(($initBodyOk['result']['serverInfo']['platform'] ?? null) === 'eMCP', 'initialize should inject platform metadata');
    assertTrue(($initBodyOk['result']['capabilities']['evo']['toolsetVersion'] ?? null) === '1.0', 'initialize should inject toolsetVersion');

    $streamRequest = new \Illuminate\Http\Request(json_encode([
        'jsonrpc' => '2.0',
        'id' => 's1',
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search', 'arguments' => ['limit' => 1]],
    ], JSON_UNESCAPED_SLASHES));
    $streamRequest->headers->set('Content-Type', 'application/json');
    $streamRequest->headers->set('X-Trace-Id', 'trace-stream-1');

    HttpTransport::$nextResponse = new StreamedResponse(200);
    $streamDisabledResponse = $controller($streamRequest, 'content');
    assertTrue($streamDisabledResponse->getStatusCode() === 403, 'streaming must be denied when stream.enabled=false');
    $streamDisabledPayload = json_decode($streamDisabledResponse->getContent(), true);
    assertTrue(($streamDisabledPayload['error']['code'] ?? null) === 'streaming_disabled', 'Expected streaming_disabled error code');

    $configValues['cms.settings.eMCP.stream.enabled'] = true;
    $configValues['cms.settings.eMCP.stream.max_stream_seconds'] = 90;
    $configValues['cms.settings.eMCP.stream.heartbeat_seconds'] = 7;
    $configValues['cms.settings.eMCP.stream.abort_on_disconnect'] = true;
    $configValues['mcp.servers'][0]['stream'] = [
        'enabled' => true,
        'max_stream_seconds' => 60,
        'heartbeat_seconds' => 5,
        'abort_on_disconnect' => true,
    ];

    $streamOk = new StreamedResponse(200);
    HttpTransport::$nextResponse = $streamOk;
    $streamEnabledResponse = $controller($streamRequest, 'content');
    assertTrue($streamEnabledResponse->getStatusCode() === 200, 'streaming response should pass when stream.enabled=true');
    assertTrue($streamEnabledResponse instanceof StreamedResponse, 'Expected streamed response to remain streamed');
    assertTrue(
        $streamEnabledResponse->headers->get('content-type') === 'text/event-stream',
        'streaming response must set SSE content-type for proxy/FPM compatibility'
    );
    assertTrue(
        $streamEnabledResponse->headers->get('cache-control') === 'no-cache, no-transform',
        'streaming response must set cache-control guard for incremental delivery'
    );
    assertTrue(
        $streamEnabledResponse->headers->get('x-accel-buffering') === 'no',
        'streaming response must disable nginx buffering in FPM deployments'
    );
    assertTrue(
        $streamEnabledResponse->headers->get('x-emcp-stream-max-seconds') === '60',
        'stream max seconds header must reflect per-server override'
    );
    assertTrue(
        $streamEnabledResponse->headers->get('x-emcp-heartbeat-seconds') === '5',
        'heartbeat seconds header must reflect per-server override'
    );

    echo "Streaming and result-limits behavior checks passed.\n";
}
