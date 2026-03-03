<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);

$composerRaw = file_get_contents($root . '/composer.json');
assertTrue(is_string($composerRaw), 'Unable to read composer.json');
$composer = json_decode($composerRaw, true);
assertTrue(is_array($composer), 'composer.json must be valid JSON');

$scripts = $composer['scripts'] ?? null;
assertTrue(is_array($scripts), 'composer scripts section must exist');

$requiredScripts = [
    'test:unit:redactor',
    'test:unit:security-policy',
    'test:unit:model-field-leakage',
    'test:integration:runtime',
    'test:integration:clean-install',
    'test:phase6:fixtures',
    'test:phase6:governance',
    'test:phase6:advanced-tree',
    'benchmark:run',
    'benchmark:leaderboard',
];

foreach ($requiredScripts as $script) {
    assertTrue(array_key_exists($script, $scripts), "Missing required composer script: {$script}");
}

$requires = $composer['require'] ?? null;
assertTrue(is_array($requires), 'composer require section must exist');
assertTrue(isset($requires['seiger/sapi']), 'Package must require seiger/sapi for API transport');
assertTrue(isset($requires['seiger/stask']), 'Package must require seiger/stask for async dispatch');
assertTrue(isset($requires['laravel/mcp']), 'Package must require laravel/mcp upstream runtime');
assertTrue(!isset($requires['laravel/passport']), 'Package must not require laravel/passport');

$provider = file_get_contents($root . '/src/eMCPServiceProvider.php');
assertTrue(is_string($provider), 'Unable to read src/eMCPServiceProvider.php');
assertTrue(str_contains($provider, 'eMcpTestCommand::class'), 'Service provider must register emcp:test command');
assertTrue(str_contains($provider, 'eMcpListServersCommand::class'), 'Service provider must register emcp:list-servers command');
assertTrue(str_contains($provider, 'eMcpSyncWorkersCommand::class'), 'Service provider must register emcp:sync-workers command');

$makefile = file_get_contents($root . '/Makefile');
assertTrue(is_string($makefile), 'Unable to read Makefile');
assertTrue((bool)preg_match('/^demo:/m', $makefile), 'Makefile must expose demo target');
assertTrue((bool)preg_match('/^demo-verify:/m', $makefile), 'Makefile must expose demo-verify target');
assertTrue((bool)preg_match('/^demo-all:/m', $makefile), 'Makefile must expose demo-all target');
assertTrue((bool)preg_match('/^clean-install-validate:/m', $makefile), 'Makefile must expose clean-install-validate target');
assertTrue((bool)preg_match('/^benchmark:/m', $makefile), 'Makefile must expose benchmark target');
assertTrue((bool)preg_match('/^leaderboard:/m', $makefile), 'Makefile must expose leaderboard target');
assertTrue((bool)preg_match('/^migration-matrix-sqlite:/m', $makefile), 'Makefile must expose migration-matrix-sqlite target');
assertTrue((bool)preg_match('/^migration-matrix-mysql:/m', $makefile), 'Makefile must expose migration-matrix-mysql target');
assertTrue((bool)preg_match('/^migration-matrix-pgsql:/m', $makefile), 'Makefile must expose migration-matrix-pgsql target');

$readme = file_get_contents($root . '/README.md');
$readmeUk = file_get_contents($root . '/README.uk.md');
$docs = file_get_contents($root . '/DOCS.md');
$docsUk = file_get_contents($root . '/DOCS.uk.md');

assertTrue(is_string($readme) && is_string($readmeUk), 'README files must be readable');
assertTrue(is_string($docs) && is_string($docsUk), 'DOCS files must be readable');

$requiredDocStrings = [
    'php artisan emcp:test',
    'php artisan emcp:list-servers',
    'php artisan emcp:sync-workers',
    'make demo-all',
    'composer run benchmark:run',
    'composer run benchmark:leaderboard',
    'composer run test:integration:clean-install',
    '/api/v1/mcp/content',
];

foreach ($requiredDocStrings as $needle) {
    assertTrue(str_contains($readme, $needle), "README.md missing runtime/doc entry: {$needle}");
    assertTrue(str_contains($readmeUk, $needle), "README.uk.md missing runtime/doc entry: {$needle}");
}

assertTrue(str_contains($docs, 'manager-only'), 'DOCS.md must document manager-only profile preset');
assertTrue(str_contains($docs, 'api-only'), 'DOCS.md must document api-only profile preset');
assertTrue(str_contains($docs, 'hybrid'), 'DOCS.md must document hybrid profile preset');

assertTrue(str_contains($docsUk, 'manager-only'), 'DOCS.uk.md must document manager-only profile preset');
assertTrue(str_contains($docsUk, 'api-only'), 'DOCS.uk.md must document api-only profile preset');
assertTrue(str_contains($docsUk, 'hybrid'), 'DOCS.uk.md must document hybrid profile preset');

echo "Docs/config/commands consistency checks passed.\n";
