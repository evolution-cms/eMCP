<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{exit:int,output:string} */
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

    $output = [];
    $exit = 1;
    @exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => trim(implode("\n", $output))];
}

/** @return array<string,mixed> */
function decodeFileJson(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("Unable to read JSON file: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON file: {$path}");
    }

    return $decoded;
}

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/benchmark/run.php';
assertTrue(is_file($script), 'Missing benchmark run script');

$tmpDir = sys_get_temp_dir() . '/emcp-benchmark-' . bin2hex(random_bytes(4));
assertTrue(mkdir($tmpDir, 0777, true), 'Unable to create benchmark temp dir');

$reportA = $tmpDir . '/report-a.json';
$reportB = $tmpDir . '/report-b.json';

$commandA = sprintf('php %s --output=%s --seed=20260303 --pass-threshold=5', escapeshellarg($script), escapeshellarg($reportA));
$runA = runCommand($commandA, $root);
assertTrue($runA['exit'] === 0, 'Benchmark run A failed: ' . $runA['output']);
assertTrue(is_file($reportA), 'Benchmark report A was not created');

$commandB = sprintf('php %s --output=%s --seed=20260303 --pass-threshold=5', escapeshellarg($script), escapeshellarg($reportB));
$runB = runCommand($commandB, $root);
assertTrue($runB['exit'] === 0, 'Benchmark run B failed: ' . $runB['output']);
assertTrue(is_file($reportB), 'Benchmark report B was not created');

$jsonA = decodeFileJson($reportA);
$jsonB = decodeFileJson($reportB);

assertTrue(($jsonA['suite'] ?? null) === 'emcp-simulation-v1', 'Unexpected benchmark suite id');
assertTrue(($jsonA['seed'] ?? null) === 20260303, 'Unexpected benchmark seed');
assertTrue(isset($jsonA['fixtures_hash']) && is_string($jsonA['fixtures_hash']), 'Benchmark report must include fixtures_hash');

$strategies = $jsonA['strategies'] ?? null;
assertTrue(is_array($strategies), 'Benchmark report missing strategies');
assertTrue(count($strategies) === 2, 'Benchmark must include baseline and planner strategies');

$leaderboard = $jsonA['leaderboard'] ?? null;
assertTrue(is_array($leaderboard) && count($leaderboard) >= 2, 'Benchmark leaderboard is missing');
assertTrue(($leaderboard[0]['strategy'] ?? null) === 'planner', 'Planner must rank first in canonical benchmark');
assertTrue((bool)($jsonA['comparison']['pass'] ?? false) === true, 'Benchmark comparison must pass threshold');

unset($jsonA['generated_at_utc'], $jsonB['generated_at_utc']);
assertTrue($jsonA === $jsonB, 'Benchmark runs with same seed must be reproducible');

@unlink($reportA);
@unlink($reportB);
@rmdir($tmpDir);

echo "Simulation benchmark suite checks passed.\n";
