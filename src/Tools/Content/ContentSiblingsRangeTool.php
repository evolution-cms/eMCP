<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\Content;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ContentNodeRangeArgs;
use EvolutionCMS\eMCP\Contracts\ToolArguments\PagingArgs;
use EvolutionCMS\Models\SiteContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.content.siblings_range')]
#[Description('Read sibling nodes by menuindex position range for a node parent set.')]
class ContentSiblingsRangeTool extends BaseContentTool
{
    /**
     * @return array<string, mixed>
     */
    protected function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'integer', 'min:0'],
            'to' => ['nullable', 'integer', 'min:0'],
            'with_tvs' => ['nullable', 'array'],
            'with_tvs.*' => ['string'],
            'limit' => ['required', 'integer', 'min:1'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $from = (int)$validated['from'];
        $to = isset($validated['to']) ? (int)$validated['to'] : null;
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages([
                'to' => 'to must be greater than or equal to from.',
            ]);
        }

        [$limit, $offset] = $this->resolveLimitOffset($validated);
        $withTvs = $this->normalizeWithTvs($validated['with_tvs'] ?? null);

        return [
            'args' => new ContentNodeRangeArgs(
                (int)$validated['id'],
                $from,
                $to,
                new PagingArgs($limit, $offset),
                $withTvs['entries'],
                $withTvs['names']
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ContentNodeRangeArgs $args */
        $args = $validated['args'];

        $query = SiteContent::query()
            ->select('site_content.*')
            ->siblingsRangeOf($args->id, $args->from, $args->to)
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
        /** @var ContentNodeRangeArgs $args */
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
            'from' => $schema->integer()->min(0)->required(),
            'to' => $schema->integer()->min(0)->nullable(),
            'limit' => $schema->integer()->min(1)->required(),
            'offset' => $schema->integer()->min(0)->nullable(),
        ];
    }
}
