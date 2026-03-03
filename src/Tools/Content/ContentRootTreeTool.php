<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\Content;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ContentRootTreeArgs;
use EvolutionCMS\eMCP\Contracts\ToolArguments\PagingArgs;
use EvolutionCMS\Models\SiteContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.content.root_tree')]
#[Description('Read root tree content (from root nodes) with bounded depth and pagination.')]
class ContentRootTreeTool extends BaseContentTool
{
    /**
     * @return array<string, mixed>
     */
    protected function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'depth' => ['nullable', 'integer', 'min:1'],
            'with_tvs' => ['nullable', 'array'],
            'with_tvs.*' => ['string'],
            'limit' => ['required', 'integer', 'min:1'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        [$limit, $offset] = $this->resolveLimitOffset($validated);
        $depth = $this->resolveDepth(
            isset($validated['depth']) ? (int)$validated['depth'] : null
        );
        $withTvs = $this->normalizeWithTvs($validated['with_tvs'] ?? null);

        return [
            'args' => new ContentRootTreeArgs(
                $depth,
                new PagingArgs($limit, $offset),
                $withTvs['entries'],
                $withTvs['names']
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ContentRootTreeArgs $args */
        $args = $validated['args'];

        $query = SiteContent::query()
            ->getRootTree($args->depth + 1)
            ->where('t2.deleted', 0)
            ->orderBy('t2.id', 'asc');

        if ($args->withTvEntries !== []) {
            $query->withTVs($args->withTvEntries, ':', true);
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
        /** @var ContentRootTreeArgs $args */
        $args = $validated['args'];

        return [
            'items' => $this->projectItems($queryResult, $args->withTvNames),
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
            'depth' => $schema->integer()->minimum(1)->nullable(),
            'limit' => $schema->integer()->minimum(1)->required(),
            'offset' => $schema->integer()->minimum(0)->nullable(),
        ];
    }
}
