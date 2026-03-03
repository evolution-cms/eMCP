<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContainsText(string $haystack, string $needle, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " [missing: {$needle}]");
}

$root = dirname(__DIR__, 2);

/** @var array<string, string> $expectedTools */
$expectedTools = [
    'ContentSearchTool.php' => 'evo.content.search',
    'ContentGetTool.php' => 'evo.content.get',
    'ContentRootTreeTool.php' => 'evo.content.root_tree',
    'ContentDescendantsTool.php' => 'evo.content.descendants',
    'ContentAncestorsTool.php' => 'evo.content.ancestors',
    'ContentChildrenTool.php' => 'evo.content.children',
    'ContentSiblingsTool.php' => 'evo.content.siblings',
    'ContentNeighborsTool.php' => 'evo.content.neighbors',
    'ContentPrevSiblingsTool.php' => 'evo.content.prev_siblings',
    'ContentNextSiblingsTool.php' => 'evo.content.next_siblings',
    'ContentChildrenRangeTool.php' => 'evo.content.children_range',
    'ContentSiblingsRangeTool.php' => 'evo.content.siblings_range',
];

foreach ($expectedTools as $fileName => $toolName) {
    $path = $root . '/src/Tools/Content/' . $fileName;
    assertTrue(is_file($path), "Missing tool file: {$fileName}");

    $content = file_get_contents($path);
    assertTrue(is_string($content), "Unable to read tool file: {$fileName}");

    assertTrue(
        str_contains($content, "#[Name('{$toolName}')]") || str_contains($content, "#[Name(\"{$toolName}\")]"),
        "Missing canonical #[Name] for {$toolName} in {$fileName}"
    );
    assertContainsText($content, 'extends BaseContentTool', "{$fileName} must extend BaseContentTool");
}

$searchPath = $root . '/src/Tools/Content/ContentSearchTool.php';
$searchContent = file_get_contents($searchPath);
assertTrue(is_string($searchContent), 'Unable to read ContentSearchTool.php');
assertContainsText($searchContent, "'tv_filters' => ['nullable', 'array']", 'search must accept structured tv_filters array');
assertContainsText($searchContent, "'tv_order' => ['nullable', 'array']", 'search must accept structured tv_order array');
assertContainsText($searchContent, 'buildTvFilterString', 'search must build tv filter string from structured payload');
assertContainsText($searchContent, 'buildTvOrderString', 'search must build tv order string from structured payload');
assertContainsText($searchContent, 'resolveLimitOffset', 'search must enforce limit/offset guardrails');
assertContainsText($searchContent, 'resolveDepth', 'search must enforce depth guardrails');

$treeTools = [
    'ContentRootTreeTool.php',
    'ContentDescendantsTool.php',
    'ContentAncestorsTool.php',
    'ContentChildrenTool.php',
    'ContentSiblingsTool.php',
    'ContentNeighborsTool.php',
    'ContentPrevSiblingsTool.php',
    'ContentNextSiblingsTool.php',
    'ContentChildrenRangeTool.php',
    'ContentSiblingsRangeTool.php',
];

foreach ($treeTools as $fileName) {
    $path = $root . '/src/Tools/Content/' . $fileName;
    $content = file_get_contents($path);
    assertTrue(is_string($content), "Unable to read {$fileName}");

    assertContainsText($content, 'resolveLimitOffset', "{$fileName} must enforce limit/offset");
    assertContainsText($content, 'normalizeWithTvs', "{$fileName} must normalize with_tvs payload");
    assertContainsText($content, '->where(', "{$fileName} must apply explicit query filters");
    assertContainsText($content, 'respondList(', "{$fileName} must return list contract response");
}

$rangeTools = [
    'ContentChildrenRangeTool.php',
    'ContentSiblingsRangeTool.php',
];

foreach ($rangeTools as $fileName) {
    $path = $root . '/src/Tools/Content/' . $fileName;
    $content = file_get_contents($path);
    assertTrue(is_string($content), "Unable to read {$fileName}");

    assertContainsText($content, "'from' => ['required', 'integer', 'min:0']", "{$fileName} must require from range bound");
    assertContainsText($content, "'to' => ['nullable', 'integer', 'min:0']", "{$fileName} must validate to range bound");
    assertContainsText($content, 'to must be greater than or equal to from', "{$fileName} must reject invalid ranges");
}

echo "SiteContent tree/TV contract checks passed.\n";
