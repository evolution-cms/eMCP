<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResolveMcpActor
{
    public function handle(Request $request, Closure $next)
    {
        $actorUserId = null;
        $context = 'cli';

        if (function_exists('evo') && evo()->isLoggedIn('mgr')) {
            $actorUserId = (int)evo()->getLoginUserID('mgr');
            $context = 'mgr';
        } elseif ($request->attributes->has('sapi.jwt.user_id') || $request->attributes->has('sapi.jwt.sub')) {
            $jwtUserId = $request->attributes->get('sapi.jwt.user_id');
            if (is_numeric($jwtUserId) && (int)$jwtUserId > 0) {
                $actorUserId = (int)$jwtUserId;
            }

            $context = 'api';
        }

        $request->attributes->set('emcp.actor_user_id', $actorUserId);
        $request->attributes->set('emcp.initiated_by_user_id', $actorUserId);
        $request->attributes->set('emcp.context', $context);

        return $next($request);
    }
}
