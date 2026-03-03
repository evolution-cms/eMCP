<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\Content;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ContentGetArgs;
use EvolutionCMS\Models\SiteContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.content.get')]
#[Description('Get one content document by id with optional TVs projection.')]
class ContentGetTool extends BaseContentTool
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
        ]);

        $withTvs = $this->normalizeWithTvs($validated['with_tvs'] ?? null);

        return [
            'args' => new ContentGetArgs(
                (int)$validated['id'],
                $withTvs['entries'],
                $withTvs['names']
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ContentGetArgs $args */
        $args = $validated['args'];

        $query = SiteContent::query()
            ->select('site_content.*')
            ->where('id', $args->id)
            ->where('deleted', 0);

        if ($args->withTvEntries !== []) {
            $query->withTVs($args->withTvEntries);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStage(mixed $queryResult, array $validated): array
    {
        /** @var ContentGetArgs $args */
        $args = $validated['args'];

        /** @var SiteContent|null $item */
        $item = $queryResult instanceof SiteContent ? $queryResult : null;

        return [
            'item' => $item ? $this->projectItem($item, $args->withTvNames) : null,
        ];
    }

    protected function respondStage(array $mapped, array $validated): ResponseFactory
    {
        $item = $mapped['item'] ?? null;

        return $this->respondItem(is_array($item) ? $item : null);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required(),
        ];
    }
}
