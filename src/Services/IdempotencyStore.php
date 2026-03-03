<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use Illuminate\Support\Facades\Cache;

final class IdempotencyStore
{
    public function payloadHash(array $meta): string
    {
        $stable = [
            'server_handle' => (string)($meta['server_handle'] ?? ''),
            'jsonrpc_method' => (string)($meta['jsonrpc_method'] ?? ''),
            'jsonrpc_params' => $meta['jsonrpc_params'] ?? null,
            'actor_user_id' => $meta['actor_user_id'] ?? null,
        ];

        return hash('sha256', (string)json_encode($stable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function generatedKeyForSyncFailover(array $meta): string
    {
        return 'sync:' . substr($this->payloadHash($meta), 0, 40);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $value = Cache::get($this->cacheKey($key));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function put(string $key, array $record): void
    {
        if ($key === '') {
            return;
        }

        Cache::put($this->cacheKey($key), $record, $this->ttlSeconds());
    }

    public function rememberAccepted(string $key, string $payloadHash, int $taskId, string $traceId): void
    {
        $this->put($key, [
            'payload_hash' => $payloadHash,
            'task_id' => $taskId,
            'trace_id' => $traceId,
            'status' => 'accepted',
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function rememberCompleted(string $key, string $payloadHash, int $taskId, string $traceId, array $result): void
    {
        $this->put($key, [
            'payload_hash' => $payloadHash,
            'task_id' => $taskId,
            'trace_id' => $traceId,
            'status' => 'completed',
            'result' => $result,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    private function cacheKey(string $key): string
    {
        $storage = trim((string)config('cms.settings.eMCP.idempotency.storage', 'cache'));
        if ($storage === '') {
            $storage = 'cache';
        }

        return 'emcp:idempotency:' . $storage . ':' . $key;
    }

    private function ttlSeconds(): int
    {
        return max(60, (int)config('cms.settings.eMCP.idempotency.ttl_seconds', 86400));
    }
}
