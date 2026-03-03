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

$root = dirname(__DIR__, 2);
$runScript = $root . '/scripts/benchmark/run.php';
$leaderboardScript = $root . '/scripts/benchmark/leaderboard.php';

assertTrue(is_file($runScript), 'Missing benchmark run script');
assertTrue(is_file($leaderboardScript), 'Missing benchmark leaderboard script');

$tmpDir = sys_get_temp_dir() . '/emcp-benchmark-leaderboard-' . bin2hex(random_bytes(4));
assertTrue(mkdir($tmpDir, 0777, true), 'Unable to create benchmark leaderboard temp dir');

$report = $tmpDir . '/report.json';
$leaderboard = $tmpDir . '/leaderboard.md';

$run = runCommand(
    sprintf('php %s --output=%s --seed=20260303 --pass-threshold=5', escapeshellarg($runScript), escapeshellarg($report)),
    $root
);
assertTrue($run['exit'] === 0, 'Benchmark run failed: ' . $run['output']);

$render = runCommand(
    sprintf('php %s --input=%s --output=%s', escapeshellarg($leaderboardScript), escapeshellarg($report), escapeshellarg($leaderboard)),
    $root
);
assertTrue($render['exit'] === 0, 'Leaderboard render failed: ' . $render['output']);
assertTrue(is_file($leaderboard), 'Leaderboard file was not created');

$markdown = file_get_contents($leaderboard);
assertTrue(is_string($markdown), 'Unable to read leaderboard markdown');
assertTrue(str_contains($markdown, '| Rank | Strategy | Avg Score |'), 'Leaderboard must contain ranking table header');
assertTrue(str_contains($markdown, '| 1 | planner |'), 'Planner must be ranked #1 in leaderboard output');
assertTrue(str_contains($markdown, '| 2 | baseline |'), 'Baseline must be ranked #2 in leaderboard output');
assertTrue(str_contains($markdown, 'Status: `PASS`'), 'Leaderboard must include PASS/FAIL comparison status');

@unlink($report);
@unlink($leaderboard);
@rmdir($tmpDir);

echo "Simulation leaderboard artifact checks passed.\n";
