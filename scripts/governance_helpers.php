<?php

declare(strict_types=1);

/**
 * @return array{toolset_version:string, canonical_tools:array<int,string>}
 */
function governance_parse_toolset(string $toolsetPath): array
{
    $content = file_get_contents($toolsetPath);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Unable to read TOOLSET.md');
    }

    if (!preg_match('/`toolsetVersion`:\s*`([^`]+)`/', $content, $matches)) {
        throw new RuntimeException('Unable to extract toolsetVersion from TOOLSET.md');
    }

    $version = trim((string)$matches[1]);

    $lines = preg_split('/\R/', $content) ?: [];
    $canonical = [];

    $mode = '';
    $inOptional = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '### 4.1')) {
            $mode = 'content';
            $inOptional = false;
            continue;
        }

        if (str_starts_with($trimmed, '### 4.2')) {
            $mode = 'model';
            $inOptional = false;
            continue;
        }

        if ($mode !== '' && str_starts_with($trimmed, '## 5.')) {
            break;
        }

        if ($mode === 'content' && str_starts_with($trimmed, 'Post-MVP optional:')) {
            $inOptional = true;
            continue;
        }

        if ($mode === '') {
            continue;
        }

        if (!preg_match('/^-\s+`([^`]+)`\s*$/', $trimmed, $toolMatch)) {
            continue;
        }

        $tool = trim((string)$toolMatch[1]);
        if ($tool === '') {
            continue;
        }

        if ($mode === 'content' && $inOptional) {
            continue;
        }

        $canonical[] = $tool;
    }

    if ($canonical === []) {
        throw new RuntimeException('Unable to extract canonical tools from TOOLSET.md');
    }

    return [
        'toolset_version' => $version,
        'canonical_tools' => array_values(array_unique($canonical)),
    ];
}

/**
 * @return array{spec_version:string, runtime_status:string, public_contract_hash:string}
 */
function governance_parse_spec(string $specPath): array
{
    $content = file_get_contents($specPath);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Unable to read SPEC.md');
    }

    if (!preg_match('/`SPEC Version`:\s*`([^`]+)`/', $content, $versionMatches)) {
        throw new RuntimeException('Unable to extract SPEC version marker.');
    }

    if (!preg_match('/`Runtime Status`:\s*`([^`]+)`/', $content, $statusMatches)) {
        throw new RuntimeException('Unable to extract SPEC runtime status marker.');
    }

    $sectionHeading = "## 18. Public contract stability (MUST)";
    $start = strpos($content, $sectionHeading);
    if ($start === false) {
        throw new RuntimeException('Public contract stability section is missing in SPEC.md.');
    }

    $section = substr($content, $start);
    if (!is_string($section)) {
        throw new RuntimeException('Unable to parse SPEC public contract section.');
    }

    $nextHeadingPos = strpos($section, "\n## ", strlen($sectionHeading));
    if ($nextHeadingPos !== false) {
        $section = substr($section, 0, $nextHeadingPos);
    }

    $section = trim($section);

    return [
        'spec_version' => trim((string)$versionMatches[1]),
        'runtime_status' => trim((string)$statusMatches[1]),
        'public_contract_hash' => hash('sha256', $section),
    ];
}

function governance_model_allowlists_hash(string $modelPolicyPath): string
{
    require_once $modelPolicyPath;

    $allowlists = \EvolutionCMS\eMCP\Support\ModelFieldPolicy::fieldAllowlists();
    if (!is_array($allowlists)) {
        throw new RuntimeException('Model allowlists are invalid.');
    }

    return hash(
        'sha256',
        (string)json_encode($allowlists, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function governance_major(string $version): int
{
    $version = trim($version);
    if ($version === '') {
        return 0;
    }

    $parts = preg_split('/[.-]/', $version) ?: [];
    $major = $parts[0] ?? '0';

    return is_numeric($major) ? (int)$major : 0;
}
