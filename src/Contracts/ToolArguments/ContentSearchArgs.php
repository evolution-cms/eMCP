<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class ContentSearchArgs
{
    /**
     * @param  array<int, string>  $withTvEntries
     * @param  array<int, string>  $withTvNames
     * @param  array<int, array<string, mixed>>  $tvFilters
     * @param  array<int, array<string, mixed>>  $tvOrder
     * @param  array<string, mixed>|null  $tagsData
     */
    public function __construct(
        public PagingArgs $paging,
        public ?int $parent,
        public ?bool $published,
        public ?bool $deleted,
        public ?int $template,
        public ?bool $hidemenu,
        public ?int $depth,
        public array $withTvEntries,
        public array $withTvNames,
        public array $tvFilters,
        public array $tvOrder,
        public ?array $tagsData,
        public ?string $orderBy,
        public ?string $orderDir,
        public ?string $orderByDate
    ) {
    }
}
