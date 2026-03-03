<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class ContentRootTreeArgs
{
    /**
     * @param  array<int, string>  $withTvEntries
     * @param  array<int, string>  $withTvNames
     */
    public function __construct(
        public int $depth,
        public PagingArgs $paging,
        public array $withTvEntries,
        public array $withTvNames
    ) {
    }
}
