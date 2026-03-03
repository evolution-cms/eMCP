#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array{input:string,output:string}
 */
function parseOptions(array $argv): array
{
    $input = 'build/benchmarks/simulation-report.json';
    $output = 'build/benchmarks/leaderboard.md';

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');
        if ($key === '--input' && $value !== '') {
            $input = $value;
        } elseif ($key === '--output' && $value !== '') {
            $output = $value;
        }
    }

    return ['input' => $input, 'output' => $output];
}

/** @return array<string,mixed> */
function loadReport(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Benchmark report not found: {$path}");
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("Unable to read benchmark report: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Benchmark report is invalid JSON: {$path}");
    }

    $leaderboard = $decoded['leaderboard'] ?? null;
    if (!is_array($leaderboard) || $leaderboard === []) {
        throw new RuntimeException('Benchmark report missing leaderboard entries.');
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $report
 */
function renderMarkdown(array $report): string
{
    $suite = (string)($report['suite'] ?? 'benchmark');
    $seed = (int)($report['seed'] ?? 0);
    $generated = (string)($report['generated_at_utc'] ?? '');

    $lines = [];
    $lines[] = '# eMCP Simulation Leaderboard';
    $lines[] = '';
    $lines[] = '- Suite: `' . $suite . '`';
    $lines[] = '- Seed: `' . $seed . '`';
    $lines[] = '- Generated (UTC): `' . $generated . '`';
    $lines[] = '';
    $lines[] = '| Rank | Strategy | Avg Score | Success % | Avg Latency (ms) |';
    $lines[] = '| --- | --- | ---: | ---: | ---: |';

    $leaderboard = $report['leaderboard'];
    foreach ($leaderboard as $row) {
        if (!is_array($row)) {
            continue;
        }

        $lines[] = sprintf(
            '| %d | %s | %.4f | %.2f | %d |',
            (int)($row['rank'] ?? 0),
            (string)($row['strategy'] ?? 'unknown'),
            (float)($row['avg_score'] ?? 0.0),
            (float)($row['success_rate_percent'] ?? 0.0),
            (int)($row['avg_latency_ms'] ?? 0)
        );
    }

    $comparison = $report['comparison'] ?? [];
    $delta = (float)($comparison['planner_minus_baseline'] ?? 0.0);
    $pass = (bool)($comparison['pass'] ?? false);

    $lines[] = '';
    $lines[] = '## Comparison';
    $lines[] = '';
    $lines[] = sprintf('- Planner - Baseline: `%.4f`', $delta);
    $lines[] = '- Status: `' . ($pass ? 'PASS' : 'FAIL') . '`';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));
    $report = loadReport($options['input']);

    $markdown = renderMarkdown($report);
    $outputDir = dirname($options['output']);
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        throw new RuntimeException("Unable to create leaderboard output directory: {$outputDir}");
    }

    if (file_put_contents($options['output'], $markdown) === false) {
        throw new RuntimeException("Unable to write leaderboard markdown: {$options['output']}");
    }

    fwrite(STDOUT, '[benchmark] leaderboard=' . $options['output'] . PHP_EOL);

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, '[benchmark][FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
