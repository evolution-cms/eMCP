<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use EvolutionCMS\eMCP\Support\RateLimitIdentityResolver;
use EvolutionCMS\eMCP\Support\Redactor;
use EvolutionCMS\eMCP\Support\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly Redactor $redactor
    ) {
    }

    public function log(
        Request $request,
        string $serverHandle,
        string $method,
        int $status,
        float $startedAt,
        ?int $taskId = null,
        array $extra = []
    ): void {
        if (!(bool)config('cms.settings.eMCP.logging.audit_enabled', true)) {
            return;
        }

        $durationMs = (int)max(0, round((microtime(true) - $startedAt) * 1000));
        $traceId = TraceContext::resolve($request);

        $payload = [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $this->resolveRequestId($request),
            'trace_id' => $traceId,
            'server_handle' => trim($serverHandle),
            'method' => trim($method),
            'status' => $status,
            'actor_user_id' => $this->resolveActorId($request),
            'context' => trim((string)$request->attributes->get('emcp.context', 'unknown')),
            'duration_ms' => $durationMs,
            'task_id' => $taskId,
            'rate_limit_identity' => RateLimitIdentityResolver::resolveRateLimitIdentity($request),
        ];

        if ($extra !== []) {
            $payload['extra'] = $this->redactor->redact($extra);
        }

        $channel = trim((string)config('cms.settings.eMCP.logging.channel', 'emcp'));
        if ($channel === '') {
            $channel = 'emcp';
        }

        Log::channel($channel)->info('emcp.audit', $payload);
    }

    private function resolveRequestId(Request $request): string
    {
        $existing = trim((string)$request->attributes->get('emcp.request_id', ''));
        if ($existing !== '') {
            return $existing;
        }

        $header = trim((string)$request->headers->get('X-Request-Id', ''));
        $requestId = $header !== '' ? $header : (string)Str::uuid();

        $request->attributes->set('emcp.request_id', $requestId);

        return $requestId;
    }

    private function resolveActorId(Request $request): ?int
    {
        $actor = $request->attributes->get('emcp.actor_user_id');

        if (!is_numeric($actor)) {
            return null;
        }

        $actor = (int)$actor;

        return $actor > 0 ? $actor : null;
    }
}
