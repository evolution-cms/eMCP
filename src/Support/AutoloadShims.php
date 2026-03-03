<?php

declare(strict_types=1);

if (!function_exists('report')) {
    /**
     * Minimal Laravel-compatible report() shim for non-Laravel hosts.
     */
    function report(\Throwable $exception): void
    {
        try {
            if (class_exists(\Illuminate\Support\Facades\Log::class)) {
                try {
                    \Illuminate\Support\Facades\Log::channel('emcp')->error('mcp.report', [
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ]);
                    return;
                } catch (\Throwable) {
                    // Fallback below.
                }

                \Illuminate\Support\Facades\Log::error('mcp.report', [
                    'exception' => $exception,
                    'message' => $exception->getMessage(),
                ]);
            }
        } catch (\Throwable) {
            // Never let reporting failures break runtime.
        }
    }
}

$upstreamProvider = 'Laravel\\Mcp\\Server\\McpServiceProvider';
$adapterProvider = 'EvolutionCMS\\eMCP\\LaravelMcp\\McpServiceProvider';

if (!class_exists($adapterProvider)) {
    throw new RuntimeException(
        'eMCP adapter provider is missing. Verify package autoload and namespace map.'
    );
}

if (class_exists($upstreamProvider, false)) {
    if (!is_a($upstreamProvider, $adapterProvider, true)) {
        throw new RuntimeException(
            'Unable to intercept Laravel MCP provider: upstream provider is already loaded and not aliasable. ' .
            'Verify installed laravel/mcp version and update eMCP adapter/alias map.'
        );
    }

    return;
}

if (!class_alias($adapterProvider, $upstreamProvider)) {
    throw new RuntimeException(
        'Failed to alias Laravel MCP provider to eMCP adapter. ' .
        'Verify installed laravel/mcp version and update eMCP adapter/alias map.'
    );
}
