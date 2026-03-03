<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);

$controllerPath = $root . '/src/Http/Controllers/McpManagerController.php';
$controller = file_get_contents($controllerPath);
assertTrue(is_string($controller), 'Unable to read McpManagerController.php');

assertTrue(
    str_contains($controller, "->headers->set('Content-Type', 'text/event-stream')"),
    'Streaming policy must enforce SSE content type.'
);
assertTrue(
    str_contains($controller, "->headers->set('Cache-Control', 'no-cache, no-transform')"),
    'Streaming policy must enforce no-cache guard for streaming under PHP-FPM/proxies.'
);
assertTrue(
    str_contains($controller, "->headers->set('X-Accel-Buffering', 'no')"),
    'Streaming policy must disable nginx buffering for PHP-FPM deployments.'
);
assertTrue(
    str_contains($controller, 'resolveHeartbeatSeconds') && str_contains($controller, 'resolveStreamMaxSeconds'),
    'Streaming policy must resolve heartbeat/max-stream limits from config.'
);

$docs = file_get_contents($root . '/DOCS.md');
assertTrue(is_string($docs), 'Unable to read DOCS.md');
assertTrue(str_contains($docs, 'disable buffering for MCP streaming routes'), 'DOCS.md must mention proxy buffering disable for streaming.');
assertTrue(str_contains($docs, 'PHP-FPM/FastCGI'), 'DOCS.md must mention PHP-FPM/FastCGI constraints.');

$ops = file_get_contents($root . '/OPERATIONS.md');
assertTrue(is_string($ops), 'Unable to read OPERATIONS.md');
assertTrue(str_contains($ops, 'Streaming Infrastructure Check'), 'OPERATIONS.md must include streaming infra verification section.');
assertTrue(str_contains($ops, 'response should be SSE'), 'OPERATIONS.md must document SSE expectation when streaming is enabled.');

echo "Streaming PHP-FPM constraints checks passed.\n";
