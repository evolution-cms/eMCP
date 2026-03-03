<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "[migration][FAIL] {$message}\n");
    exit(1);
}

function info(string $message): void
{
    fwrite(STDOUT, "[migration] {$message}\n");
}

/**
 * @return array{exit:int,output:string}
 */
function runCommand(string $command, string $cwd): array
{
    if (function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($command, $descriptors, $pipes, $cwd);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            return [
                'exit' => is_int($exit) ? $exit : 1,
                'output' => trim((string)$stdout . (string)$stderr),
            ];
        }
    }

    $outputLines = [];
    $exit = 1;
    @exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $outputLines, $exit);

    return ['exit' => $exit, 'output' => trim(implode("\n", $outputLines))];
}

$enabled = getenv('EMCP_MIGRATION_CHECK_ENABLED');
if ($enabled !== '1') {
    info('Skipped (set EMCP_MIGRATION_CHECK_ENABLED=1).');
    exit(0);
}

$coreDir = trim((string)getenv('EMCP_CORE_DIR'));
if ($coreDir === '') {
    fail('EMCP_CORE_DIR is required when migration check is enabled.');
}

if (!is_dir($coreDir) || !is_file(rtrim($coreDir, '/\\') . '/artisan')) {
    fail('EMCP_CORE_DIR must point to a valid Evo core directory containing artisan.');
}

$coreDir = rtrim($coreDir, '/\\');
$migrationPath = 'vendor/evolution-cms/emcp/database/migrations';

$steps = [
    'migrate-up' => 'php artisan migrate --path=' . escapeshellarg($migrationPath) . ' --force',
    'migrate-down' => 'php artisan migrate:rollback --path=' . escapeshellarg($migrationPath) . ' --force',
    'migrate-up-again' => 'php artisan migrate --path=' . escapeshellarg($migrationPath) . ' --force',
];

foreach ($steps as $label => $command) {
    $result = runCommand($command, $coreDir);
    if ($result['exit'] !== 0) {
        fail("{$label} failed with exit {$result['exit']}: {$result['output']}");
    }

    info("{$label} passed.");
}

info('Migration cycle runtime checks passed.');
