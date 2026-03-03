<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/governance_helpers.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function loadFixture(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException("Fixture is empty: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Fixture is not valid JSON object: {$path}");
    }

    return $decoded;
}

$root = dirname(__DIR__, 2);
$fixturesDir = $root . '/tests/Fixtures/golden';

$required = [
    'initialize.json',
    'tools_list.json',
    'evo_content_search.json',
    'evo_content_get.json',
];

foreach ($required as $fixture) {
    assertTrue(is_file($fixturesDir . '/' . $fixture), "Missing golden fixture: {$fixture}");
}

$toolset = governance_parse_toolset($root . '/TOOLSET.md');
$toolsetVersion = $toolset['toolset_version'];
$canonicalTools = $toolset['canonical_tools'];

$initialize = loadFixture($fixturesDir . '/initialize.json');
assertTrue(($initialize['result']['serverInfo']['platform'] ?? null) === 'eMCP', 'initialize fixture: platform must be eMCP');
assertTrue(
    ($initialize['result']['capabilities']['evo']['toolsetVersion'] ?? null) === $toolsetVersion,
    'initialize fixture: toolsetVersion mismatch'
);

$toolsList = loadFixture($fixturesDir . '/tools_list.json');
$fixtureTools = [];
$tools = $toolsList['result']['tools'] ?? null;
assertTrue(is_array($tools), 'tools_list fixture: result.tools must be array');
foreach ($tools as $tool) {
    if (!is_array($tool)) {
        continue;
    }

    $name = trim((string)($tool['name'] ?? ''));
    if ($name !== '') {
        $fixtureTools[] = $name;
    }
}

assertTrue($fixtureTools === $canonicalTools, 'tools_list fixture must match TOOLSET canonical tools exactly');

$search = loadFixture($fixturesDir . '/evo_content_search.json');
assertTrue(is_array($search['items'] ?? null), 'search fixture: items must be array');
assertTrue(
    ($search['meta']['toolsetVersion'] ?? null) === $toolsetVersion,
    'search fixture: toolsetVersion mismatch'
);

$get = loadFixture($fixturesDir . '/evo_content_get.json');
assertTrue(is_array($get['item'] ?? null), 'get fixture: item must be object');
assertTrue(
    ($get['meta']['toolsetVersion'] ?? null) === $toolsetVersion,
    'get fixture: toolsetVersion mismatch'
);

echo "Golden fixtures checks passed.\n";
