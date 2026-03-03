<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Mappers;

use EvolutionCMS\Models\SiteContent;

final class SiteContentMapper
{
    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $tvNames
     * @return array<string, mixed>
     */
    public function map(SiteContent $row, array $fields, array $tvNames = []): array
    {
        $item = [];

        foreach ($fields as $field) {
            $item[$field] = $row->getAttribute($field);
        }

        if ($tvNames !== []) {
            $item['tvs'] = (new TvMapper())->map($row, $tvNames);
        }

        return $item;
    }

    /**
     * @param  iterable<int, SiteContent>  $rows
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $tvNames
     * @return array<int, array<string, mixed>>
     */
    public function mapMany(iterable $rows, array $fields, array $tvNames = []): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->map($row, $fields, $tvNames);
        }

        return $items;
    }
}
