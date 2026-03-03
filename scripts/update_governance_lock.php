<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_helpers.php';

$root = dirname(__DIR__);
$lockPath = $root . '/.ci/governance-lock.json';

$toolset = governance_parse_toolset($root . '/TOOLSET.md');
$spec = governance_parse_spec($root . '/SPEC.md');
$modelHash = governance_model_allowlists_hash($root . '/src/Support/ModelFieldPolicy.php');

$payload = [
    'toolset_version' => $toolset['toolset_version'],
    'canonical_tools' => $toolset['canonical_tools'],
    'spec_version' => $spec['spec_version'],
    'runtime_status' => $spec['runtime_status'],
    'spec_public_contract_hash' => $spec['public_contract_hash'],
    'model_allowlists_hash' => $modelHash,
    'updated_at' => date(DATE_ATOM),
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (!is_string($json)) {
    throw new RuntimeException('Unable to encode governance lock payload.');
}

file_put_contents($lockPath, $json);

echo "Governance lock updated: {$lockPath}\n";
