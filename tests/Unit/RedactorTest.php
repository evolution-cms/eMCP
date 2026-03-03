<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Support/Redactor.php';

use EvolutionCMS\eMCP\Support\Redactor;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$redactor = new Redactor(['token', 'password', 'secret']);

$payload = [
    'token' => 'abc',
    'user' => [
        'name' => 'dev',
        'password' => 'pass',
        'nested' => [
            'secret' => 's',
            'ok' => true,
        ],
    ],
];

$redacted = $redactor->redact($payload);

assertTrue(($redacted['token'] ?? null) === '[REDACTED]', 'Top-level token must be redacted');
assertTrue(($redacted['user']['password'] ?? null) === '[REDACTED]', 'Nested password must be redacted');
assertTrue(($redacted['user']['nested']['secret'] ?? null) === '[REDACTED]', 'Deep secret must be redacted');
assertTrue(($redacted['user']['name'] ?? null) === 'dev', 'Non-sensitive field must remain unchanged');

$scalar = $redactor->redact('plain');
assertTrue($scalar === 'plain', 'Scalar payload must pass through unchanged');

echo "Redactor unit checks passed.\n";
