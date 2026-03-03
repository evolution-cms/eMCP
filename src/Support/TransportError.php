<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransportError
{
    public static function response(
        Request $request,
        int $status,
        string $code,
        string $message
    ): JsonResponse {
        $traceId = TraceContext::resolve($request);
        $traceHeader = (string)config('cms.settings.eMCP.trace.header', 'X-Trace-Id');

        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'trace_id' => $traceId,
            ],
        ], $status);

        if ($traceId !== '') {
            $response->headers->set($traceHeader, $traceId);
        }

        return $response;
    }
}
