<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\ModelCatalog;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ModelListArgs;
use EvolutionCMS\eMCP\Contracts\ToolArguments\PagingArgs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.model.list')]
#[Description('List allowlisted Evolution CMS models with explicit allowlist projection.')]
class ModelListTool extends BaseModelTool
{
    /**
     * @return array<string, mixed>
     */
    protected function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'model' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
            'filters.where' => ['nullable', 'array'],
            'filters.where.*.field' => ['required_with:filters.where', 'string'],
            'filters.where.*.op' => ['required_with:filters.where', 'string'],
            'filters.where.*.value' => ['nullable'],
            'order_by' => ['nullable', 'string'],
            'order_dir' => ['nullable', 'string'],
            'limit' => ['required', 'integer', 'min:1'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        [$limit, $offset] = $this->resolveLimitOffset($validated);

        return [
            'args' => new ModelListArgs(
                (string)$validated['model'],
                new PagingArgs($limit, $offset),
                is_array($validated['filters'] ?? null) ? $validated['filters'] : null,
                isset($validated['order_by']) ? (string)$validated['order_by'] : null,
                isset($validated['order_dir']) ? (string)$validated['order_dir'] : null,
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ModelListArgs $args */
        $args = $validated['args'];

        $modelClass = $this->resolveModelClass($args->model);
        $publicFields = $this->resolvePublicFields($modelClass);

        $query = $modelClass::query()->select($publicFields);

        $this->applyFilters($query, $modelClass, $args->filters);
        $this->applySort($query, $modelClass, $args->orderBy, $args->orderDir);

        return [
            'modelClass' => $modelClass,
            'publicFields' => $publicFields,
            'rows' => $query
                ->limit($args->paging->limit)
                ->offset($args->paging->offset)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStage(mixed $queryResult, array $validated): array
    {
        /** @var ModelListArgs $args */
        $args = $validated['args'];

        $modelClass = (string)($queryResult['modelClass'] ?? '');
        $publicFields = is_array($queryResult['publicFields'] ?? null) ? $queryResult['publicFields'] : [];
        $rows = $queryResult['rows'] ?? [];

        return [
            'modelClass' => $modelClass,
            'items' => $this->projectItems($rows, $publicFields),
            'limit' => $args->paging->limit,
            'offset' => $args->paging->offset,
        ];
    }

    protected function respondStage(array $mapped, array $validated): ResponseFactory
    {
        $items = is_array($mapped['items'] ?? null) ? $mapped['items'] : [];

        return $this->respondList(
            (string)($mapped['modelClass'] ?? ''),
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
            'model' => $schema->string()->required(),
            'limit' => $schema->integer()->minimum(1)->required(),
            'offset' => $schema->integer()->minimum(0)->nullable(),
            'order_by' => $schema->string()->nullable(),
            'order_dir' => $schema->string()->nullable(),
        ];
    }
}
