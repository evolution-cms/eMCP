<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use EvolutionCMS\eMCP\Http\Controllers\McpManagerController;
use EvolutionCMS\eMCP\Support\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class McpExecutionService
{
    public function __construct(
        private readonly McpManagerController $controller,
        private readonly SecurityPolicy $securityPolicy
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function call(array $meta): array
    {
        $serverHandle = trim((string)($meta['server_handle'] ?? ''));
        $jsonrpcMethod = trim((string)($meta['jsonrpc_method'] ?? ''));

        if ($serverHandle === '' || $jsonrpcMethod === '') {
            throw new \InvalidArgumentException('server_handle and jsonrpc_method are required.');
        }

        if (!$this->securityPolicy->isServerAllowed($serverHandle)) {
            throw new \DomainException('Server is denied by allow_servers policy.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $meta['request_id'] ?? (string)Str::uuid(),
            'method' => $jsonrpcMethod,
            'params' => is_array($meta['jsonrpc_params'] ?? null) ? $meta['jsonrpc_params'] : (object)[],
        ];

        $toolName = $this->securityPolicy->resolveToolName($payload);
        if ($toolName !== null && $this->securityPolicy->isToolDenied($serverHandle, $toolName)) {
            throw new \DomainException('Tool is denied by security policy.');
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode JSON-RPC payload.');
        }

        $request = Request::create('/emcp/' . $serverHandle, 'POST', [], [], [], [], $encoded);
        $request->headers->set('Content-Type', 'application/json');

        $sessionId = trim((string)($meta['session_id'] ?? ''));
        if ($sessionId !== '') {
            $request->headers->set('MCP-Session-Id', $sessionId);
        }

        $traceHeader = (string)config('cms.settings.eMCP.trace.header', 'X-Trace-Id');
        $traceId = trim((string)($meta['trace_id'] ?? ''));
        if ($traceId !== '') {
            $request->headers->set($traceHeader, $traceId);
        }

        $request->attributes->set('emcp.actor_user_id', $this->resolveInt($meta['actor_user_id'] ?? null));
        $request->attributes->set('emcp.initiated_by_user_id', $this->resolveInt($meta['initiated_by_user_id'] ?? null));
        $request->attributes->set('emcp.context', trim((string)($meta['context'] ?? 'cli')) ?: 'cli');

        $response = $this->controller->__invoke($request, $serverHandle);

        if ($response instanceof StreamedResponse) {
            throw new \RuntimeException('Streaming response is not supported in async worker mode.');
        }

        return $this->normalizeResponse($request, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(Request $request, Response $response): array
    {
        $content = method_exists($response, 'getContent') ? $response->getContent() : null;
        $decoded = null;

        if (is_string($content) && trim($content) !== '') {
            $json = json_decode($content, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        return [
            'http_status' => $response->getStatusCode(),
            'session_id' => trim((string)$response->headers->get('MCP-Session-Id', '')),
            'trace_id' => TraceContext::resolve($request),
            'response' => $decoded ?? ['raw' => (string)$content],
        ];
    }

    private function resolveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
