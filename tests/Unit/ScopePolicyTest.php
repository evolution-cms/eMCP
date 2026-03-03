<?php

declare(strict_types=1);

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
        }
    }
}

namespace Illuminate\Http {
    if (!class_exists(AttributeBag::class, false)) {
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
        }
    }

    if (!class_exists(Request::class, false)) {
        final class Request
        {
            public AttributeBag $attributes;

            public function __construct(private string $content = '')
            {
                $this->attributes = new AttributeBag();
            }

            public static function create(
                string $uri = '/',
                string $method = 'GET',
                array $parameters = [],
                array $cookies = [],
                array $files = [],
                array $server = [],
                ?string $content = null
            ): self {
                return new self((string)$content);
            }

            public function getContent(): string
            {
                return $this->content;
            }
        }
    }
}

namespace {
    use EvolutionCMS\eMCP\Services\ScopePolicy;
    use Illuminate\Http\Request;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function makeRequest(string $content = ''): Request
    {
        if (method_exists(Request::class, 'create')) {
            return Request::create('/', 'POST', [], [], [], [], $content);
        }

        return new Request($content);
    }

    /** @var array<string, mixed> $config */
    $config = [
        'cms.settings.eMCP.auth.scope_map' => [],
        'mcp.servers' => [],
    ];

    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            global $config;

            return $config[$key] ?? $default;
        }
    }

    require_once __DIR__ . '/../../src/Services/ScopePolicy.php';

    $policy = new ScopePolicy();

    assertTrue($policy->resolveRequiredScope(null, 'initialize') === 'mcp:read', 'initialize must require mcp:read');
    assertTrue($policy->resolveRequiredScope(null, 'tools/call') === 'mcp:call', 'tools/call must require mcp:call');
    assertTrue($policy->resolveRequiredScope(null, 'admin/reindex') === 'mcp:admin', 'admin/* must require mcp:admin');

    $config['cms.settings.eMCP.auth.scope_map'] = [
        'mcp:read' => ['initialize', 'tools/list'],
        'mcp:call' => ['tools/call', 'custom/method'],
    ];
    assertTrue(
        $policy->resolveRequiredScope('content', 'custom/method') === 'mcp:call',
        'global custom method must map to mcp:call'
    );

    $config['mcp.servers'] = [
        [
            'handle' => 'content',
            'scope_map' => [
                'mcp:read' => ['initialize'],
                'mcp:call' => ['server/custom'],
            ],
        ],
    ];
    assertTrue(
        $policy->resolveRequiredScope('content', 'server/custom') === 'mcp:call',
        'server-level scope_map must override global mapping'
    );
    assertTrue(
        $policy->resolveRequiredScope('other', 'custom/method') === 'mcp:call',
        'other server should still use global map'
    );

    $cachedRequest = makeRequest('{"method":"tools/list"}');
    $cachedRequest->attributes->set('emcp.jsonrpc.method', 'tools/call');
    assertTrue(
        $policy->resolveRequestedMethod($cachedRequest) === 'tools/call',
        'cached method attribute must win'
    );

    $jsonRequest = makeRequest('{"jsonrpc":"2.0","method":"tools/list"}');
    assertTrue(
        $policy->resolveRequestedMethod($jsonRequest) === 'tools/list',
        'method must be parsed from JSON body'
    );
    assertTrue(
        $jsonRequest->attributes->get('emcp.jsonrpc.method') === 'tools/list',
        'parsed method should be cached in request attributes'
    );

    $invalidRequest = makeRequest('not-json');
    assertTrue($policy->resolveRequestedMethod($invalidRequest) === null, 'invalid JSON body should return null method');

    $scopesRequest = makeRequest();
    $scopesRequest->attributes->set('sapi.jwt.scopes', ['mcp:read', 'mcp:call']);
    assertTrue($policy->requestHasScope($scopesRequest, 'mcp:call'), 'array scopes should allow listed scope');

    $csvScopesRequest = makeRequest();
    $csvScopesRequest->attributes->set('sapi.jwt.scopes', 'mcp:read,mcp:admin');
    assertTrue($policy->requestHasScope($csvScopesRequest, 'mcp:admin'), 'CSV scopes should allow listed scope');

    $wildcardScopesRequest = makeRequest();
    $wildcardScopesRequest->attributes->set('sapi.jwt.scopes', ['*']);
    assertTrue($policy->requestHasScope($wildcardScopesRequest, 'mcp:admin'), 'wildcard scope should allow any scope');

    echo "ScopePolicy unit checks passed.\n";
}
