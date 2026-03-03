<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

$upstreamProvider = 'Laravel\\Mcp\\Server\\McpServiceProvider';
$adapterProvider = 'EvolutionCMS\\eMCP\\LaravelMcp\\McpServiceProvider';

$composerRaw = file_get_contents($root . '/composer.json');
assertTrue(is_string($composerRaw), 'Unable to read composer.json for upstream smoke test');
$composer = json_decode($composerRaw, true);
assertTrue(is_array($composer), 'composer.json is invalid JSON in upstream smoke test');

$constraint = trim((string)($composer['require']['laravel/mcp'] ?? ''));
assertTrue($constraint !== '', 'Missing laravel/mcp requirement in composer.json');
assertTrue(str_starts_with($constraint, '^0.5'), 'laravel/mcp compatibility window must be ^0.5.x');

$shimPath = $root . '/src/Support/AutoloadShims.php';
$shimContent = file_get_contents($shimPath);
assertTrue(is_string($shimContent), 'Unable to read src/Support/AutoloadShims.php');
assertTrue(str_contains($shimContent, 'class_alias'), 'Autoload shim must use class_alias interception');
$upstreamEscaped = str_replace('\\', '\\\\', $upstreamProvider);
$adapterEscaped = str_replace('\\', '\\\\', $adapterProvider);
assertTrue(
    str_contains($shimContent, $upstreamProvider) || str_contains($shimContent, $upstreamEscaped),
    'Autoload shim must reference upstream provider FQCN'
);
assertTrue(
    str_contains($shimContent, $adapterProvider) || str_contains($shimContent, $adapterEscaped),
    'Autoload shim must reference adapter provider FQCN'
);

if (class_exists($adapterProvider)) {
    require_once $shimPath;

    assertTrue(class_exists($upstreamProvider, false), 'Upstream provider alias must be present after shim load');
    assertTrue(is_a($upstreamProvider, $adapterProvider, true), 'Upstream provider alias must resolve to eMCP adapter');
}

echo "Upstream adapter smoke checks passed.\n";
