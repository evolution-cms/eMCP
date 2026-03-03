<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Support/ModelFieldPolicy.php';

use EvolutionCMS\eMCP\Support\ModelFieldPolicy;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<int, string>
 */
function extractQuotedList(string $content, string $constantName): array
{
    if (!preg_match('/' . preg_quote($constantName, '/') . '\\s*=\\s*\\[(.*?)\\];/s', $content, $m)) {
        throw new RuntimeException("Unable to parse {$constantName} list.");
    }

    preg_match_all('/\'([^\']+)\'/', (string)$m[1], $values);

    return array_values(array_unique(array_map(
        static fn(string $value): string => trim($value),
        $values[1] ?? []
    )));
}

$baseContentPath = __DIR__ . '/../../src/Tools/Content/BaseContentTool.php';
$baseContent = file_get_contents($baseContentPath);
assertTrue(is_string($baseContent), 'Unable to read BaseContentTool.php');

$operators = extractQuotedList($baseContent, 'ALLOWED_TV_OPERATORS');
$casts = extractQuotedList($baseContent, 'ALLOWED_TV_CASTS');

$expectedOperators = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'like', 'like-l', 'like-r', 'null', '!null'];
$expectedCasts = ['UNSIGNED', 'SIGNED'];

assertTrue($operators === $expectedOperators, 'Allowed TV operators list mismatch in BaseContentTool');
assertTrue($casts === $expectedCasts, 'Allowed TV casts list mismatch in BaseContentTool');
assertTrue(!in_array('decimal', array_map('strtolower', $casts), true), 'DECIMAL cast must not be allowed');

$allowlists = ModelFieldPolicy::fieldAllowlists();
$userFields = $allowlists['User'] ?? null;
assertTrue(is_array($userFields), 'User allowlist must be defined');

$sensitive = array_map(
    static fn(string $field): string => strtolower(trim($field)),
    ModelFieldPolicy::sensitiveFields()
);

$userLookup = [];
foreach ($userFields as $field) {
    $userLookup[strtolower(trim((string)$field))] = true;
}

foreach ($sensitive as $field) {
    assertTrue(!isset($userLookup[$field]), "Sensitive field leaked into User allowlist: {$field}");
}

$requiredSafeFields = ['id', 'username', 'blocked', 'createdon'];
foreach ($requiredSafeFields as $field) {
    assertTrue(isset($userLookup[$field]), "Expected safe User field missing from allowlist: {$field}");
}

$scopesMiddleware = file_get_contents(__DIR__ . '/../../src/Middleware/EnsureMcpScopes.php');
assertTrue(is_string($scopesMiddleware), 'Unable to read EnsureMcpScopes.php');
assertTrue(
    str_contains($scopesMiddleware, '$requiredScope = $requiredScope ?? \'mcp:admin\';') ||
    str_contains($scopesMiddleware, "requiredScope = $requiredScope ?? 'mcp:admin'"),
    'EnsureMcpScopes must deny unknown methods by requiring mcp:admin'
);

$apiJwtMiddleware = file_get_contents(__DIR__ . '/../../src/Middleware/EnsureApiJwt.php');
assertTrue(is_string($apiJwtMiddleware), 'Unable to read EnsureApiJwt.php');
assertTrue(str_contains($apiJwtMiddleware, 'TransportError::response($request, 401'), 'EnsureApiJwt must normalize unauthenticated to 401 transport error');
assertTrue(str_contains($apiJwtMiddleware, 'TransportError::response($request, 403'), 'EnsureApiJwt must normalize forbidden to 403 transport error');

$settings = file_get_contents(__DIR__ . '/../../config/eMCPSettings.php');
assertTrue(is_string($settings), 'Unable to read config/eMCPSettings.php');
assertTrue(str_contains($settings, "'redact_keys'"), 'Config must define redact_keys list');
assertTrue(str_contains($settings, "'password'"), 'Config redact_keys must include password');
assertTrue(str_contains($settings, "'token'"), 'Config redact_keys must include token');

echo "Security guardrail checks passed.\n";
