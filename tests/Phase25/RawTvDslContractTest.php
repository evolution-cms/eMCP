<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContains(string $haystack, string $needle, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " [missing: {$needle}]");
}

$searchToolFile = __DIR__ . '/../../src/Tools/Content/ContentSearchTool.php';
$content = file_get_contents($searchToolFile);
assertTrue(is_string($content), 'Unable to read ContentSearchTool');

assertContains($content, "'tv_filters' => ['nullable', 'array']", 'tv_filters must be structured array');
assertContains($content, "'tv_order' => ['nullable', 'array']", 'tv_order must be structured array');
assertContains($content, 'buildTvFilterString', 'tv filter string must be composed from structured DTOs');
assertContains($content, 'buildTvOrderString', 'tv order string must be composed from structured DTOs');

assertTrue(!str_contains($content, "'tv_filter' => ['nullable', 'string']"), 'Raw tv_filter string DSL must be rejected');
assertTrue(!str_contains($content, "'tv_order_by' => ['nullable', 'string']"), 'Raw tv_order_by string DSL must be rejected');
assertTrue(!str_contains($content, "'tvFilter' => ['nullable', 'string']"), 'Raw tvFilter string DSL must be rejected');

echo "Phase 2.5 raw TV DSL contract checks passed.\n";
