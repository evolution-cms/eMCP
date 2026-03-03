<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Support;

use Illuminate\Http\Request;

final class RateLimitIdentityResolver
{
    public static function resolveRateLimitIdentity(Request $request): string
    {
        $context = trim((string)$request->attributes->get('emcp.context', ''));
        if ($context === 'mgr') {
            $actorUserId = $request->attributes->get('emcp.actor_user_id');
            if (is_numeric($actorUserId) && (int)$actorUserId > 0) {
                return 'mgr:' . (int)$actorUserId;
            }
        }

        if ($context === 'api' || $context === '') {
            $jwtUserId = trim((string)$request->attributes->get('sapi.jwt.user_id', ''));
            if ($jwtUserId !== '') {
                return 'api:' . $jwtUserId;
            }

            $jwtSubject = trim((string)$request->attributes->get('sapi.jwt.sub', ''));
            if ($jwtSubject !== '') {
                return 'api:' . $jwtSubject;
            }
        }

        $ip = trim((string)$request->ip());

        return $ip !== '' ? 'ip:' . $ip : 'ip:unknown';
    }
}
