<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use Illuminate\Support\Str;

final class SecurityPolicy
{
    public function isServerAllowed(string $serverHandle): bool
    {
        $allow = config('cms.settings.eMCP.security.allow_servers', ['*']);
        if (!is_array($allow) || $allow === []) {
            return false;
        }

        $serverHandle = trim($serverHandle);
        if ($serverHandle === '') {
            return false;
        }

        foreach ($allow as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern === '') {
                continue;
            }

            if ($pattern === '*' || Str::is($pattern, $serverHandle)) {
                return true;
            }
        }

        return false;
    }

    public function isToolDenied(string $serverHandle, string $toolName): bool
    {
        $toolName = trim($toolName);
        if ($toolName === '') {
            return false;
        }

        if ($this->isWriteToolDenied($toolName)) {
            return true;
        }

        $global = $this->normalizePatterns(config('cms.settings.eMCP.security.deny_tools', []));
        $server = $this->serverToolDenyPatterns($serverHandle);

        foreach (array_merge($global, $server) as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (Str::is($pattern, $toolName)) {
                return true;
            }
        }

        return false;
    }

    public function isWriteToolDenied(string $toolName): bool
    {
        if ((bool)config('cms.settings.eMCP.security.enable_write_tools', false)) {
            return false;
        }

        return str_starts_with(trim($toolName), 'evo.write.');
    }

    /**
     * @param  array<string, mixed>  $jsonRpc
     */
    public function resolveToolName(array $jsonRpc): ?string
    {
        if (trim((string)($jsonRpc['method'] ?? '')) !== 'tools/call') {
            return null;
        }

        $params = $jsonRpc['params'] ?? null;
        if (!is_array($params)) {
            return null;
        }

        $name = trim((string)($params['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<int, mixed>  $patterns
     * @return array<int, string>
     */
    private function normalizePatterns(mixed $patterns): array
    {
        if (!is_array($patterns)) {
            return [];
        }

        $result = [];

        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern !== '') {
                $result[] = $pattern;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<int, string>
     */
    private function serverToolDenyPatterns(string $serverHandle): array
    {
        $servers = config('mcp.servers', []);
        if (!is_array($servers)) {
            return [];
        }

        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }

            if (trim((string)($server['handle'] ?? '')) !== trim($serverHandle)) {
                continue;
            }

            return $this->normalizePatterns($server['security']['deny_tools'] ?? []);
        }

        return [];
    }
}
