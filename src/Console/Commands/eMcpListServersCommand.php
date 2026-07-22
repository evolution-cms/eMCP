<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Console\Commands;

use EvolutionCMS\eMCP\Services\ServerRegistry;
use Illuminate\Console\Command;

/**
 * Lists runtime-ready MCP servers and reports rejected configuration entries.
 */
class eMcpListServersCommand extends Command
{
    protected $signature = 'emcp:list-servers';

    protected $description = 'List enabled eMCP servers resolved from config.';

    /**
     * Render resolved servers plus any validation diagnostics from the registry.
     */
    public function handle(ServerRegistry $registry): int
    {
        $servers = $registry->allEnabled();

        if ($servers === []) {
            $this->warn('No valid enabled MCP servers found in config(mcp.servers).');
            $this->renderRegistryDiagnostics($registry);
            return self::FAILURE;
        }

        $rows = [];
        foreach ($servers as $server) {
            $rows[] = [
                (string)($server['handle'] ?? ''),
                (string)($server['transport'] ?? ''),
                (string)($server['class'] ?? ''),
                ((bool)($server['enabled'] ?? false)) ? 'yes' : 'no',
            ];
        }

        $this->table(['Handle', 'Transport', 'Class', 'Enabled'], $rows);
        $this->renderRegistryDiagnostics($registry);

        return self::SUCCESS;
    }

    /**
     * Print entries rejected while the registry resolved the configuration.
     *
     * @since 1.1.0
     */
    private function renderRegistryDiagnostics(ServerRegistry $registry): void
    {
        foreach ($registry->diagnostics() as $diagnostic) {
            $this->warn('Rejected: ' . $diagnostic);
        }
    }
}
