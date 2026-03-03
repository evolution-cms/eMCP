<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Servers;

use EvolutionCMS\eMCP\Tools\Content\ContentChildrenTool;
use EvolutionCMS\eMCP\Tools\Content\ContentGetTool;
use EvolutionCMS\eMCP\Tools\Content\ContentRootTreeTool;
use EvolutionCMS\eMCP\Tools\Content\ContentSearchTool;
use EvolutionCMS\eMCP\Tools\Content\ContentDescendantsTool;
use EvolutionCMS\eMCP\Tools\Content\ContentAncestorsTool;
use EvolutionCMS\eMCP\Tools\Content\ContentSiblingsTool;
use EvolutionCMS\eMCP\Tools\ModelCatalog\ModelGetTool;
use EvolutionCMS\eMCP\Tools\ModelCatalog\ModelListTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('eMCP Content Server')]
#[Version('1.0.0')]
#[Instructions('Default eMCP server for initialize/tools:list smoke checks in clean Evolution CMS.')]
class ContentServer extends Server
{
    protected array $tools = [
        ContentSearchTool::class,
        ContentGetTool::class,
        ContentRootTreeTool::class,
        ContentDescendantsTool::class,
        ContentAncestorsTool::class,
        ContentChildrenTool::class,
        ContentSiblingsTool::class,
        ModelListTool::class,
        ModelGetTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
