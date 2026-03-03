<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/governance_helpers.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$lockPath = $root . '/.ci/governance-lock.json';

assertTrue(is_file($lockPath), 'Governance lock file is missing. Run php scripts/update_governance_lock.php');

$lockRaw = file_get_contents($lockPath);
assertTrue(is_string($lockRaw) && trim($lockRaw) !== '', 'Governance lock file is empty.');

$lock = json_decode($lockRaw, true);
assertTrue(is_array($lock), 'Governance lock file is not valid JSON.');

$toolset = governance_parse_toolset($root . '/TOOLSET.md');
$spec = governance_parse_spec($root . '/SPEC.md');
$modelHash = governance_model_allowlists_hash($root . '/src/Support/ModelFieldPolicy.php');

$lockToolsetVersion = trim((string)($lock['toolset_version'] ?? ''));
assertTrue($lockToolsetVersion !== '', 'Lock missing toolset_version.');

$currentTools = $toolset['canonical_tools'];
$lockedTools = is_array($lock['canonical_tools'] ?? null) ? array_values($lock['canonical_tools']) : [];

if ($currentTools !== $lockedTools) {
    $currentMajor = governance_major($toolset['toolset_version']);
    $lockedMajor = governance_major($lockToolsetVersion);

    assertTrue(
        $currentMajor > $lockedMajor,
        'Canonical tool names changed without MAJOR toolsetVersion bump. '
        . 'Update TOOLSET toolsetVersion and changelog, then refresh .ci/governance-lock.json'
    );

    $changelogPath = $root . '/CHANGELOG.md';
    assertTrue(is_file($changelogPath), 'CHANGELOG.md is required when canonical tool names change.');
    $changelog = file_get_contents($changelogPath);
    assertTrue(
        is_string($changelog) && str_contains($changelog, $toolset['toolset_version']) && str_contains($changelog, 'Canonical tools'),
        'CHANGELOG.md must mention canonical tool change and target toolsetVersion.'
    );
}

$lockedSpecHash = trim((string)($lock['spec_public_contract_hash'] ?? ''));
$lockedSpecVersion = trim((string)($lock['spec_version'] ?? ''));
$lockedRuntimeStatus = trim((string)($lock['runtime_status'] ?? ''));

assertTrue($lockedSpecHash !== '', 'Lock missing spec_public_contract_hash.');

if ($spec['public_contract_hash'] !== $lockedSpecHash) {
    $versionChanged = $spec['spec_version'] !== $lockedSpecVersion;
    $runtimeChanged = $spec['runtime_status'] !== $lockedRuntimeStatus;

    assertTrue(
        $versionChanged || $runtimeChanged,
        'SPEC public-contract stability section changed without SPEC version/runtime marker update. '
        . 'Update markers and refresh .ci/governance-lock.json'
    );
}

$lockedModelHash = trim((string)($lock['model_allowlists_hash'] ?? ''));
assertTrue($lockedModelHash !== '', 'Lock missing model_allowlists_hash.');
assertTrue(
    $modelHash === $lockedModelHash,
    'Model allowlist changed without governance lock update. '
    . 'Update allowlist tests/fixtures and refresh .ci/governance-lock.json'
);

echo "Governance guards passed.\n";
