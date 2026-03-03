<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Http\Controllers;

use EvolutionCMS\eMCP\Services\AuditLogger;
use EvolutionCMS\eMCP\Services\SecurityPolicy;
use EvolutionCMS\eMCP\Services\ServerRegistry;
use EvolutionCMS\eMCP\Support\TraceContext;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpManagerController
{
    public function __construct(
        private readonly ServerRegistry $registry,
        private readonly SecurityPolicy $securityPolicy,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function __invoke(Request $request, string $server): Response
    {
        $startedAt = microtime(true);
        $auditMethod = $this->resolveAuditMethod($request);

        $validationError = $this->validateIncomingRequest($request, $server);
        if ($validationError !== null) {
            return $this->finalizeResponse($request, $validationError, $server, $auditMethod, $startedAt);
        }

        if (!$this->securityPolicy->isServerAllowed($server)) {
            return $this->finalizeResponse(
                $request,
                TransportError::response($request, 403, 'server_denied', 'Server denied by policy'),
                $server,
                $auditMethod,
                $startedAt
            );
        }

        $payload = $request->json()->all();
        if (is_array($payload)) {
            $toolName = $this->securityPolicy->resolveToolName($payload);
            if ($toolName !== null && $this->securityPolicy->isToolDenied($server, $toolName)) {
                return $this->finalizeResponse(
                    $request,
                    TransportError::response($request, 403, 'tool_denied', 'Tool denied by policy'),
                    $server,
                    $auditMethod,
                    $startedAt
                );
            }
        }

        $serverClass = $this->registry->resolveWebServerClassByHandle($server);
        if ($serverClass === null) {
            return $this->finalizeResponse(
                $request,
                TransportError::response($request, 404, 'server_not_found', 'Server not found'),
                $server,
                $auditMethod,
                $startedAt
            );
        }

        try {
            $response = $this->runServer($request, $serverClass);
        } catch (\Throwable $e) {
            $this->logInternalError($request, $server, $auditMethod, $e);

            if ((bool)config('app.debug', false)) {
                throw $e;
            }

            $errorMessage = 'Internal server error';
            if (app()->runningInConsole()) {
                $errorMessage = trim($e->getMessage()) !== '' ? $e->getMessage() : $errorMessage;
            }

            return $this->finalizeResponse(
                $request,
                TransportError::response($request, 500, 'internal_error', $errorMessage),
                $server,
                $auditMethod,
                $startedAt
            );
        }

        if (!$response instanceof StreamedResponse && $this->isInitializeRequest($request)) {
            $this->injectPlatformMetadata($response);
        }

        if ($response instanceof StreamedResponse) {
            $streamError = $this->enforceStreamingPolicy($request, $server, $response);
            if ($streamError !== null) {
                return $this->finalizeResponse($request, $streamError, $server, $auditMethod, $startedAt);
            }
        } else {
            $sizeError = $this->enforceResultSizeLimit($request, $server, $response);
            if ($sizeError !== null) {
                return $this->finalizeResponse($request, $sizeError, $server, $auditMethod, $startedAt);
            }
        }

        return $this->finalizeResponse($request, $response, $server, $auditMethod, $startedAt);
    }

    private function logInternalError(Request $request, string $server, string $method, \Throwable $e): void
    {
        try {
            $channel = trim((string)config('cms.settings.eMCP.logging.channel', 'emcp'));
            if ($channel === '') {
                $channel = 'emcp';
            }

            Log::channel($channel)->error('emcp.internal_error', [
                'server_handle' => $server,
                'method' => $method,
                'trace_id' => TraceContext::resolve($request),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // Logging must not break request processing.
        }
    }

    private function runServer(Request $request, string $serverClass): Response
    {
        $transport = new HttpTransport(
            $request,
            (string)$request->header('MCP-Session-Id', '')
        );

        $server = app()->make($serverClass, ['transport' => $transport]);
        $server->start();

        return $transport->run();
    }

    private function isInitializeRequest(Request $request): bool
    {
        $payload = $request->json()->all();

        return is_array($payload) && ($payload['method'] ?? null) === 'initialize';
    }

    private function injectPlatformMetadata(Response $response): void
    {
        $raw = $response->getContent();
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['result']) || !is_array($payload['result'])) {
            return;
        }

        $payload['result']['serverInfo']['platform'] = 'eMCP';
        $payload['result']['serverInfo']['platformVersion'] = $this->packageVersion();
        $payload['result']['capabilities']['evo']['toolsetVersion'] = (string)config('cms.settings.eMCP.toolset_version', '1.0');

        $response->setContent(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function appendTraceHeader(Request $request, Response $response): void
    {
        $traceId = TraceContext::resolve($request);
        if ($traceId === '') {
            return;
        }

        $traceHeader = (string)config('cms.settings.eMCP.trace.header', 'X-Trace-Id');
        $response->headers->set($traceHeader, $traceId);
    }

    private function packageVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('evolution-cms/emcp');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        return 'dev';
    }

    private function validateIncomingRequest(Request $request, string $server): ?Response
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

    private function enforceResultSizeLimit(Request $request, string $server, Response $response): ?Response
    {
        $content = $response->getContent();
        if (!is_string($content)) {
            return null;
        }

        $maxResultBytes = $this->resolveMaxResultBytes($server);
        if (strlen($content) > $maxResultBytes) {
            return TransportError::response($request, 413, 'result_too_large', 'Result too large');
        }

        return null;
    }

    private function enforceStreamingPolicy(Request $request, string $server, StreamedResponse $response): ?Response
    {
        if (!$this->resolveStreamEnabled($server)) {
            return TransportError::response($request, 403, 'streaming_disabled', 'Streaming is disabled');
        }

        $maxSeconds = $this->resolveStreamMaxSeconds($server);
        $heartbeatSeconds = $this->resolveHeartbeatSeconds($server);
        $abortOnDisconnect = $this->resolveAbortOnDisconnect($server);

        $response->headers->set('X-eMCP-Stream-Max-Seconds', (string)$maxSeconds);
        $response->headers->set('X-eMCP-Heartbeat-Seconds', (string)$heartbeatSeconds);

        if (function_exists('set_time_limit') && $maxSeconds > 0) {
            @set_time_limit($maxSeconds + 5);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(!$abortOnDisconnect);
        }

        return null;
    }

    private function resolveMaxPayloadBytes(string $server): int
    {
        $globalKb = max(1, (int)config('cms.settings.eMCP.limits.max_payload_kb', 256));
        $serverKb = $this->resolveServerNumeric('limits.max_payload_kb', $server);
        $effectiveKb = $serverKb !== null ? max(1, $serverKb) : $globalKb;

        return $effectiveKb * 1024;
    }

    private function resolveMaxResultBytes(string $server): int
    {
        $global = max(1, (int)config('cms.settings.eMCP.limits.max_result_bytes', 1048576));
        $serverOverride = $this->resolveServerNumeric('limits.max_result_bytes', $server);

        return $serverOverride !== null ? max(1, $serverOverride) : $global;
    }

    private function resolveStreamEnabled(string $server): bool
    {
        $global = (bool)config('cms.settings.eMCP.stream.enabled', false);
        $serverOverride = $this->resolveServerValue('stream.enabled', $server);
        if ($serverOverride === null) {
            return $global;
        }

        return (bool)$serverOverride;
    }

    private function resolveStreamMaxSeconds(string $server): int
    {
        $global = max(1, (int)config('cms.settings.eMCP.stream.max_stream_seconds', 120));
        $serverOverride = $this->resolveServerNumeric('stream.max_stream_seconds', $server);

        return $serverOverride !== null ? max(1, $serverOverride) : $global;
    }

    private function resolveHeartbeatSeconds(string $server): int
    {
        $global = max(1, (int)config('cms.settings.eMCP.stream.heartbeat_seconds', 15));
        $serverOverride = $this->resolveServerNumeric('stream.heartbeat_seconds', $server);

        return $serverOverride !== null ? max(1, $serverOverride) : $global;
    }

    private function resolveAbortOnDisconnect(string $server): bool
    {
        $global = (bool)config('cms.settings.eMCP.stream.abort_on_disconnect', true);
        $serverOverride = $this->resolveServerValue('stream.abort_on_disconnect', $server);
        if ($serverOverride === null) {
            return $global;
        }

        return (bool)$serverOverride;
    }

    private function resolveServerNumeric(string $key, string $server): ?int
    {
        $value = $this->resolveServerValue($key, $server);
        if (!is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function resolveServerValue(string $key, string $server): mixed
    {
        $serverConfig = $this->resolveServerConfig($server);
        if ($serverConfig === []) {
            return null;
        }

        return data_get($serverConfig, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveServerConfig(string $server): array
    {
        $servers = config('mcp.servers', []);
        if (!is_array($servers)) {
            return [];
        }

        foreach ($servers as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (trim((string)($item['handle'] ?? '')) !== $server) {
                continue;
            }

            return $item;
        }

        return [];
    }

    private function resolveAuditMethod(Request $request): string
    {
        $payload = $request->json()->all();
        if (!is_array($payload)) {
            return 'unknown';
        }

        $method = trim((string)($payload['method'] ?? ''));

        return $method !== '' ? $method : 'unknown';
    }

    private function finalizeResponse(Request $request, Response $response, string $server, string $method, float $startedAt): Response
    {
        $this->appendTraceHeader($request, $response);

        $this->auditLogger->log(
            $request,
            $server,
            $method,
            $response->getStatusCode(),
            $startedAt
        );

        return $response;
    }
}
