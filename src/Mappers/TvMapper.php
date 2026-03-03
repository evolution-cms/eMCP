<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Mappers;

use EvolutionCMS\Models\SiteContent;

final class TvMapper
{
    /**
     * @param  array<int, string>  $tvNames
     * @return array<string, mixed>
     */
    public function map(SiteContent $row, array $tvNames): array
    {
        $payload = [];

        foreach ($tvNames as $tvName) {
            $payload[$tvName] = $row->getAttribute($tvName);
        }

        return $payload;
    }
}
