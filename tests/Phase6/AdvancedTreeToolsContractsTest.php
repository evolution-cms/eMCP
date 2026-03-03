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

/** @var array<string, array<string, string>> $advancedTools */
$advancedTools = [
    'ContentNeighborsTool.php' => [
        'name' => 'evo.content.neighbors',
        'scope' => 'neighborsOf',
    ],
    'ContentPrevSiblingsTool.php' => [
        'name' => 'evo.content.prev_siblings',
        'scope' => 'prevSiblingsOf',
    ],
    'ContentNextSiblingsTool.php' => [
        'name' => 'evo.content.next_siblings',
        'scope' => 'nextSiblingsOf',
    ],
    'ContentChildrenRangeTool.php' => [
        'name' => 'evo.content.children_range',
        'scope' => 'childrenRangeOf',
    ],
    'ContentSiblingsRangeTool.php' => [
        'name' => 'evo.content.siblings_range',
        'scope' => 'siblingsRangeOf',
    ],
];

foreach ($advancedTools as $fileName => $meta) {
    $path = $root . '/src/Tools/Content/' . $fileName;
    assertTrue(is_file($path), "Missing advanced tool file: {$fileName}");

    $content = file_get_contents($path);
    assertTrue(is_string($content), "Unable to read advanced tool file: {$fileName}");

    $toolName = $meta['name'];
    assertTrue(
        str_contains($content, "#[Name('{$toolName}')]") || str_contains($content, "#[Name(\"{$toolName}\")]"),
        "Missing canonical #[Name] for {$toolName} in {$fileName}"
    );
    assertContainsText($content, 'extends BaseContentTool', "{$fileName} must extend BaseContentTool");
    assertContainsText($content, $meta['scope'], "{$fileName} must use dedicated SiteContent scope");
    assertContainsText($content, 'resolveLimitOffset', "{$fileName} must enforce limit/offset guardrails");
    assertContainsText($content, 'normalizeWithTvs', "{$fileName} must normalize with_tvs payload");
    assertContainsText($content, 'respondList(', "{$fileName} must return list contract response");
}

/** @var array<int, string> $rangeTools */
$rangeTools = [
    'ContentChildrenRangeTool.php',
    'ContentSiblingsRangeTool.php',
];

foreach ($rangeTools as $fileName) {
    $path = $root . '/src/Tools/Content/' . $fileName;
    $content = file_get_contents($path);
    assertTrue(is_string($content), "Unable to read {$fileName}");

    assertContainsText($content, "'from' => ['required', 'integer', 'min:0']", "{$fileName} must require from");
    assertContainsText($content, "'to' => ['nullable', 'integer', 'min:0']", "{$fileName} must validate to");
    assertContainsText($content, 'to must be greater than or equal to from', "{$fileName} must reject invalid from/to");
}

echo "Advanced tree tool contract checks passed.\n";

