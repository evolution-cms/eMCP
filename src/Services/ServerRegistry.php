<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

class ServerRegistry
{
    private const CORE_NAMESPACE = 'EvolutionCMS\\eMCP\\';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $servers = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allEnabled(): array
    {
        if ($this->servers !== null) {
            return $this->servers;
        }

        $configured = config('mcp.servers', []);
        if (!is_array($configured)) {
            $this->servers = [];
            return $this->servers;
        }

        $resolved = [];
        $toolNames = [];

        foreach ($configured as $server) {
            if (!is_array($server)) {
                continue;
            }

            $enabled = (bool)($server['enabled'] ?? false);
            if (!$enabled) {
                continue;
            }

            $handle = (string)($server['handle'] ?? '');
            $class = (string)($server['class'] ?? '');
            $transport = (string)($server['transport'] ?? 'web');

            if ($handle === '' || $class === '') {
                $this->report('Server config entry must contain non-empty `handle` and `class`.');
                continue;
            }

            if (isset($resolved[$handle])) {
                $this->report("Duplicate server handle [{$handle}] is forbidden.");
                continue;
            }

            if (!in_array($transport, ['web', 'local'], true)) {
                $this->report("Server [{$handle}] has unsupported transport [{$transport}].");
                continue;
            }

            if (!class_exists($class)) {
                $this->report("Server class [{$class}] for handle [{$handle}] does not exist.");
                continue;
            }

            if (!is_subclass_of($class, Server::class)) {
                $this->report("Server class [{$class}] for handle [{$handle}] must extend " . Server::class . '.');
                continue;
            }

            $serverToolNames = $this->extractToolNames($class, $server);
            $acceptServer = true;

            foreach ($serverToolNames as $toolName) {
                if (isset($toolNames[$toolName])) {
                    $this->report(
                        "Duplicate tool name [{$toolName}] is forbidden. " .
                        "First declared by [{$toolNames[$toolName]}], conflict at [{$handle}]."
                    );
                    $acceptServer = false;
                    break;
                }

                if (str_starts_with($toolName, 'evo.') && !$this->isCoreServer($class)) {
                    $this->report(
                        "Namespace violation for tool [{$toolName}] in server [{$handle}]. " .
                        "Only core package can register evo.* tools."
                    );
                    $acceptServer = false;
                    break;
                }

                $toolNames[$toolName] = $handle;
            }

            if (!$acceptServer) {
                continue;
            }

            $resolved[$handle] = $server;
        }

        $this->servers = $resolved;

        return $resolved;
    }

    public function registerLocalServers(): void
    {
        foreach ($this->allEnabled() as $handle => $server) {
            $transport = (string)($server['transport'] ?? 'web');
            if ($transport !== 'local') {
                continue;
            }

            Mcp::local($handle, (string)$server['class']);
        }
    }

    public function resolveWebServerClassByHandle(string $handle): ?string
    {
        $server = $this->allEnabled()[$handle] ?? null;
        if (!$server) {
            return null;
        }

        return (($server['transport'] ?? '') === 'web')
            ? (string)$server['class']
            : null;
    }

    /**
     * @return array<int, string>
     */
    public function handles(): array
    {
        return array_keys($this->allEnabled());
    }

    /**
     * @param  array<string, mixed>  $serverConfig
     * @return array<int, string>
     */
    private function extractToolNames(string $serverClass, array $serverConfig): array
    {
        $names = [];

        // Optional explicit declarations for deterministic validation.
        $declared = $serverConfig['tool_names'] ?? null;
        if (is_array($declared)) {
            foreach ($declared as $name) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        try {
            $reflection = new \ReflectionClass($serverClass);
            $defaults = $reflection->getDefaultProperties();
            $tools = $defaults['tools'] ?? [];
            if (!is_array($tools)) {
                return array_values(array_unique($names));
            }

            foreach ($tools as $tool) {
                if (!is_string($tool) || !class_exists($tool) || !is_subclass_of($tool, Tool::class)) {
                    continue;
                }

                $toolName = $this->resolveToolName($tool);
                if ($toolName !== '') {
                    $names[] = $toolName;
                }
            }
        } catch (\Throwable) {
            // Best-effort extraction; dynamic tools are validated during runtime registration.
        }

        return array_values(array_unique($names));
    }

    private function resolveToolName(string $toolClass): string
    {
        try {
            $reflection = new \ReflectionClass($toolClass);

            $nameAttribute = $reflection->getAttributes(Name::class)[0] ?? null;
            if ($nameAttribute !== null) {
                $value = trim((string)$nameAttribute->newInstance()->value);
                if ($value !== '') {
                    return $value;
                }
            }

            $defaults = $reflection->getDefaultProperties();
            $name = trim((string)($defaults['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            // fallback below
        }

        return Str::kebab(class_basename($toolClass));
    }

    private function isCoreServer(string $serverClass): bool
    {
        return str_starts_with(ltrim($serverClass, '\\'), self::CORE_NAMESPACE);
    }

    private function report(string $message): void
    {
        if ((bool)config('app.debug', false)) {
            throw new \RuntimeException($message);
        }

        Log::warning('eMCP registry: ' . $message);
    }
}
