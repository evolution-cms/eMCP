<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Http\Controllers;

use EvolutionCMS\eMCP\Services\AuditLogger;
use EvolutionCMS\eMCP\Services\IdempotencyStore;
use EvolutionCMS\eMCP\Services\McpExecutionService;
use EvolutionCMS\eMCP\Services\SecurityPolicy;
use EvolutionCMS\eMCP\Services\ServerRegistry;
use EvolutionCMS\eMCP\Support\TraceContext;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Seiger\sTask\Models\sTaskModel;

class McpDispatchController
{
    public function __construct(
        private readonly ServerRegistry $registry,
        private readonly SecurityPolicy $securityPolicy,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly McpExecutionService $executionService,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function __invoke(Request $request, string $server): JsonResponse
    {
        $startedAt = microtime(true);

        if (($error = $this->validateIncomingRequest($request, $server)) !== null) {
            return $this->finalize($request, $error, $server, 'dispatch', $startedAt);
        }

        if (!$this->securityPolicy->isServerAllowed($server)) {
            return $this->finalize(
                $request,
                TransportError::response($request, 403, 'server_denied', 'Server denied by policy'),
                $server,
                'dispatch',
                $startedAt
            );
        }

        $serverClass = $this->registry->resolveWebServerClassByHandle($server);
        if ($serverClass === null) {
            return $this->finalize(
                $request,
                TransportError::response($request, 404, 'server_not_found', 'Server not found'),
                $server,
                'dispatch',
                $startedAt
            );
        }

        $jsonrpc = $request->json()->all();
        if (!is_array($jsonrpc)) {
            return $this->finalize(
                $request,
                TransportError::response($request, 400, 'invalid_payload', 'Invalid payload'),
                $server,
                'dispatch',
                $startedAt
            );
        }

        $method = trim((string)($jsonrpc['method'] ?? ''));
        if ($method === '') {
            return $this->finalize(
                $request,
                TransportError::response($request, 400, 'invalid_payload', 'jsonrpc method is required'),
                $server,
                'dispatch',
                $startedAt
            );
        }

        $toolName = $this->securityPolicy->resolveToolName($jsonrpc);
        if ($toolName !== null && $this->securityPolicy->isToolDenied($server, $toolName)) {
            return $this->finalize(
                $request,
                TransportError::response($request, 403, 'tool_denied', 'Tool denied by policy'),
                $server,
                'dispatch',
                $startedAt
            );
        }

        $traceId = TraceContext::resolve($request);
        $meta = $this->buildMeta($request, $server, $jsonrpc, $traceId);
        $asyncAvailable = $this->canQueueAsync();
        $failover = trim((string)config('cms.settings.eMCP.queue.failover', 'sync'));

        $idempotencyKey = trim((string)($meta['idempotency_key'] ?? ''));

        if ($idempotencyKey === '' && !$asyncAvailable && $failover === 'sync') {
            $idempotencyKey = $this->idempotencyStore->generatedKeyForSyncFailover($meta);
            $meta['idempotency_key'] = $idempotencyKey;
        }

        $payloadHash = trim((string)($meta['payload_hash'] ?? ''));

        if ($idempotencyKey !== '') {
            $reuse = $this->resolveIdempotencyReuse($request, $server, $idempotencyKey, $payloadHash, $startedAt);
            if ($reuse !== null) {
                return $reuse;
            }
        }

        if ($asyncAvailable) {
            $task = sTaskModel::query()->create([
                'identifier' => 'emcp_dispatch',
                'action' => 'dispatch',
                'status' => sTaskModel::TASK_STATUS_QUEUED,
                'message' => 'MCP dispatch queued',
                'started_by' => $meta['initiated_by_user_id'],
                'meta' => $meta,
                'priority' => 'normal',
                'progress' => 0,
                'attempts' => 0,
                'max_attempts' => (int)$meta['max_attempts'],
            ]);

            if ($idempotencyKey !== '') {
                $this->idempotencyStore->rememberAccepted($idempotencyKey, $payloadHash, (int)$task->id, $traceId);
            }

            $response = response()->json([
                'status' => 'accepted',
                'task_id' => (int)$task->id,
                'trace_id' => $traceId,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'reused' => false,
            ], 202);

            return $this->finalize($request, $response, $server, $method, $startedAt, (int)$task->id);
        }

        if ($failover !== 'sync') {
            return $this->finalize(
                $request,
                TransportError::response($request, 503, 'async_unavailable', 'Async dispatch unavailable'),
                $server,
                $method,
                $startedAt
            );
        }

        $result = $this->executionService->call($meta);
        $this->idempotencyStore->rememberCompleted($idempotencyKey, $payloadHash, 0, $traceId, $result);

        $response = response()->json([
            'status' => 'completed',
            'task_id' => null,
            'trace_id' => $traceId,
            'idempotency_key' => $idempotencyKey,
            'reused' => false,
            'result' => $result,
        ], 200);

        return $this->finalize($request, $response, $server, $method, $startedAt);
    }

    private function canQueueAsync(): bool
    {
        if ((string)config('cms.settings.eMCP.queue.driver', 'stask') !== 'stask') {
            return false;
        }

        if (!class_exists(sTaskModel::class) || !class_exists(Schema::class)) {
            return false;
        }

        try {
            return Schema::hasTable('s_tasks');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $jsonrpc
     * @return array<string, mixed>
     */
    private function buildMeta(Request $request, string $server, array $jsonrpc, string $traceId): array
    {
        $header = trim((string)config('cms.settings.eMCP.idempotency.header', 'Idempotency-Key'));
        if ($header === '') {
            $header = 'Idempotency-Key';
        }

        $idempotencyKey = trim((string)$request->headers->get($header, ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = trim((string)($jsonrpc['idempotency_key'] ?? ''));
        }

        $params = $jsonrpc['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $meta = [
            'server_handle' => $server,
            'jsonrpc_method' => trim((string)($jsonrpc['method'] ?? '')),
            'jsonrpc_params' => $params,
            'request_id' => $jsonrpc['id'] ?? null,
            'session_id' => trim((string)$request->header('MCP-Session-Id', '')),
            'trace_id' => $traceId,
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $this->resolveActorId($request),
            'initiated_by_user_id' => $this->resolveActorId($request),
            'context' => trim((string)$request->attributes->get('emcp.context', 'cli')) ?: 'cli',
            'attempts' => 0,
            'max_attempts' => 3,
        ];

        $meta['payload_hash'] = $this->idempotencyStore->payloadHash($meta);

        return $meta;
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

    private function validateIncomingRequest(Request $request, string $server): ?JsonResponse
    {
        $contentType = strtolower(trim((string)$request->headers->get('Content-Type', '')));
        if ($contentType === '' || !str_starts_with($contentType, 'application/json')) {
            return TransportError::response($request, 415, 'unsupported_media_type', 'Unsupported media type');
        }

        $rawBody = $request->getContent();
        $maxPayloadBytes = $this->resolveMaxPayloadBytes($server);
        if (is_string($rawBody) && strlen($rawBody) > $maxPayloadBytes) {
            return TransportError::response($request, 413, 'payload_too_large', 'Payload too large');
        }

        return null;
    }

    private function resolveMaxPayloadBytes(string $server): int
    {
        $globalKb = max(1, (int)config('cms.settings.eMCP.limits.max_payload_kb', 256));
        $effectiveKb = $globalKb;

        $servers = config('mcp.servers', []);
        if (is_array($servers)) {
            foreach ($servers as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (trim((string)($item['handle'] ?? '')) !== $server) {
                    continue;
                }

                $override = $item['limits']['max_payload_kb'] ?? null;
                if (is_numeric($override) && (int)$override > 0) {
                    $effectiveKb = (int)$override;
                }

                break;
            }
        }

        return $effectiveKb * 1024;
    }

    private function resolveIdempotencyReuse(
        Request $request,
        string $server,
        string $idempotencyKey,
        string $payloadHash,
        float $startedAt
    ): ?JsonResponse {
        $existing = $this->idempotencyStore->get($idempotencyKey);
        if ($existing === null) {
            return null;
        }

        $existingHash = trim((string)($existing['payload_hash'] ?? ''));
        if ($existingHash !== '' && $existingHash !== $payloadHash) {
            return $this->finalize(
                $request,
                TransportError::response($request, 409, 'idempotency_conflict', 'Idempotency key conflict'),
                $server,
                'dispatch',
                $startedAt,
                null,
                ['idempotency_key' => $idempotencyKey]
            );
        }

        $status = trim((string)($existing['status'] ?? 'accepted'));
        $taskId = is_numeric($existing['task_id'] ?? null) ? (int)$existing['task_id'] : null;

        $body = [
            'status' => $status,
            'task_id' => $taskId,
            'trace_id' => trim((string)($existing['trace_id'] ?? TraceContext::resolve($request))),
            'idempotency_key' => $idempotencyKey,
            'reused' => true,
        ];

        $result = $existing['result'] ?? null;
        if (is_array($result)) {
            $body['result'] = $result;
        }

        $http = $status === 'completed' ? 200 : 202;

        return $this->finalize(
            $request,
            response()->json($body, $http),
            $server,
            'dispatch',
            $startedAt,
            $taskId,
            ['idempotency_key' => $idempotencyKey, 'reused' => true]
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finalize(
        Request $request,
        JsonResponse $response,
        string $server,
        string $method,
        float $startedAt,
        ?int $taskId = null,
        array $extra = []
    ): JsonResponse {
        $traceId = TraceContext::resolve($request);
        if ($traceId !== '') {
            $traceHeader = (string)config('cms.settings.eMCP.trace.header', 'X-Trace-Id');
            $response->headers->set($traceHeader, $traceId);
        }

        $this->auditLogger->log(
            $request,
            $server,
            $method,
            $response->getStatusCode(),
            $startedAt,
            $taskId,
            $extra
        );

        return $response;
    }
}
