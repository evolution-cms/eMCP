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
 * @param  array<int, string>  $headers
 * @return array{status:int, headers:array<string, string>, body:string}
 */
function httpPostRaw(string $url, string $rawBody, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            fail('curl_init failed.');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
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
            'header' => implode("\r\n", $headers),
            'content' => $rawBody,
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

/**
 * @return array<string, mixed>
 */
function assertTransportError(array $response, int $expectedStatus, string $context): array
{
    if (($response['status'] ?? 0) !== $expectedStatus) {
        fail("{$context} expected HTTP {$expectedStatus}, got " . (int)($response['status'] ?? 0) . '.');
    }

    $json = decodeJson((string)($response['body'] ?? ''));
    $error = $json['error'] ?? null;
    if (!is_array($error)) {
        fail("{$context} missing error object.");
    }

    $traceId = trim((string)($error['trace_id'] ?? ''));
    if ($traceId === '') {
        fail("{$context} missing error.trace_id.");
    }

    return $json;
}

/**
 * @param  array<string, mixed>  $payload
 */
function issueHs256Jwt(array $payload, string $secret): string
{
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $encode = static function (string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $signingInput = implode('.', [
        $encode((string)json_encode($header, JSON_UNESCAPED_SLASHES)),
        $encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ]);

    $signature = hash_hmac('sha256', $signingInput, $secret, true);

    return $signingInput . '.' . $encode($signature);
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
$runNegativeChecks = getenv('EMCP_RUNTIME_NEGATIVE') === '1';
$runModelSanity = $runNegativeChecks || getenv('EMCP_RUNTIME_MODEL_SANITY') === '1';
$requireRateLimitProbe = getenv('EMCP_RUNTIME_NEGATIVE_REQUIRE_RATE_LIMIT') === '1';
$rateProbeMaxAttempts = (int)getenv('EMCP_RUNTIME_RATE_PROBE_MAX');
if ($rateProbeMaxAttempts < 1) {
    $rateProbeMaxAttempts = 90;
}

$readOnlyToken = trim((string)getenv('EMCP_TEST_JWT_READ_TOKEN'));
$jwtSecret = trim((string)getenv('EMCP_TEST_JWT_SECRET'));
if ($readOnlyToken === '' && $jwtSecret !== '') {
    $now = time();
    $readOnlyToken = issueHs256Jwt([
        'sub' => 'runtime-readonly',
        'user_id' => 1,
        'scopes' => ['mcp:read'],
        'iat' => $now,
        'exp' => $now + 3600,
    ], $jwtSecret);
}

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

    if ($runModelSanity) {
        $modelCall = httpPostJson($url, [
            'jsonrpc' => '2.0',
            'id' => 'model-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'evo.model.get',
                'arguments' => [
                    'model' => 'User',
                    'id' => 1,
                ],
            ],
        ], $callHeaders);

        if ($modelCall['status'] !== 200) {
            fail("{$label} model sanity expected HTTP 200, got {$modelCall['status']}.");
        }

        $modelJson = decodeJson($modelCall['body']);
        if (isset($modelJson['error'])) {
            $msg = (string)($modelJson['error']['message'] ?? 'unknown error');
            fail("{$label} evo.model.get returned JSON-RPC error: {$msg}");
        }

        $structured = $modelJson['result']['structuredContent'] ?? null;
        if (!is_array($structured)) {
            fail("{$label} evo.model.get missing result.structuredContent.");
        }

        $item = $structured['item'] ?? null;
        if (!is_array($item)) {
            fail("{$label} evo.model.get missing structured item.");
        }

        $sensitiveFields = [
            'password',
            'cachepwd',
            'verified_key',
            'refresh_token',
            'access_token',
            'sessionid',
        ];

        foreach ($sensitiveFields as $field) {
            if (array_key_exists($field, $item)) {
                fail("{$label} evo.model.get leaked sensitive field [{$field}].");
            }
        }
    }

    if ($runNegativeChecks && $label === 'api') {
        $initPayload = [
            'jsonrpc' => '2.0',
            'id' => 'init-neg-unauth',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object)[],
                'clientInfo' => [
                    'name' => 'emcp-runtime-negative',
                    'version' => '1.0.0',
                ],
            ],
        ];

        assertTransportError(
            httpPostJson($url, $initPayload, []),
            401,
            "{$label} initialize without Authorization"
        );

        assertTransportError(
            httpPostRaw($url, 'plain text body', array_merge($baseHeaders, ['Content-Type: text/plain'])),
            415,
            "{$label} unsupported media type"
        );

        $oversizedCall = httpPostJson($url, [
            'jsonrpc' => '2.0',
            'id' => 'oversized-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'evo.content.search',
                'arguments' => [
                    'limit' => 1,
                    'offset' => 0,
                    'padding' => str_repeat('x', 300 * 1024),
                ],
            ],
        ], $callHeaders);

        assertTransportError($oversizedCall, 413, "{$label} oversized payload");

        if ($readOnlyToken !== '') {
            $readHeaders = ['Authorization: Bearer ' . $readOnlyToken];
            $readInit = httpPostJson($url, [
                'jsonrpc' => '2.0',
                'id' => 'init-readonly',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => (object)[],
                    'clientInfo' => [
                        'name' => 'emcp-runtime-readonly',
                        'version' => '1.0.0',
                    ],
                ],
            ], $readHeaders);

            if ($readInit['status'] !== 200) {
                fail("{$label} read-only token initialize expected 200, got {$readInit['status']}.");
            }

            $readSessionId = trim((string)($readInit['headers']['mcp-session-id'] ?? ''));
            if ($readSessionId !== '') {
                $readHeaders[] = 'MCP-Session-Id: ' . $readSessionId;
            }

            assertTransportError(
                httpPostJson($url, [
                    'jsonrpc' => '2.0',
                    'id' => 'call-readonly',
                    'method' => 'tools/call',
                    'params' => [
                        'name' => 'evo.content.search',
                        'arguments' => ['limit' => 1, 'offset' => 0],
                    ],
                ], $readHeaders),
                403,
                "{$label} scope denied on tools/call"
            );
        } else {
            info("{$label} negative scope probe skipped (EMCP_TEST_JWT_READ_TOKEN/EMCP_TEST_JWT_SECRET not provided).");
        }

        $rateLimited = false;
        $rateRetryAfter = 0;
        for ($i = 1; $i <= $rateProbeMaxAttempts; $i++) {
            $probe = httpPostJson($url, [
                'jsonrpc' => '2.0',
                'id' => 'rate-' . $i,
                'method' => 'tools/list',
                'params' => (object)[],
            ], $callHeaders);

            if ($probe['status'] === 429) {
                $rateLimited = true;
                $rateRetryAfter = (int)($probe['headers']['retry-after'] ?? 0);
                if ($rateRetryAfter < 1) {
                    fail("{$label} rate-limit probe got 429 without Retry-After.");
                }
                break;
            }
        }

        if ($requireRateLimitProbe && !$rateLimited) {
            fail("{$label} expected 429 during rate-limit probe, none observed.");
        }

        if ($rateLimited) {
            info("{$label} rate-limit probe observed 429 with Retry-After={$rateRetryAfter}.");
        } else {
            info("{$label} rate-limit probe did not hit 429 within {$rateProbeMaxAttempts} requests.");
        }
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
        $dispatchAJson = decodeJson($dispatchA['body']);
        if (($dispatchAJson['reused'] ?? null) !== false) {
            fail("{$label} dispatch first call must return reused=false.");
        }
        if (($dispatchAJson['idempotency_key'] ?? null) !== $key) {
            fail("{$label} dispatch first call missing idempotency_key.");
        }
        $dispatchATaskId = is_numeric($dispatchAJson['task_id'] ?? null) ? (int)$dispatchAJson['task_id'] : null;

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
        $dispatchBJson = decodeJson($dispatchB['body']);
        if (($dispatchBJson['reused'] ?? null) !== true) {
            fail("{$label} dispatch reuse must return reused=true.");
        }
        if (($dispatchBJson['idempotency_key'] ?? null) !== $key) {
            fail("{$label} dispatch reuse missing idempotency_key.");
        }
        $dispatchBTaskId = is_numeric($dispatchBJson['task_id'] ?? null) ? (int)$dispatchBJson['task_id'] : null;
        if ($dispatchATaskId !== null && $dispatchATaskId > 0 && $dispatchBTaskId !== $dispatchATaskId) {
            fail("{$label} dispatch reuse expected same task_id={$dispatchATaskId}, got " . (string)($dispatchBJson['task_id'] ?? 'null') . '.');
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
        $dispatchCJson = decodeJson($dispatchC['body']);
        if (($dispatchCJson['error']['code'] ?? null) !== 'idempotency_conflict') {
            fail("{$label} dispatch conflict expected error.code=idempotency_conflict.");
        }
        $traceId = trim((string)($dispatchCJson['error']['trace_id'] ?? ''));
        if ($traceId === '') {
            fail("{$label} dispatch conflict missing error.trace_id.");
        }
    }

    info("{$label} checks passed.");
}

info('Runtime integration checks passed.');
