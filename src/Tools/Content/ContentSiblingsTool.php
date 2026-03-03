<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\Content;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ContentNodeListArgs;
use EvolutionCMS\eMCP\Contracts\ToolArguments\PagingArgs;
use EvolutionCMS\Models\SiteContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.content.siblings')]
#[Description('Read sibling documents of a content node with pagination.')]
class ContentSiblingsTool extends BaseContentTool
{
    /**
     * @return array<string, mixed>
     */
    protected function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'with_tvs' => ['nullable', 'array'],
            'with_tvs.*' => ['string'],
            'limit' => ['required', 'integer', 'min:1'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        [$limit, $offset] = $this->resolveLimitOffset($validated);
        $withTvs = $this->normalizeWithTvs($validated['with_tvs'] ?? null);

        return [
            'args' => new ContentNodeListArgs(
                (int)$validated['id'],
                new PagingArgs($limit, $offset),
                $withTvs['entries'],
                $withTvs['names']
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ContentNodeListArgs $args */
        $args = $validated['args'];

        /** @var SiteContent|null $node */
        $node = SiteContent::query()
            ->select('id', 'parent')
            ->where('id', $args->id)
            ->where('deleted', 0)
            ->first();

        if ($node === null) {
            return [];
        }

        $query = SiteContent::query()
            ->select('site_content.*')
            ->where('site_content.parent', (int)$node->getAttribute('parent'))
            ->where('site_content.id', '!=', $args->id)
            ->where('site_content.deleted', 0)
            ->orderBy('site_content.menuindex', 'asc')
            ->orderBy('site_content.id', 'asc');

        if ($args->withTvEntries !== []) {
            $query->withTVs($args->withTvEntries);
        }

        return $query
            ->limit($args->paging->limit)
            ->offset($args->paging->offset)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStage(mixed $queryResult, array $validated): array
    {
        /** @var ContentNodeListArgs $args */
        $args = $validated['args'];

        return [
            'items' => $this->projectItems(is_iterable($queryResult) ? $queryResult : [], $args->withTvNames),
            'limit' => $args->paging->limit,
            'offset' => $args->paging->offset,
        ];
    }

    protected function respondStage(array $mapped, array $validated): ResponseFactory
    {
        $items = is_array($mapped['items'] ?? null) ? $mapped['items'] : [];

        return $this->respondList(
            $items,
            (int)($mapped['limit'] ?? 0),
            (int)($mapped['offset'] ?? 0)
        );
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required(),
            'limit' => $schema->integer()->min(1)->required(),
            'offset' => $schema->integer()->min(0)->nullable(),
        ];
    }
}
