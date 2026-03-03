<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\ModelCatalog;

use EvolutionCMS\eMCP\Contracts\ToolResponses\ItemToolResponse;
use EvolutionCMS\eMCP\Contracts\ToolResponses\ListToolResponse;
use EvolutionCMS\eMCP\Mappers\ModelRecordMapper;
use EvolutionCMS\eMCP\Support\ModelFieldPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class BaseModelTool extends Tool
{
    private ?ModelRecordMapper $modelRecordMapper = null;

    /**
     * @var array<int, string>
     */
    protected const ALLOWED_OPERATORS = [
        '=',
        '!=',
        '>',
        '>=',
        '<',
        '<=',
        'in',
        'not_in',
        'like',
        'like-l',
        'like-r',
        'null',
        '!null',
    ];

    final public function handle(Request $request): ResponseFactory
    {
        $validated = $this->validateStage($request);
        $this->authorizeStage($validated, $request);
        $queryResult = $this->queryStage($validated);
        $mapped = $this->mapStage($queryResult, $validated);
        $paginated = $this->paginateStage($mapped, $validated);
        $response = $this->respondStage($paginated, $validated);
        $this->auditStage($validated, $paginated, $response, $request);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function validateStage(Request $request): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function authorizeStage(array $validated, Request $request): void
    {
        // Model tools are read-only by default; access control is enforced in middleware/policy.
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    abstract protected function queryStage(array $validated): mixed;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    abstract protected function mapStage(mixed $queryResult, array $validated): array;

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $validated
     */
    protected function paginateStage(array $mapped, array $validated): array
    {
        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $validated
     */
    abstract protected function respondStage(array $paginated, array $validated): ResponseFactory;

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $paginated
     */
    protected function auditStage(array $validated, array $paginated, ResponseFactory $response, Request $request): void
    {
        // Reserved for explicit audit logger wiring in Gate E.
    }

    protected function resolveModelClass(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            throw ValidationException::withMessages([
                'model' => 'model is required.',
            ]);
        }

        $catalog = $this->allowedModelCatalog();
        $lookup = strtolower(ltrim($model, '\\'));
        if (!isset($catalog[$lookup])) {
            throw ValidationException::withMessages([
                'model' => "Model [{$model}] is not allowlisted.",
            ]);
        }

        $class = $catalog[$lookup];
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            throw ValidationException::withMessages([
                'model' => "Model class [{$class}] is not available.",
            ]);
        }

        $this->resolvePublicFields($class);

        return $class;
    }

    /**
     * @return array<int, string>
     */
    protected function resolvePublicFields(string $modelClass): array
    {
        $alias = class_basename($modelClass);
        $allowlists = ModelFieldPolicy::fieldAllowlists();
        $fields = $allowlists[$alias] ?? null;

        if (!is_array($fields) || $fields === []) {
            throw ValidationException::withMessages([
                'model' => "Field allowlist for model [{$alias}] is not defined.",
            ]);
        }

        $sensitive = array_fill_keys(
            array_map(
                static fn(string $field): string => strtolower(trim($field)),
                ModelFieldPolicy::sensitiveFields()
            ),
            true
        );

        $resolved = [];
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                continue;
            }

            if (isset($sensitive[strtolower($field)])) {
                continue;
            }

            $resolved[] = $field;
        }

        $resolved = array_values(array_unique($resolved));
        if ($resolved === []) {
            throw ValidationException::withMessages([
                'model' => "Resolved allowlisted fields for model [{$alias}] are empty.",
            ]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function applyFilters(Builder $query, string $modelClass, ?array $filters): void
    {
        if ($filters === null || $filters === []) {
            return;
        }

        $where = $filters['where'] ?? [];
        if (!is_array($where)) {
            throw ValidationException::withMessages([
                'filters' => 'filters.where must be an array.',
            ]);
        }

        foreach ($where as $index => $condition) {
            if (!is_array($condition)) {
                throw ValidationException::withMessages([
                    'filters' => "filters.where[{$index}] must be an object.",
                ]);
            }

            $field = trim((string)($condition['field'] ?? ''));
            $op = trim((string)($condition['op'] ?? ''));
            $value = $condition['value'] ?? null;

            $this->assertFieldAllowed($modelClass, $field);

            if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                throw ValidationException::withMessages([
                    'filters' => "Unsupported operator [{$op}].",
                ]);
            }

            $this->applyFilterCondition($query, $field, $op, $value);
        }
    }

    protected function applySort(Builder $query, string $modelClass, ?string $orderBy, ?string $orderDir): void
    {
        $fields = $this->resolvePublicFields($modelClass);

        if ($orderBy === null) {
            $fallback = in_array('id', $fields, true) ? 'id' : $fields[0];
            $query->orderBy($fallback, 'asc');

            return;
        }

        $field = trim($orderBy);
        $this->assertFieldAllowed($modelClass, $field);

        $direction = strtolower(trim((string)($orderDir ?? 'asc')));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages([
                'order_dir' => "Invalid order_dir [{$orderDir}].",
            ]);
        }

        $query->orderBy($field, $direction);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: int}
     */
    protected function resolveLimitOffset(array $validated): array
    {
        $limit = (int)$validated['limit'];
        $offset = (int)($validated['offset'] ?? 0);
        $maxLimit = max(1, (int)config('cms.settings.eMCP.limits.max_result_items', 100));
        $maxOffset = max(0, (int)config('cms.settings.eMCP.domain.models.max_offset', 5000));

        if ($limit < 1 || $limit > $maxLimit) {
            throw ValidationException::withMessages([
                'limit' => "limit must be between 1 and {$maxLimit}.",
            ]);
        }

        if ($offset < 0 || $offset > $maxOffset) {
            throw ValidationException::withMessages([
                'offset' => "offset must be between 0 and {$maxOffset}.",
            ]);
        }

        return [$limit, $offset];
    }

    /**
     * @param  iterable<int, Model>  $rows
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function projectItems(iterable $rows, array $fields): array
    {
        return $this->modelMapper()->mapMany($rows, $fields);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected function projectItem(Model $row, array $fields): array
    {
        return $this->modelMapper()->map($row, $fields);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function respondList(string $modelClass, array $items, int $limit, int $offset): ResponseFactory
    {
        return Response::structured(
            (new ListToolResponse(
                $items,
                $limit,
                $offset,
                count($items),
                (string)config('cms.settings.eMCP.toolset_version', '1.0'),
                class_basename($modelClass)
            ))->toArray()
        );
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    protected function respondItem(string $modelClass, ?array $item): ResponseFactory
    {
        return Response::structured(
            (new ItemToolResponse(
                $item,
                (string)config('cms.settings.eMCP.toolset_version', '1.0'),
                class_basename($modelClass)
            ))->toArray()
        );
    }

    protected function modelMapper(): ModelRecordMapper
    {
        if ($this->modelRecordMapper === null) {
            $this->modelRecordMapper = new ModelRecordMapper();
        }

        return $this->modelRecordMapper;
    }

    private function assertFieldAllowed(string $modelClass, string $field): void
    {
        $field = trim($field);
        if ($field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            throw ValidationException::withMessages([
                'filters' => "Invalid field [{$field}].",
            ]);
        }

        $allowed = $this->resolvePublicFields($modelClass);
        if (!in_array($field, $allowed, true)) {
            throw ValidationException::withMessages([
                'filters' => "Field [{$field}] is not allowlisted for model [" . class_basename($modelClass) . '].',
            ]);
        }
    }

    private function applyFilterCondition(Builder $query, string $field, string $op, mixed $value): void
    {
        if ($op === 'null') {
            $query->whereNull($field);

            return;
        }

        if ($op === '!null') {
            $query->whereNotNull($field);

            return;
        }

        if (in_array($op, ['in', 'not_in'], true)) {
            $values = is_array($value) ? $value : explode(',', trim((string)$value));
            $prepared = [];
            foreach ($values as $item) {
                if (is_array($item) || is_object($item)) {
                    throw ValidationException::withMessages([
                        'filters' => "Operator [{$op}] expects scalar values.",
                    ]);
                }

                $normalized = trim((string)$item);
                if ($normalized !== '') {
                    $prepared[] = $normalized;
                }
            }

            if ($prepared === []) {
                throw ValidationException::withMessages([
                    'filters' => "Operator [{$op}] requires at least one value.",
                ]);
            }

            if ($op === 'in') {
                $query->whereIn($field, $prepared);

                return;
            }

            $query->whereNotIn($field, $prepared);

            return;
        }

        if (is_array($value) || is_object($value) || $value === null) {
            throw ValidationException::withMessages([
                'filters' => "Operator [{$op}] requires scalar value.",
            ]);
        }

        $prepared = (string)$value;
        if (str_contains($op, 'like')) {
            if ($prepared === '') {
                throw ValidationException::withMessages([
                    'filters' => "Operator [{$op}] requires non-empty value.",
                ]);
            }

            $likeValue = match ($op) {
                'like-l' => '%' . $prepared,
                'like-r' => $prepared . '%',
                default => '%' . $prepared . '%',
            };

            $query->where($field, 'like', $likeValue);

            return;
        }

        $normalizedOperator = $op === '!=' ? '<>' : $op;
        $query->where($field, $normalizedOperator, $value);
    }

    /**
     * @return array<string, string>
     */
    private function allowedModelCatalog(): array
    {
        $configured = config('cms.settings.eMCP.domain.models.allow', []);
        if (!is_array($configured)) {
            return [];
        }

        $catalog = [];
        foreach ($configured as $entry) {
            $entry = trim((string)$entry);
            if ($entry === '') {
                continue;
            }

            $class = Str::contains($entry, '\\')
                ? ltrim($entry, '\\')
                : 'EvolutionCMS\\Models\\' . $entry;

            $alias = class_basename($class);

            $catalog[strtolower($alias)] = $class;
            $catalog[strtolower($class)] = $class;
        }

        return $catalog;
    }
}
