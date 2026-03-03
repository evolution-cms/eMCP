<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/ci.yml';

$workflow = file_get_contents($workflowPath);
assertTrue(is_string($workflow), 'Unable to read CI workflow file.');

$requiredSnippets = [
    'checks:',
    'runtime-integration:',
    'demo-runtime-proof:',
    'migration-matrix:',
    'EMCP_STASK_LIFECYCLE_CHECK',
    'EMCP_STASK_EXPECT_EXTERNAL_WORKER',
    'benchmark-artifacts',
    'composer run test:integration:clean-install',
    'scripts/migration_matrix_check.sh sqlite',
    'scripts/migration_matrix_check.sh mysql',
    'scripts/migration_matrix_check.sh pgsql',
    'demo/clean-install.log',
    '- sqlite',
    '- mysql',
    '- pgsql',
];

foreach ($requiredSnippets as $snippet) {
    assertTrue(str_contains($workflow, $snippet), "CI workflow must include snippet: {$snippet}");
}

echo "CI workflow coverage checks passed.\n";
