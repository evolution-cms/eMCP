<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Contracts\ToolArguments;

final readonly class ModelGetArgs
{
    public function __construct(
        public string $model,
        public int $id
    ) {
    }
}
