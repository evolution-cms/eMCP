<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Support/ModelFieldPolicy.php';

use EvolutionCMS\eMCP\Support\ModelFieldPolicy;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$allowlists = ModelFieldPolicy::fieldAllowlists();
$sensitive = array_map(
    static fn(string $field): string => strtolower(trim($field)),
    ModelFieldPolicy::sensitiveFields()
);
$sensitiveLookup = array_fill_keys($sensitive, true);

foreach ($allowlists as $model => $fields) {
    if (!is_array($fields)) {
        continue;
    }

    foreach ($fields as $field) {
        $field = strtolower(trim((string)$field));
        assertTrue(
            !isset($sensitiveLookup[$field]),
            "Sensitive field [{$field}] leaked into allowlist for model [{$model}]"
        );
    }
}

echo "Model field leakage checks passed.\n";
