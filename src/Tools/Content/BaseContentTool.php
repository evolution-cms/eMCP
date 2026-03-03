<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Tools\Content;

use EvolutionCMS\eMCP\Contracts\ToolResponses\ItemToolResponse;
use EvolutionCMS\eMCP\Contracts\ToolResponses\ListToolResponse;
use EvolutionCMS\eMCP\Mappers\SiteContentMapper;
use EvolutionCMS\Models\SiteContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class BaseContentTool extends Tool
{
    private ?SiteContentMapper $siteContentMapper = null;

    /**
     * @var array<int, string>
     */
    protected const ALLOWED_SORT_FIELDS = ['id', 'pagetitle', 'menuindex', 'createdon', 'pub_date'];

    /**
     * @var array<int, string>
     */
    protected const ALLOWED_TV_OPERATORS = [
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

    /**
     * @var array<int, string>
     */
    protected const ALLOWED_TV_CASTS = ['UNSIGNED', 'SIGNED'];

    /**
     * @var array<int, string>
     */
    protected const PUBLIC_FIELDS = [
        'id',
        'parent',
        'pagetitle',
        'longtitle',
        'description',
        'alias',
        'published',
        'deleted',
        'hidemenu',
        'menuindex',
        'template',
        'createdon',
        'editedon',
        'pub_date',
        'unpub_date',
        'isfolder',
        'type',
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
        // Read-only tools share manager/API policy in middleware; no per-tool override by default.
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

    protected function resolveMaxLimit(): int
    {
        $limitA = max(1, (int)config('cms.settings.eMCP.limits.max_result_items', 100));
        $limitB = max(1, (int)config('cms.settings.eMCP.domain.content.max_limit', $limitA));

        return min($limitA, $limitB);
    }

    protected function resolveMaxOffset(): int
    {
        return max(0, (int)config('cms.settings.eMCP.domain.content.max_offset', 5000));
    }

    protected function resolveMaxDepth(): int
    {
        return max(1, (int)config('cms.settings.eMCP.domain.content.max_depth', 6));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: int}
     */
    protected function resolveLimitOffset(array $validated): array
    {
        $limit = (int)$validated['limit'];
        $offset = (int)($validated['offset'] ?? 0);

        $maxLimit = $this->resolveMaxLimit();
        $maxOffset = $this->resolveMaxOffset();

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

    protected function resolveDepth(?int $depth, int $default = 1): int
    {
        $depth = $depth ?? $default;
        $maxDepth = $this->resolveMaxDepth();

        if ($depth < 1 || $depth > $maxDepth) {
            throw ValidationException::withMessages([
                'depth' => "depth must be between 1 and {$maxDepth}.",
            ]);
        }

        return $depth;
    }

    /**
     * @param  array<int, mixed>|null  $withTvs
     * @return array{entries: array<int, string>, names: array<int, string>}
     */
    protected function normalizeWithTvs(?array $withTvs): array
    {
        if ($withTvs === null || $withTvs === []) {
            return ['entries' => [], 'names' => []];
        }

        $entries = [];
        $names = [];

        foreach ($withTvs as $rawValue) {
            $value = trim((string)$rawValue);
            if ($value === '') {
                continue;
            }

            if (!preg_match('~^[A-Za-z0-9_\\-]+(?::d)?$~', $value)) {
                throw ValidationException::withMessages([
                    'with_tvs' => "Invalid with_tvs entry [{$value}].",
                ]);
            }

            $entries[] = $value;
            $names[] = strstr($value, ':', true) ?: $value;
        }

        return [
            'entries' => array_values(array_unique($entries)),
            'names' => array_values(array_unique($names)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     */
    protected function buildTvFilterString(?array $filters): string
    {
        if ($filters === null || $filters === []) {
            return '';
        }

        $chunks = [];

        foreach ($filters as $filter) {
            $tv = trim((string)($filter['tv'] ?? ''));
            $op = trim((string)($filter['op'] ?? ''));
            $cast = strtoupper(trim((string)($filter['cast'] ?? '')));
            $useDefault = (bool)($filter['use_default'] ?? false);

            if (!preg_match('~^[A-Za-z0-9_\\-]+$~', $tv)) {
                throw ValidationException::withMessages(['tv_filters' => "Invalid tv name [{$tv}]."]);
            }

            if (!in_array($op, self::ALLOWED_TV_OPERATORS, true)) {
                throw ValidationException::withMessages(['tv_filters' => "Invalid tv operator [{$op}]."]);
            }

            if ($cast !== '' && !$this->isValidCast($cast)) {
                throw ValidationException::withMessages(['tv_filters' => "Invalid tv cast [{$cast}]."]);
            }

            $value = $this->normalizeTvFilterValue($op, $cast, $filter['value'] ?? null);
            $type = $useDefault ? 'tvd' : 'tv';
            $chunks[] = implode(':', [$type, $tv, $op, $value, $cast]);
        }

        return implode(';', $chunks);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $orders
     */
    protected function buildTvOrderString(?array $orders): string
    {
        if ($orders === null || $orders === []) {
            return '';
        }

        $chunks = [];

        foreach ($orders as $order) {
            $tv = trim((string)($order['tv'] ?? ''));
            $dir = strtolower(trim((string)($order['dir'] ?? 'asc')));
            $cast = strtoupper(trim((string)($order['cast'] ?? '')));
            $useDefault = (bool)($order['use_default'] ?? false);

            if (!preg_match('~^[A-Za-z0-9_\\-]+$~', $tv)) {
                throw ValidationException::withMessages(['tv_order' => "Invalid tv name [{$tv}]."]);
            }

            if (!in_array($dir, ['asc', 'desc'], true)) {
                throw ValidationException::withMessages(['tv_order' => "Invalid tv sort direction [{$dir}]."]);
            }

            if ($cast !== '' && !$this->isValidCast($cast)) {
                throw ValidationException::withMessages(['tv_order' => "Invalid tv cast [{$cast}]."]);
            }

            // Evo scopeTvOrderBy splits items by comma, so DECIMAL(p,s) cannot be encoded safely in tv_order string.
            if ($cast !== '' && str_starts_with($cast, 'DECIMAL(')) {
                throw ValidationException::withMessages([
                    'tv_order' => 'DECIMAL cast is not supported for tv_order; use SIGNED or UNSIGNED.',
                ]);
            }

            $tvName = $useDefault ? ($tv . ':d') : $tv;
            $chunk = $tvName . ' ' . $dir;
            if ($cast !== '') {
                $chunk .= ' ' . $cast;
            }

            $chunks[] = $chunk;
        }

        return implode(',', $chunks);
    }

    /**
     * @param  array<string, mixed>|null  $tagsData
     */
    protected function buildTagsDataString(?array $tagsData): string
    {
        if ($tagsData === null || $tagsData === []) {
            return '';
        }

        $tvId = (int)($tagsData['tv_id'] ?? 0);
        $tags = $tagsData['tags'] ?? [];

        if ($tvId < 1 || !is_array($tags) || $tags === []) {
            throw ValidationException::withMessages(['tags_data' => 'tags_data must contain tv_id and non-empty tags array.']);
        }

        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '' || str_contains($tag, ':') || str_contains($tag, ';')) {
                throw ValidationException::withMessages(['tags_data' => "Invalid tag value [{$tag}]."]);
            }
            $normalized[] = $tag;
        }

        return $tvId . ':' . implode(',', array_values(array_unique($normalized)));
    }

    protected function applySort(Builder $query, ?string $orderBy, ?string $orderDir, ?string $orderByDate): void
    {
        if ($orderByDate !== null) {
            $direction = strtolower(trim($orderByDate));
            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw ValidationException::withMessages(['order_by_date' => "Invalid order_by_date [{$orderByDate}]."]);
            }

            $query->orderByDate($direction);

            return;
        }

        if ($orderBy === null) {
            $query->orderBy('id', 'asc');

            return;
        }

        $field = trim($orderBy);
        if (!in_array($field, self::ALLOWED_SORT_FIELDS, true)) {
            throw ValidationException::withMessages([
                'order_by' => 'order_by must be one of: ' . implode(', ', self::ALLOWED_SORT_FIELDS) . '.',
            ]);
        }

        $direction = strtolower(trim((string)($orderDir ?? 'asc')));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['order_dir' => "Invalid order_dir [{$orderDir}]."]);
        }

        $query->orderBy($field, $direction);
    }

    /**
     * @param  iterable<int, SiteContent>  $rows
     * @param  array<int, string>  $tvNames
     * @return array<int, array<string, mixed>>
     */
    protected function projectItems(iterable $rows, array $tvNames = []): array
    {
        return $this->contentMapper()->mapMany($rows, self::PUBLIC_FIELDS, $tvNames);
    }

    /**
     * @param  array<int, string>  $tvNames
     * @return array<string, mixed>
     */
    protected function projectItem(SiteContent $row, array $tvNames = []): array
    {
        return $this->contentMapper()->map($row, self::PUBLIC_FIELDS, $tvNames);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function respondList(array $items, int $limit, int $offset): ResponseFactory
    {
        return Response::structured(
            (new ListToolResponse(
                $items,
                $limit,
                $offset,
                count($items),
                (string)config('cms.settings.eMCP.toolset_version', '1.0')
            ))->toArray()
        );
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    protected function respondItem(?array $item): ResponseFactory
    {
        return Response::structured(
            (new ItemToolResponse(
                $item,
                (string)config('cms.settings.eMCP.toolset_version', '1.0')
            ))->toArray()
        );
    }

    protected function contentMapper(): SiteContentMapper
    {
        if ($this->siteContentMapper === null) {
            $this->siteContentMapper = new SiteContentMapper();
        }

        return $this->siteContentMapper;
    }

    protected function booleanOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? null;
    }

    private function isValidCast(string $cast): bool
    {
        if (in_array($cast, self::ALLOWED_TV_CASTS, true)) {
            return true;
        }

        return (bool)preg_match('~^DECIMAL\\(\\d{1,2},\\d{1,2}\\)$~', $cast);
    }

    private function normalizeTvFilterValue(string $op, string $cast, mixed $rawValue): string
    {
        if (in_array($op, ['null', '!null'], true)) {
            return '';
        }

        if (in_array($op, ['in', 'not_in'], true)) {
            $values = is_array($rawValue) ? $rawValue : explode(',', trim((string)$rawValue));
            $normalized = [];
            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value === '' || str_contains($value, ':') || str_contains($value, ';')) {
                    throw ValidationException::withMessages(['tv_filters' => "Invalid value for operator [{$op}]."]);
                }
                $normalized[] = $value;
            }

            if ($normalized === []) {
                throw ValidationException::withMessages(['tv_filters' => "Operator [{$op}] requires at least one value."]);
            }

            return implode(',', $normalized);
        }

        $value = trim((string)$rawValue);
        if ($value === '') {
            throw ValidationException::withMessages(['tv_filters' => "Operator [{$op}] requires a value."]);
        }

        if (str_contains($value, ':') || str_contains($value, ';')) {
            throw ValidationException::withMessages(['tv_filters' => "Invalid value [{$value}]."]);
        }

        if ($cast !== '') {
            if (!in_array($op, ['=', '!=', '>', '>=', '<', '<='], true)) {
                throw ValidationException::withMessages(['tv_filters' => 'Numeric casts are allowed only for comparison operators.']);
            }

            if (!preg_match('~^-?\\d+(?:\\.\\d+)?$~', $value)) {
                throw ValidationException::withMessages(['tv_filters' => "Cast [{$cast}] requires a numeric value."]);
            }
        }

        return $value;
    }
}
