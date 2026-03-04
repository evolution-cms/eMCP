<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Middleware;

use Closure;
use EvolutionCMS\eMCP\Services\ScopePolicy;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;

class EnsureMcpScopes
{
    public function __construct(
        private readonly ScopePolicy $scopePolicy
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->authModeIsNone()) {
            return $next($request);
        }

        if (!(bool)config('cms.settings.eMCP.auth.require_scopes', true)) {
            return $next($request);
        }

        if (!$this->hasJwtContext($request)) {
            return TransportError::response($request, 401, 'unauthenticated', 'Unauthenticated');
        }

        $serverHandle = trim((string)$request->route('server', ''));
        $method = $this->scopePolicy->resolveRequestedMethod($request);
        if ($method === null) {
            return $next($request);
        }

        $requiredScope = $this->scopePolicy->resolveRequiredScope(
            $serverHandle !== '' ? $serverHandle : null,
            $method
        );

        // Deny by default: unknown methods require admin scope unless explicitly mapped.
        $requiredScope = $requiredScope ?? 'mcp:admin';

        if (!$this->scopePolicy->requestHasScope($request, $requiredScope)) {
            return TransportError::response($request, 403, 'scope_denied', 'Scope denied');
        }

        $request->attributes->set('emcp.required_scope', $requiredScope);

        return $next($request);
    }

    private function hasJwtContext(Request $request): bool
    {
        if ($request->attributes->has('sapi.jwt.payload')) {
            return true;
        }

        if ($request->attributes->has('sapi.jwt.user_id')) {
            return true;
        }

        if ($request->attributes->has('sapi.jwt.sub')) {
            return true;
        }

        return false;
    }

    private function authModeIsNone(): bool
    {
        $mode = strtolower(trim((string)config('cms.settings.eMCP.auth.mode', 'sapi_jwt')));

        return $mode === 'none';
    }
}
