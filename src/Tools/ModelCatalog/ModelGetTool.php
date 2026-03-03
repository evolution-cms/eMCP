<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\ModelCatalog;

use EvolutionCMS\eMCP\Contracts\ToolArguments\ModelGetArgs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('evo.model.get')]
#[Description('Get one allowlisted Evolution CMS model record by id with allowlist projection.')]
class ModelGetTool extends BaseModelTool
{
    /**
     * @return array<string, mixed>
     */
    protected function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'model' => ['required', 'string'],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        return [
            'args' => new ModelGetArgs(
                (string)$validated['model'],
                (int)$validated['id']
            ),
        ];
    }

    protected function queryStage(array $validated): mixed
    {
        /** @var ModelGetArgs $args */
        $args = $validated['args'];

        $modelClass = $this->resolveModelClass($args->model);
        $publicFields = $this->resolvePublicFields($modelClass);

        return [
            'modelClass' => $modelClass,
            'publicFields' => $publicFields,
            'item' => $modelClass::query()
                ->select($publicFields)
                ->where('id', $args->id)
                ->first(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStage(mixed $queryResult, array $validated): array
    {
        $modelClass = (string)($queryResult['modelClass'] ?? '');
        $publicFields = is_array($queryResult['publicFields'] ?? null) ? $queryResult['publicFields'] : [];

        /** @var Model|null $item */
        $item = $queryResult['item'] instanceof Model ? $queryResult['item'] : null;

        return [
            'modelClass' => $modelClass,
            'item' => $item ? $this->projectItem($item, $publicFields) : null,
        ];
    }

    protected function respondStage(array $mapped, array $validated): ResponseFactory
    {
        $item = $mapped['item'] ?? null;

        return $this->respondItem(
            (string)($mapped['modelClass'] ?? ''),
            is_array($item) ? $item : null
        );
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->required(),
            'id' => $schema->integer()->min(1)->required(),
        ];
    }
}
