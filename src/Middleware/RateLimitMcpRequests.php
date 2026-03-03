<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Middleware;

use Closure;
use EvolutionCMS\eMCP\Services\ScopePolicy;
use EvolutionCMS\eMCP\Support\RateLimitIdentityResolver;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitMcpRequests
{
    public function __construct(
        private readonly ScopePolicy $scopePolicy
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!(bool)config('cms.settings.eMCP.rate_limit.enabled', true)) {
            return $next($request);
        }

        $maxAttempts = $this->resolveMaxAttempts($request);
        if ($maxAttempts < 1) {
            return $next($request);
        }

        $key = $this->rateLimitKey($request);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            $response = TransportError::response($request, 429, 'rate_limited', 'Too many requests');
            $response->headers->set('Retry-After', (string)$retryAfter);

            return $response;
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    private function resolveMaxAttempts(Request $request): int
    {
        $default = (int)config('cms.settings.eMCP.rate_limit.per_minute', 60);
        $serverHandle = trim((string)$request->route('server', ''));
        if ($serverHandle === '') {
            return max(0, $default);
        }

        $servers = config('mcp.servers', []);
        if (!is_array($servers)) {
            return max(0, $default);
        }

        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }

            if (trim((string)($server['handle'] ?? '')) !== $serverHandle) {
                continue;
            }

            $override = $server['rate_limit']['per_minute'] ?? null;
            if (is_numeric($override)) {
                return max(0, (int)$override);
            }

            return max(0, $default);
        }

        return max(0, $default);
    }

    private function rateLimitKey(Request $request): string
    {
        $serverHandle = trim((string)$request->route('server', ''));
        if ($serverHandle === '') {
            $serverHandle = 'unknown';
        }

        $method = $this->scopePolicy->resolveRequestedMethod($request) ?? 'unknown';
        $identity = RateLimitIdentityResolver::resolveRateLimitIdentity($request);

        return implode(':', ['emcp', $serverHandle, $method, $identity]);
    }
}
