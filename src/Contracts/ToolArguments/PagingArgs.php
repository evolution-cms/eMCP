<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class PagingArgs
{
    public function __construct(
        public int $limit,
        public int $offset
    ) {
    }
}
