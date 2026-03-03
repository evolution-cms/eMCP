<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/governance_helpers.php';

function fail(string $message): never
{
    fwrite(STDERR, "[integration][FAIL] {$message}\n");
    exit(1);
}

function info(string $message): void
{
    fwrite(STDOUT, "[integration] {$message}\n");
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<int, string>  $headers
 * @return array{status:int, headers:array<string, string>, body:string}
 */
function httpPostJson(string $url, array $payload, array $headers = []): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fail('Failed to encode JSON payload.');
    }

    $baseHeaders = ['Content-Type: application/json'];
    $headerList = array_merge($baseHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            fail('curl_init failed.');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($line);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if (!is_string($body)) {
            $error = curl_error($ch);
            curl_close($ch);
            fail('HTTP request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerList),
            'content' => $json,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        fail('HTTP request failed using stream context.');
    }

    $status = 0;
    $responseHeaders = [];

    /** @var array<int, string> $http_response_header */
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int)$m[1];
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $body,
    ];
}

/**
 * @return array<string, mixed>
 */
function decodeJson(string $body): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        fail('Response is not valid JSON object: ' . $body);
    }

    return $decoded;
}

/**
 * @param  array<int, array<string, mixed>>  $tools
 * @return array<int, string>
 */
function toolNames(array $tools): array
{
    $names = [];

    foreach ($tools as $tool) {
        if (!is_array($tool)) {
            continue;
        }

        $name = trim((string)($tool['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

$enabled = getenv('EMCP_INTEGRATION_ENABLED');
if ($enabled !== '1') {
    info('Skipped (set EMCP_INTEGRATION_ENABLED=1 to run runtime integration checks).');
    exit(0);
}

$baseUrl = rtrim(trim((string)getenv('EMCP_BASE_URL')), '/');
if ($baseUrl === '') {
    fail('EMCP_BASE_URL is required when integration checks are enabled.');
}

$server = trim((string)getenv('EMCP_SERVER_HANDLE'));
if ($server === '') {
    $server = 'content';
}

$toolset = governance_parse_toolset(dirname(__DIR__, 2) . '/TOOLSET.md');
$toolsetVersion = $toolset['toolset_version'];
$canonicalTools = $toolset['canonical_tools'];

$targets = [];

$apiPathTemplate = trim((string)getenv('EMCP_API_PATH'));
$apiToken = trim((string)getenv('EMCP_API_TOKEN'));
if ($apiPathTemplate !== '' && $apiToken !== '') {
    $targets[] = [
        'label' => 'api',
        'url' => $baseUrl . str_replace('{server}', $server, $apiPathTemplate),
        'headers' => [
            'Authorization: Bearer ' . $apiToken,
        ],
    ];
}

$mgrPathTemplate = trim((string)getenv('EMCP_MANAGER_PATH'));
$mgrCookie = trim((string)getenv('EMCP_MANAGER_COOKIE'));
if ($mgrPathTemplate !== '' && $mgrCookie !== '') {
    $targets[] = [
        'label' => 'manager',
        'url' => $baseUrl . str_replace('{server}', $server, $mgrPathTemplate),
        'headers' => [
            'Cookie: ' . $mgrCookie,
        ],
    ];
}

if ($targets === []) {
    fail('No runnable target. Configure EMCP_API_PATH+EMCP_API_TOKEN and/or EMCP_MANAGER_PATH+EMCP_MANAGER_COOKIE.');
}

$runDispatchCheck = getenv('EMCP_DISPATCH_CHECK') === '1';

foreach ($targets as $target) {
    $label = (string)$target['label'];
    $url = (string)$target['url'];
    /** @var array<int, string> $baseHeaders */
    $baseHeaders = $target['headers'];

    info("Running {$label} checks against {$url}");

    $init = httpPostJson($url, [
        'jsonrpc' => '2.0',
        'id' => 'init-1',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object)[],
            'clientInfo' => [
                'name' => 'emcp-runtime-integration',
                'version' => '1.0.0',
            ],
        ],
    ], $baseHeaders);

    if ($init['status'] !== 200) {
        fail("{$label} initialize expected HTTP 200, got {$init['status']}.");
    }

    $initJson = decodeJson($init['body']);
    if (($initJson['result']['serverInfo']['platform'] ?? null) !== 'eMCP') {
        fail("{$label} initialize missing platform=eMCP.");
    }

    if (($initJson['result']['capabilities']['evo']['toolsetVersion'] ?? null) !== $toolsetVersion) {
        fail("{$label} initialize toolsetVersion mismatch.");
    }

    $sessionId = trim((string)($init['headers']['mcp-session-id'] ?? ''));
    $callHeaders = $baseHeaders;
    if ($sessionId !== '') {
        $callHeaders[] = 'MCP-Session-Id: ' . $sessionId;
    }

    $list = httpPostJson($url, [
        'jsonrpc' => '2.0',
        'id' => 'tools-1',
        'method' => 'tools/list',
        'params' => (object)[],
    ], $callHeaders);

    if ($list['status'] !== 200) {
        fail("{$label} tools/list expected HTTP 200, got {$list['status']}.");
    }

    $listJson = decodeJson($list['body']);
    $tools = $listJson['result']['tools'] ?? null;
    if (!is_array($tools)) {
        fail("{$label} tools/list missing result.tools array.");
    }

    $actual = toolNames($tools);
    $missing = array_values(array_diff($canonicalTools, $actual));
    if ($missing !== []) {
        fail("{$label} tools/list missing canonical tools: " . implode(', ', $missing));
    }

    $call = httpPostJson($url, [
        'jsonrpc' => '2.0',
        'id' => 'call-1',
        'method' => 'tools/call',
        'params' => [
            'name' => 'evo.content.search',
            'arguments' => [
                'limit' => 1,
                'offset' => 0,
            ],
        ],
    ], $callHeaders);

    if ($call['status'] !== 200) {
        fail("{$label} tools/call expected HTTP 200, got {$call['status']}.");
    }

    $callJson = decodeJson($call['body']);
    if (isset($callJson['error'])) {
        $msg = (string)($callJson['error']['message'] ?? 'unknown error');
        fail("{$label} tools/call returned JSON-RPC error: {$msg}");
    }

    if ($runDispatchCheck) {
        $dispatchUrl = rtrim($url, '/') . '/dispatch';
        $key = 'runtime-k1';

        $dispatchA = httpPostJson($dispatchUrl, [
            'jsonrpc' => '2.0',
            'id' => 'd1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'evo.content.search',
                'arguments' => ['limit' => 1, 'offset' => 0],
            ],
        ], array_merge($callHeaders, ['Idempotency-Key: ' . $key]));

        if ($dispatchA['status'] === 501) {
            fail("{$label} dispatch still returns 501.");
        }

        if (!in_array($dispatchA['status'], [200, 202], true)) {
            fail("{$label} dispatch first call expected 200/202, got {$dispatchA['status']}.");
        }

        $dispatchB = httpPostJson($dispatchUrl, [
            'jsonrpc' => '2.0',
            'id' => 'd2',
            'method' => 'tools/call',
            'params' => [
                'name' => 'evo.content.search',
                'arguments' => ['limit' => 1, 'offset' => 0],
            ],
        ], array_merge($callHeaders, ['Idempotency-Key: ' . $key]));

        if (!in_array($dispatchB['status'], [200, 202], true)) {
            fail("{$label} dispatch reuse expected 200/202, got {$dispatchB['status']}.");
        }

        $dispatchC = httpPostJson($dispatchUrl, [
            'jsonrpc' => '2.0',
            'id' => 'd3',
            'method' => 'tools/call',
            'params' => [
                'name' => 'evo.content.search',
                'arguments' => ['limit' => 2, 'offset' => 0],
            ],
        ], array_merge($callHeaders, ['Idempotency-Key: ' . $key]));

        if ($dispatchC['status'] !== 409) {
            fail("{$label} dispatch conflict expected 409, got {$dispatchC['status']}.");
        }
    }

    info("{$label} checks passed.");
}

info('Runtime integration checks passed.');
