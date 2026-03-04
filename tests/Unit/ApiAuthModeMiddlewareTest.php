<?php

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    class Response
    {
        public function __construct(
            protected string $content = '',
            protected int $statusCode = 200
        ) {
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        public function getContent(): string
        {
            return $this->content;
        }
    }
}

namespace Illuminate\Support {
    if (!class_exists(Str::class, false)) {
        final class Str
        {
            public static function contains(string $haystack, string $needle): bool
            {
                return str_contains($haystack, $needle);
            }

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
}

namespace Illuminate\Http {
    use Symfony\Component\HttpFoundation\Response;

    final class HeaderBag
    {
        /** @var array<string, string> */
        private array $items = [];

        public function set(string $name, string $value): void
        {
            $this->items[strtolower($name)] = $value;
        }

        public function get(string $name, mixed $default = null): mixed
        {
            return $this->items[strtolower($name)] ?? $default;
        }
    }

    final class AttributeBag
    {
        /** @var array<string, mixed> */
        private array $items = [];

        public function set(string $key, mixed $value): void
        {
            $this->items[$key] = $value;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->items);
        }
    }

    class Request
    {
        public HeaderBag $headers;
        public AttributeBag $attributes;

        /**
         * @param array<string, mixed> $routeParameters
         */
        public function __construct(
            private string $content = '',
            private array $routeParameters = []
        ) {
            $this->headers = new HeaderBag();
            $this->attributes = new AttributeBag();
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function route(string $key, mixed $default = null): mixed
        {
            return $this->routeParameters[$key] ?? $default;
        }

        public function ip(): string
        {
            return '127.0.0.1';
        }
    }

    class JsonResponse extends Response
    {
        public HeaderBag $headers;

        /**
         * @param array<string, mixed> $payload
         */
        public function __construct(array $payload, int $status = 200)
        {
            parent::__construct((string)json_encode($payload, JSON_UNESCAPED_SLASHES), $status);
            $this->headers = new HeaderBag();
        }
    }
}

namespace Seiger\sApi\Http\Middleware {
    use Closure;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    final class JwtAuthMiddleware
    {
        /** @var callable|null */
        public static $handler = null;

        public function handle(Request $request, Closure $next): Response
        {
            if (is_callable(self::$handler)) {
                return (self::$handler)($request, $next);
            }

            return $next($request);
        }
    }
}

namespace {
    use EvolutionCMS\eMCP\Middleware\EnsureApiJwt;
    use EvolutionCMS\eMCP\Middleware\EnsureMcpScopes;
    use EvolutionCMS\eMCP\Services\ScopePolicy;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Seiger\sApi\Http\Middleware\JwtAuthMiddleware;
    use Symfony\Component\HttpFoundation\Response;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    function jsonDecodeObject(string $content): array
    {
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @var array<string, mixed> $configValues */
    $configValues = [
        'cms.settings.eMCP.auth.mode' => 'sapi_jwt',
        'cms.settings.eMCP.auth.require_scopes' => true,
        'cms.settings.eMCP.auth.scope_map' => [],
        'cms.settings.eMCP.trace.header' => 'X-Trace-Id',
        'cms.settings.eMCP.trace.generate_if_missing' => true,
        'mcp.servers' => [],
    ];

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
                /**
                 * @param array<string, mixed> $payload
                 */
                public function json(array $payload, int $status = 200): JsonResponse
                {
                    return new JsonResponse($payload, $status);
                }
            };
        }
    }

    if (!function_exists('app')) {
        function app(): object
        {
            return new class {
                public function make(string $class): object
                {
                    return new $class();
                }
            };
        }
    }

    require_once __DIR__ . '/../../src/Support/TraceContext.php';
    require_once __DIR__ . '/../../src/Support/TransportError.php';
    require_once __DIR__ . '/../../src/Services/ScopePolicy.php';
    require_once __DIR__ . '/../../src/Middleware/EnsureApiJwt.php';
    require_once __DIR__ . '/../../src/Middleware/EnsureMcpScopes.php';

