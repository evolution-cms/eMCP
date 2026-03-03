<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Contracts/ToolResponses/ListToolResponse.php';
require_once __DIR__ . '/../../src/Contracts/ToolResponses/ItemToolResponse.php';

use EvolutionCMS\eMCP\Contracts\ToolResponses\ItemToolResponse;
use EvolutionCMS\eMCP\Contracts\ToolResponses\ListToolResponse;

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

$list = new ListToolResponse(
    [['id' => 1]],
    10,
    0,
    1,
    '1.0'
);

$listPayload = $list->toArray();
assertTrue(($listPayload['meta']['limit'] ?? null) === 10, 'List response limit mismatch');
assertTrue(($listPayload['meta']['count'] ?? null) === 1, 'List response count mismatch');
assertTrue(!isset($listPayload['meta']['model']), 'List response model should be optional');

$listWithModel = new ListToolResponse([['id' => 2]], 5, 5, 1, '1.0', 'User');
$listWithModelPayload = $listWithModel->toArray();
assertTrue(($listWithModelPayload['meta']['model'] ?? null) === 'User', 'List response model mismatch');

$item = new ItemToolResponse(['id' => 7], '1.0');
$itemPayload = $item->toArray();
assertTrue(($itemPayload['item']['id'] ?? null) === 7, 'Item response item mismatch');
assertTrue(!isset($itemPayload['meta']['model']), 'Item response model should be optional');

$itemWithModel = new ItemToolResponse(['id' => 8], '1.0', 'SiteTemplate');
$itemWithModelPayload = $itemWithModel->toArray();
assertTrue(($itemWithModelPayload['meta']['model'] ?? null) === 'SiteTemplate', 'Item response model mismatch');

$baseContent = file_get_contents(__DIR__ . '/../../src/Tools/Content/BaseContentTool.php');
assertTrue(is_string($baseContent), 'Unable to read BaseContentTool');
assertContains($baseContent, 'final public function handle', 'BaseContentTool must own final handle');
assertContains($baseContent, 'validateStage', 'BaseContentTool must define validate stage contract');
assertContains($baseContent, 'queryStage', 'BaseContentTool must define query stage contract');
assertContains($baseContent, 'mapStage', 'BaseContentTool must define map stage contract');
assertContains($baseContent, 'paginateStage', 'BaseContentTool must define paginate stage contract');
assertContains($baseContent, 'respondStage', 'BaseContentTool must define respond stage contract');

$baseModel = file_get_contents(__DIR__ . '/../../src/Tools/ModelCatalog/BaseModelTool.php');
assertTrue(is_string($baseModel), 'Unable to read BaseModelTool');
assertContains($baseModel, 'final public function handle', 'BaseModelTool must own final handle');
assertContains($baseModel, 'validateStage', 'BaseModelTool must define validate stage contract');
assertContains($baseModel, 'queryStage', 'BaseModelTool must define query stage contract');
assertContains($baseModel, 'mapStage', 'BaseModelTool must define map stage contract');
assertContains($baseModel, 'paginateStage', 'BaseModelTool must define paginate stage contract');
assertContains($baseModel, 'respondStage', 'BaseModelTool must define respond stage contract');

$toolFiles = [
    __DIR__ . '/../../src/Tools/Content/ContentSearchTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentGetTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentRootTreeTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentDescendantsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentAncestorsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentChildrenTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentSiblingsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentNeighborsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentPrevSiblingsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentNextSiblingsTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentChildrenRangeTool.php',
    __DIR__ . '/../../src/Tools/Content/ContentSiblingsRangeTool.php',
    __DIR__ . '/../../src/Tools/ModelCatalog/ModelListTool.php',
    __DIR__ . '/../../src/Tools/ModelCatalog/ModelGetTool.php',
];

foreach ($toolFiles as $file) {
    $content = file_get_contents($file);
    assertTrue(is_string($content), "Unable to read tool file: {$file}");
    assertTrue(!str_contains($content, 'function handle('), "Tool should not override final handle: {$file}");
    assertContains($content, 'validateStage', "Missing validateStage in {$file}");
    assertContains($content, 'queryStage', "Missing queryStage in {$file}");
    assertContains($content, 'mapStage', "Missing mapStage in {$file}");
    assertContains($content, 'respondStage', "Missing respondStage in {$file}");
}

$requiredContractFiles = [
    __DIR__ . '/../../src/Contracts/ToolArguments/ContentSearchArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ContentGetArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ContentNodeListArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ContentNodeRangeArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ContentRootTreeArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ModelListArgs.php',
    __DIR__ . '/../../src/Contracts/ToolArguments/ModelGetArgs.php',
    __DIR__ . '/../../src/Contracts/ToolResponses/ListToolResponse.php',
    __DIR__ . '/../../src/Contracts/ToolResponses/ItemToolResponse.php',
    __DIR__ . '/../../src/Mappers/SiteContentMapper.php',
    __DIR__ . '/../../src/Mappers/TvMapper.php',
    __DIR__ . '/../../src/Mappers/ModelRecordMapper.php',
];

foreach ($requiredContractFiles as $file) {
    assertTrue(is_file($file), "Required contract/mapper file missing: {$file}");
}

echo "Phase 2.5 contracts and pipeline checks passed.\n";
