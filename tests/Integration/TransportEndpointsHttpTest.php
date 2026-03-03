<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "[transport][FAIL] {$message}\n");
    exit(1);
}

function info(string $message): void
{
    fwrite(STDOUT, "[transport] {$message}\n");
}

/**
 * @param array<int,string> $headers
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function httpRequest(string $method, string $url, string $body = '', array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            fail('curl_init failed');
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
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

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawBody = curl_exec($ch);
        if (!is_string($rawBody)) {
            $error = curl_error($ch);
            curl_close($ch);
            fail('HTTP request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $rawBody];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);

    $rawBody = @file_get_contents($url, false, $context);
    if (!is_string($rawBody)) {
        fail('HTTP request failed using stream wrapper');
    }

    $status = 0;
    $responseHeaders = [];
    /** @var array<int,string> $http_response_header */
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

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $rawBody];
}

function decodeJsonObject(string $body, string $context): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        fail("{$context}: response body is not a JSON object");
    }

    return $decoded;
}

$enabled = getenv('EMCP_INTEGRATION_ENABLED');
if ($enabled !== '1') {
    info('Skipped (set EMCP_INTEGRATION_ENABLED=1).');
    exit(0);
}

$baseUrl = rtrim(trim((string)getenv('EMCP_BASE_URL')), '/');
$server = trim((string)getenv('EMCP_SERVER_HANDLE'));
if ($baseUrl === '' || $server === '') {
    fail('EMCP_BASE_URL and EMCP_SERVER_HANDLE are required.');
}

$apiPath = trim((string)getenv('EMCP_API_PATH'));
$apiToken = trim((string)getenv('EMCP_API_TOKEN'));
$mgrPath = trim((string)getenv('EMCP_MANAGER_PATH'));
$mgrCookie = trim((string)getenv('EMCP_MANAGER_COOKIE'));
$requireManager = getenv('EMCP_REQUIRE_MANAGER_ENDPOINT') === '1';

$initializePayload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 'transport-init',
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => (object)[],
        'clientInfo' => ['name' => 'transport-integration', 'version' => '1.0.0'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($initializePayload)) {
    fail('Failed to encode initialize payload');
}

$apiRan = false;
if ($apiPath !== '' && $apiToken !== '') {
    $apiRan = true;
    $apiUrl = $baseUrl . str_replace('{server}', $server, $apiPath);

    $apiGet = httpRequest('GET', $apiUrl, '', ['Authorization: Bearer ' . $apiToken]);
    if ($apiGet['status'] !== 405) {
        fail('API GET endpoint must return 405, got ' . $apiGet['status']);
    }

    $apiPost = httpRequest('POST', $apiUrl, $initializePayload, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiToken,
    ]);
    if ($apiPost['status'] !== 200) {
        fail('API initialize expected 200, got ' . $apiPost['status']);
    }

    $apiJson = decodeJsonObject($apiPost['body'], 'API initialize');
    if (($apiJson['result']['serverInfo']['platform'] ?? null) !== 'eMCP') {
        fail('API initialize missing platform=eMCP');
    }

    $sessionId = trim((string)($apiPost['headers']['mcp-session-id'] ?? ''));
    if ($sessionId === '') {
        fail('API initialize response missing MCP-Session-Id header');
    }

    info('API endpoint checks passed.');
}

$managerRan = false;
if ($mgrPath !== '' && $mgrCookie !== '') {
    $managerRan = true;
    $managerUrl = $baseUrl . str_replace('{server}', $server, $mgrPath);

    $mgrGet = httpRequest('GET', $managerUrl, '', ['Cookie: ' . $mgrCookie]);
    if ($mgrGet['status'] !== 405) {
        fail('Manager GET endpoint must return 405, got ' . $mgrGet['status']);
    }

    $mgrPost = httpRequest('POST', $managerUrl, $initializePayload, [
        'Content-Type: application/json',
        'Cookie: ' . $mgrCookie,
    ]);
    if ($mgrPost['status'] !== 200) {
        fail('Manager initialize expected 200, got ' . $mgrPost['status']);
    }

    $mgrJson = decodeJsonObject($mgrPost['body'], 'Manager initialize');
    if (($mgrJson['result']['serverInfo']['platform'] ?? null) !== 'eMCP') {
        fail('Manager initialize missing platform=eMCP');
    }

    $mgrSessionId = trim((string)($mgrPost['headers']['mcp-session-id'] ?? ''));
    if ($mgrSessionId === '') {
        fail('Manager initialize response missing MCP-Session-Id header');
    }

    info('Manager endpoint checks passed.');
}

if (!$apiRan && !$managerRan) {
    fail('No endpoints configured. Provide API and/or manager endpoint env vars.');
}

if ($requireManager && !$managerRan) {
    fail('EMCP_REQUIRE_MANAGER_ENDPOINT=1 but manager env variables are missing.');
}

info('Transport endpoint integration checks passed.');
