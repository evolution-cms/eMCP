<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Middleware;

use Closure;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;

class EnsureMcpPermission
{
    public function handle(Request $request, Closure $next, ?string $permission = null)
    {
        $permission = $permission ?: (string)config('cms.settings.eMCP.acl.permission', 'emcp');

        if (!function_exists('evo') || !evo()->isLoggedIn('mgr')) {
            return TransportError::response($request, 403, 'forbidden', 'Forbidden');
        }

        if (!evo()->hasPermission($permission, 'mgr')) {
            return TransportError::response($request, 403, 'forbidden', 'Forbidden');
        }

        return $next($request);
    }
}
