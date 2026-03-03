<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "[closure][FAIL] {$message}\n");
    exit(1);
}

function info(string $message): void
{
    fwrite(STDOUT, "[closure] {$message}\n");
}

/**
 * @param array<int,string> $headers
 * @param array<string,mixed> $payload
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function postJson(string $url, array $payload, array $headers = []): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fail('Failed to encode JSON payload');
    }

    $headers = array_merge(['Content-Type: application/json'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            fail('curl_init failed');
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
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

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
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

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

/**
 * @return array<string,mixed>
 */
function decodeJson(string $body, string $context): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        fail("{$context}: invalid JSON object response");
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
$apiPath = trim((string)getenv('EMCP_API_PATH'));
$token = trim((string)getenv('EMCP_API_TOKEN'));

if ($baseUrl === '' || $server === '' || $apiPath === '' || $token === '') {
    fail('EMCP_BASE_URL, EMCP_SERVER_HANDLE, EMCP_API_PATH, and EMCP_API_TOKEN are required.');
}

$url = $baseUrl . str_replace('{server}', $server, $apiPath);
$headers = ['Authorization: Bearer ' . $token];

$init = postJson($url, [
    'jsonrpc' => '2.0',
    'id' => 'closure-init',
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => (object)[],
        'clientInfo' => ['name' => 'closure-invariants', 'version' => '1.0.0'],
    ],
], $headers);
if ($init['status'] !== 200) {
    fail('initialize expected 200, got ' . $init['status']);
}

$sessionId = trim((string)($init['headers']['mcp-session-id'] ?? ''));
if ($sessionId !== '') {
    $headers[] = 'MCP-Session-Id: ' . $sessionId;
}

$rootResp = postJson($url, [
    'jsonrpc' => '2.0',
    'id' => 'closure-root',
    'method' => 'tools/call',
    'params' => [
        'name' => 'evo.content.root_tree',
        'arguments' => ['depth' => 4, 'limit' => 50, 'offset' => 0],
    ],
], $headers);
if ($rootResp['status'] !== 200) {
    fail('root_tree expected 200, got ' . $rootResp['status']);
}

$rootJson = decodeJson($rootResp['body'], 'root_tree');
if (isset($rootJson['error'])) {
    fail('root_tree returned JSON-RPC error');
}

$items = $rootJson['result']['structuredContent']['items'] ?? null;
if (!is_array($items) || $items === []) {
    info('No content items in root tree; skipping closure invariants due to empty dataset.');
    exit(0);
}

$byId = [];
$candidate = null;
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }

    $id = (int)($item['id'] ?? 0);
    if ($id < 1) {
        fail('root_tree item has invalid id');
    }

    if (isset($byId[$id])) {
        fail('root_tree returned duplicate id ' . $id);
    }

    $parent = (int)($item['parent'] ?? 0);
    if ($parent === $id) {
        fail('cycle detected: item parent equals self for id ' . $id);
    }

    $byId[$id] = $item;
    if ($candidate === null && $parent > 0) {
        $candidate = ['id' => $id, 'parent' => $parent];
    }
}

if ($candidate === null) {
    info('No non-root candidate found; closure invariants skipped for shallow dataset.');
    exit(0);
}

$candidateId = (int)$candidate['id'];
$parentId = (int)$candidate['parent'];

$ancResp = postJson($url, [
    'jsonrpc' => '2.0',
    'id' => 'closure-anc',
    'method' => 'tools/call',
    'params' => [
        'name' => 'evo.content.ancestors',
        'arguments' => ['id' => $candidateId, 'depth' => 10, 'limit' => 50, 'offset' => 0],
    ],
], $headers);
if ($ancResp['status'] !== 200) {
    fail('ancestors expected 200, got ' . $ancResp['status']);
}

$ancJson = decodeJson($ancResp['body'], 'ancestors');
if (isset($ancJson['error'])) {
    fail('ancestors returned JSON-RPC error');
}

$ancItems = $ancJson['result']['structuredContent']['items'] ?? null;
if (!is_array($ancItems)) {
    fail('ancestors result missing structuredContent.items');
}

