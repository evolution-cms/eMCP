#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array{fixtures:string,output:string,seed:int,pass_threshold:float}
 */
function parseOptions(array $argv): array
{
    $fixtures = 'tests/Fixtures/simulation/episodes.json';
    $output = 'build/benchmarks/simulation-report.json';
    $seed = 20260303;
    $threshold = 5.0;

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');

        if ($key === '--fixtures' && $value !== '') {
            $fixtures = $value;
        } elseif ($key === '--output' && $value !== '') {
            $output = $value;
        } elseif ($key === '--seed' && is_numeric($value)) {
            $seed = (int)$value;
        } elseif ($key === '--pass-threshold' && is_numeric($value)) {
            $threshold = (float)$value;
        }
    }

    return [
        'fixtures' => $fixtures,
        'output' => $output,
        'seed' => $seed,
        'pass_threshold' => $threshold,
    ];
}

/**
 * @return list<array{id:string,scenario:string,metrics:array{baseline:array<string,int>,planner:array<string,int>}}>
 */
function loadEpisodes(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Benchmark fixtures not found: {$path}");
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("Unable to read benchmark fixtures: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Benchmark fixtures must be a JSON array: {$path}");
    }

    $episodes = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        $scenario = trim((string)($item['scenario'] ?? ''));
        $metrics = $item['metrics'] ?? null;

        if ($id === '' || $scenario === '' || !is_array($metrics)) {
            continue;
        }

        $baseline = $metrics['baseline'] ?? null;
        $planner = $metrics['planner'] ?? null;
        if (!is_array($baseline) || !is_array($planner)) {
            continue;
        }

        $episodes[] = [
            'id' => $id,
            'scenario' => $scenario,
            'metrics' => [
                'baseline' => [
                    'success' => (int)($baseline['success'] ?? 0),
                    'policy_violations' => (int)($baseline['policy_violations'] ?? 0),
                    'latency_ms' => (int)($baseline['latency_ms'] ?? 0),
                    'retries' => (int)($baseline['retries'] ?? 0),
                ],
                'planner' => [
                    'success' => (int)($planner['success'] ?? 0),
                    'policy_violations' => (int)($planner['policy_violations'] ?? 0),
                    'latency_ms' => (int)($planner['latency_ms'] ?? 0),
                    'retries' => (int)($planner['retries'] ?? 0),
                ],
            ],
        ];
    }

    if ($episodes === []) {
        throw new RuntimeException('No valid simulation episodes found in fixtures.');
    }

    return $episodes;
}

/**
 * @param array{success:int,policy_violations:int,latency_ms:int,retries:int} $metrics
 */
function scoreEpisode(array $metrics, string $strategy, int $seed, string $episodeId): float
{
    $base = ($metrics['success'] * 100.0)
        - ($metrics['policy_violations'] * 25.0)
        - ($metrics['retries'] * 8.0)
        - ($metrics['latency_ms'] * 0.02);

    $hash = sha1($seed . '|' . $strategy . '|' . $episodeId);
    $jitter = ((hexdec(substr($hash, 0, 2)) % 5) - 2) * 0.3;

    return round($base + $jitter, 4);
}

/**
 * @param list<array{id:string,scenario:string,metrics:array{baseline:array<string,int>,planner:array<string,int>}}> $episodes
 * @return array<string, mixed>
 */
function buildReport(array $episodes, int $seed, float $threshold): array
{
    $strategies = ['baseline', 'planner'];
    $strategyRows = [];

    foreach ($strategies as $strategy) {
        $scoreSum = 0.0;
        $latencySum = 0;
        $successCount = 0;
        $episodeRows = [];

        foreach ($episodes as $episode) {
            $metrics = $episode['metrics'][$strategy];
            $score = scoreEpisode($metrics, $strategy, $seed, $episode['id']);
            $scoreSum += $score;
            $latencySum += $metrics['latency_ms'];
            $successCount += $metrics['success'] > 0 ? 1 : 0;

            $episodeRows[] = [
                'id' => $episode['id'],
                'scenario' => $episode['scenario'],
                'metrics' => $metrics,
                'score' => $score,
            ];
        }

        $count = count($episodeRows);
        $avgScore = $count > 0 ? round($scoreSum / $count, 4) : 0.0;
        $avgLatency = $count > 0 ? (int)round($latencySum / $count) : 0;
        $successRate = $count > 0 ? round(($successCount * 100.0) / $count, 2) : 0.0;

        $strategyRows[] = [
            'name' => $strategy,
            'episodes' => $episodeRows,
            'summary' => [
                'episodes' => $count,
                'success_count' => $successCount,
                'success_rate_percent' => $successRate,
                'avg_latency_ms' => $avgLatency,
                'avg_score' => $avgScore,
            ],
        ];
    }

    usort($strategyRows, static function (array $a, array $b): int {
        return ($b['summary']['avg_score'] <=> $a['summary']['avg_score']);
    });

    $leaderboard = [];
    foreach ($strategyRows as $index => $row) {
        $leaderboard[] = [
            'rank' => $index + 1,
            'strategy' => $row['name'],
            'avg_score' => $row['summary']['avg_score'],
            'success_rate_percent' => $row['summary']['success_rate_percent'],
            'avg_latency_ms' => $row['summary']['avg_latency_ms'],
        ];
    }

    $planner = null;
    $baseline = null;
    foreach ($strategyRows as $row) {
        if ($row['name'] === 'planner') {
            $planner = $row;
        }
        if ($row['name'] === 'baseline') {
            $baseline = $row;
        }
    }

    if (!is_array($planner) || !is_array($baseline)) {
        throw new RuntimeException('Required benchmark strategies baseline/planner are missing.');
    }

    $delta = round((float)$planner['summary']['avg_score'] - (float)$baseline['summary']['avg_score'], 4);

    return [
        'suite' => 'emcp-simulation-v1',
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'seed' => $seed,
        'thresholds' => [
            'planner_minus_baseline_min' => $threshold,
        ],
        'strategies' => $strategyRows,
        'leaderboard' => $leaderboard,
        'comparison' => [
            'planner_minus_baseline' => $delta,
            'pass' => $delta >= $threshold,
        ],
    ];
}

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));
    $episodes = loadEpisodes($options['fixtures']);

    $report = buildReport($episodes, $options['seed'], $options['pass_threshold']);
    $rawFixtures = file_get_contents($options['fixtures']);
    if (is_string($rawFixtures)) {
        $report['fixtures_hash'] = hash('sha256', $rawFixtures);
    }

    $outputDir = dirname($options['output']);
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        throw new RuntimeException("Unable to create benchmark output directory: {$outputDir}");
    }

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode benchmark report JSON.');
    }

    if (file_put_contents($options['output'], $encoded . PHP_EOL) === false) {
        throw new RuntimeException("Unable to write benchmark report: {$options['output']}");
    }

    $delta = (float)($report['comparison']['planner_minus_baseline'] ?? 0.0);
    $pass = (bool)($report['comparison']['pass'] ?? false);

    fwrite(
        STDOUT,
        sprintf(
            "[benchmark] report=%s planner-minus-baseline=%.4f pass=%s\n",
            $options['output'],
            $delta,
            $pass ? 'true' : 'false'
        )
    );

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, '[benchmark][FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
