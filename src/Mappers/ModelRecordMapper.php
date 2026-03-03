<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Mappers;

use Illuminate\Database\Eloquent\Model;

final class ModelRecordMapper
{
    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function map(Model $row, array $fields): array
    {
        $item = [];

        foreach ($fields as $field) {
            $item[$field] = $row->getAttribute($field);
        }

        return $item;
    }

    /**
     * @param  iterable<int, Model>  $rows
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function mapMany(iterable $rows, array $fields): array
    {
        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->map($row, $fields);
        }

        return $items;
    }
}