    $apiJwt = new EnsureApiJwt();
    $scopePolicy = new ScopePolicy();
    $scopeMiddleware = new EnsureMcpScopes($scopePolicy);
    $next = static fn(Request $request): Response => new Response('pass', 209);

    $configValues['cms.settings.eMCP.auth.mode'] = 'none';
    $noneModeResponse = $apiJwt->handle(new Request(), $next);
    assertTrue($noneModeResponse->getStatusCode() === 209, 'auth.mode=none must bypass EnsureApiJwt');

    $configValues['cms.settings.eMCP.auth.mode'] = 'sapi_jwt';
    JwtAuthMiddleware::$handler = static function (Request $request, \Closure $next): Response {
        return new JsonResponse([
            'success' => false,
            'message' => 'Unauthorized.',
            'object' => (object)[],
            'code' => 401,
        ], 401);
    };

    $normalizedJwtError = $apiJwt->handle(new Request(), $next);
    assertTrue($normalizedJwtError->getStatusCode() === 401, 'EnsureApiJwt must normalize raw sApi 401');
    $normalizedJwtPayload = jsonDecodeObject($normalizedJwtError->getContent());
    assertTrue(
        (($normalizedJwtPayload['error']['code'] ?? '') === 'unauthenticated'),
        'EnsureApiJwt must return transport unauthenticated code'
    );

    $configValues['cms.settings.eMCP.auth.mode'] = 'none';
    $configValues['cms.settings.eMCP.auth.require_scopes'] = true;
    $scopeBypassResponse = $scopeMiddleware->handle(
        new Request('{"jsonrpc":"2.0","method":"tools/call"}', ['server' => 'content']),
        $next
    );
    assertTrue($scopeBypassResponse->getStatusCode() === 209, 'auth.mode=none must bypass EnsureMcpScopes');

    $configValues['cms.settings.eMCP.auth.mode'] = 'sapi_jwt';
    $configValues['cms.settings.eMCP.auth.require_scopes'] = true;
    $missingJwtResponse = $scopeMiddleware->handle(
        new Request('{"jsonrpc":"2.0","method":"tools/call"}', ['server' => 'content']),
        $next
    );
    assertTrue($missingJwtResponse->getStatusCode() === 401, 'EnsureMcpScopes must require JWT context');
    $missingJwtPayload = jsonDecodeObject($missingJwtResponse->getContent());
    assertTrue(
        (($missingJwtPayload['error']['code'] ?? '') === 'unauthenticated'),
        'EnsureMcpScopes must return unauthenticated on missing JWT context'
    );

    $configValues['cms.settings.eMCP.auth.require_scopes'] = false;
    $scopesDisabledResponse = $scopeMiddleware->handle(
        new Request('{"jsonrpc":"2.0","method":"tools/call"}', ['server' => 'content']),
        $next
    );
    assertTrue($scopesDisabledResponse->getStatusCode() === 209, 'require_scopes=false must bypass EnsureMcpScopes');

    $configValues['cms.settings.eMCP.auth.require_scopes'] = true;
    $deniedRequest = new Request('{"jsonrpc":"2.0","method":"tools/call"}', ['server' => 'content']);
    $deniedRequest->attributes->set('sapi.jwt.payload', ['sub' => '123']);
    $deniedRequest->attributes->set('sapi.jwt.scopes', ['mcp:read']);

    $scopeDeniedResponse = $scopeMiddleware->handle($deniedRequest, $next);
    assertTrue($scopeDeniedResponse->getStatusCode() === 403, 'EnsureMcpScopes must deny missing required scope');
    $scopeDeniedPayload = jsonDecodeObject($scopeDeniedResponse->getContent());
    assertTrue((($scopeDeniedPayload['error']['code'] ?? '') === 'scope_denied'), 'Scope denial code must be scope_denied');

    echo "API auth mode middleware checks passed.\n";
}
