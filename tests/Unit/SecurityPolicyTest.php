<?php

declare(strict_types=1);

namespace Illuminate\Support {
    if (!class_exists(Str::class)) {
        final class Str
        {
            public static function is(string $pattern, string $value): bool
            {
                if ($pattern === '*') {
                    return true;
                }

                $quoted = preg_quote($pattern, '/');
                $regex = '/^' . str_replace('\\*', '.*', $quoted) . '$/u';

                return (bool)preg_match($regex, $value);
            }
        }
    }
}

namespace {
    use EvolutionCMS\eMCP\Services\SecurityPolicy;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $config = [
        'cms.settings.eMCP.security.allow_servers' => ['content', 'public-*'],
        'cms.settings.eMCP.security.deny_tools' => ['evo.model.*'],
        'cms.settings.eMCP.security.enable_write_tools' => false,
        'mcp.servers' => [
            [
                'handle' => 'content',
                'security' => [
                    'deny_tools' => ['evo.content.get'],
                ],
            ],
        ],
    ];

    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            global $config;

            return $config[$key] ?? $default;
        }
    }

    require_once __DIR__ . '/../../src/Services/SecurityPolicy.php';

    $policy = new SecurityPolicy();

    assertTrue($policy->isServerAllowed('content'), 'Exact allowlisted server should be allowed');
    assertTrue($policy->isServerAllowed('public-api'), 'Wildcard allowlisted server should be allowed');
    assertTrue(!$policy->isServerAllowed('private'), 'Non-allowlisted server must be denied');

    assertTrue($policy->isToolDenied('content', 'evo.model.list'), 'Global deny_tools wildcard should deny');
    assertTrue($policy->isToolDenied('content', 'evo.content.get'), 'Server-level deny_tools should deny');
    assertTrue(!$policy->isToolDenied('content', 'evo.content.search'), 'Non-denied tool should be allowed');

    assertTrue($policy->isWriteToolDenied('evo.write.publish'), 'Write tools must be denied by default');

    $config['cms.settings.eMCP.security.enable_write_tools'] = true;
    assertTrue(!$policy->isWriteToolDenied('evo.write.publish'), 'Write tools should be allowed when feature flag enabled');

    $toolName = $policy->resolveToolName([
        'method' => 'tools/call',
        'params' => ['name' => 'evo.content.search'],
    ]);
    assertTrue($toolName === 'evo.content.search', 'tools/call name should resolve');

    $toolNameNull = $policy->resolveToolName([
        'method' => 'initialize',
        'params' => [],
    ]);
    assertTrue($toolNameNull === null, 'Non tools/call method should not resolve tool name');

    echo "SecurityPolicy unit checks passed.\n";
}