$ancestorIds = [];
foreach ($ancItems as $anc) {
    if (!is_array($anc)) {
        continue;
    }
    $aid = (int)($anc['id'] ?? 0);
    if ($aid < 1) {
        fail('ancestors item has invalid id');
    }
    if ($aid === $candidateId) {
        fail('ancestors list must not contain candidate itself');
    }
    $ancestorIds[$aid] = true;
}

$childrenResp = postJson($url, [
    'jsonrpc' => '2.0',
    'id' => 'closure-children',
    'method' => 'tools/call',
    'params' => [
        'name' => 'evo.content.children',
        'arguments' => ['id' => $parentId, 'limit' => 100, 'offset' => 0],
    ],
], $headers);
if ($childrenResp['status'] !== 200) {
    fail('children expected 200, got ' . $childrenResp['status']);
}

$childrenJson = decodeJson($childrenResp['body'], 'children');
if (isset($childrenJson['error'])) {
    fail('children returned JSON-RPC error');
}

$childrenItems = $childrenJson['result']['structuredContent']['items'] ?? null;
if (!is_array($childrenItems)) {
    fail('children result missing structuredContent.items');
}

$foundInChildren = false;
foreach ($childrenItems as $child) {
    if (!is_array($child)) {
        continue;
    }
    $cid = (int)($child['id'] ?? 0);
    $cparent = (int)($child['parent'] ?? 0);
    if ($cid === $candidateId) {
        $foundInChildren = true;
    }
    if ($cparent !== $parentId) {
        fail('children result contains node with mismatched parent');
    }
}
if (!$foundInChildren) {
    fail('candidate node must appear in children(parent) result');
}

if ($ancestorIds !== []) {
    $topAncestorId = (int)array_key_last($ancestorIds);

    $descResp = postJson($url, [
        'jsonrpc' => '2.0',
        'id' => 'closure-desc',
        'method' => 'tools/call',
        'params' => [
            'name' => 'evo.content.descendants',
            'arguments' => ['id' => $topAncestorId, 'depth' => 10, 'limit' => 200, 'offset' => 0],
        ],
    ], $headers);
    if ($descResp['status'] !== 200) {
        fail('descendants expected 200, got ' . $descResp['status']);
    }

    $descJson = decodeJson($descResp['body'], 'descendants');
    if (isset($descJson['error'])) {
        fail('descendants returned JSON-RPC error');
    }

    $descItems = $descJson['result']['structuredContent']['items'] ?? null;
    if (!is_array($descItems)) {
        fail('descendants result missing structuredContent.items');
    }

    $foundInDescendants = false;
    foreach ($descItems as $desc) {
        if (!is_array($desc)) {
            continue;
        }
        $did = (int)($desc['id'] ?? 0);
        if ($did === $candidateId) {
            $foundInDescendants = true;
            break;
        }
    }

    if (!$foundInDescendants) {
        fail('candidate must appear in descendants(topAncestor) result');
    }
}

$siblingsResp = postJson($url, [
    'jsonrpc' => '2.0',
    'id' => 'closure-siblings',
    'method' => 'tools/call',
    'params' => [
        'name' => 'evo.content.siblings',
        'arguments' => ['id' => $candidateId, 'limit' => 100, 'offset' => 0],
    ],
], $headers);
if ($siblingsResp['status'] !== 200) {
    fail('siblings expected 200, got ' . $siblingsResp['status']);
}

$siblingsJson = decodeJson($siblingsResp['body'], 'siblings');
if (isset($siblingsJson['error'])) {
    fail('siblings returned JSON-RPC error');
}

$siblingsItems = $siblingsJson['result']['structuredContent']['items'] ?? null;
if (!is_array($siblingsItems)) {
    fail('siblings result missing structuredContent.items');
}

foreach ($siblingsItems as $sib) {
    if (!is_array($sib)) {
        continue;
    }

    $sid = (int)($sib['id'] ?? 0);
    if ($sid === $candidateId) {
        fail('siblings result must not include the requested node itself');
    }

    $sparent = (int)($sib['parent'] ?? 0);
    if ($sparent !== $parentId) {
        fail('siblings result contains node with different parent');
    }
}

info('Closure-table invariants checks passed.');
