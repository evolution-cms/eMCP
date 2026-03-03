<?php

declare(strict_types=1);

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
