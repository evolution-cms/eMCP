<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Middleware;

use Closure;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;
use Seiger\sApi\Http\Middleware\JwtAuthMiddleware;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!class_exists(JwtAuthMiddleware::class)) {
            return TransportError::response($request, 401, 'unauthenticated', 'Unauthenticated');
        }

        try {
            /** @var JwtAuthMiddleware $middleware */
            $middleware = app()->make(JwtAuthMiddleware::class);
            $response = $middleware->handle($request, $next);
        } catch (\Throwable) {
            return TransportError::response($request, 500, 'internal_error', 'Internal server error');
        }

        if ($response->getStatusCode() < 400) {
            return $response;
        }

        // Normalize only raw sApi JwtAuthMiddleware errors; keep downstream eMCP errors untouched.
        if (!$this->isRawSapiErrorResponse($response)) {
            return $response;
        }

        if ($response->getStatusCode() === 401) {
            return TransportError::response($request, 401, 'unauthenticated', 'Unauthenticated');
        }

        if ($response->getStatusCode() === 403) {
            return TransportError::response($request, 403, 'forbidden', 'Forbidden');
        }

        if ($response->getStatusCode() >= 500) {
            return TransportError::response($request, 500, 'internal_error', 'Internal server error');
        }

        return $response;
    }

    private function isRawSapiErrorResponse(Response $response): bool
    {
        if (!method_exists($response, 'getContent')) {
            return false;
        }

        $content = $response->getContent();
        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            return false;
        }

        return array_key_exists('success', $payload)
            && array_key_exists('message', $payload)
            && array_key_exists('object', $payload)
            && array_key_exists('code', $payload);
    }
}
