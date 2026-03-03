<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolResponses;

final readonly class ItemToolResponse
{
    /**
     * @param  array<string, mixed>|null  $item
     */
    public function __construct(
        public ?array $item,
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
            'toolsetVersion' => $this->toolsetVersion,
        ];

        if ($this->model !== null) {
            $meta['model'] = $this->model;
        }

        return [
            'item' => $this->item,
            'meta' => $meta,
        ];
    }
}
