<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class ContentNodeRangeArgs
{
    /**
     * @param  array<int, string>  $withTvEntries
     * @param  array<int, string>  $withTvNames
     */
    public function __construct(
        public int $id,
        public int $from,
        public ?int $to,
        public PagingArgs $paging,
        public array $withTvEntries,
        public array $withTvNames
    ) {
    }
}
