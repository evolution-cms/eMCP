<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class ModelListArgs
{
    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function __construct(
        public string $model,
        public PagingArgs $paging,
        public ?array $filters,
        public ?string $orderBy,
        public ?string $orderDir
    ) {
    }
}
