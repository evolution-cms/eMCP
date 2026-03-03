<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolResponses;

final readonly class ListToolResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
        public int $count,
        public string $toolsetVersion,
        public ?string $model = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = [
            'limit' => $this->limit,
            'offset' => $this->offset,
            'count' => $this->count,
            'toolsetVersion' => $this->toolsetVersion,
        ];

        if ($this->model !== null) {
            $meta['model'] = $this->model;
        }

        return [
            'items' => $this->items,
            'meta' => $meta,
        ];
    }
}
