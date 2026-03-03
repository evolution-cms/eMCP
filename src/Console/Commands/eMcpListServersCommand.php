<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Console\Commands;

use EvolutionCMS\eMCP\Services\ServerRegistry;
use Illuminate\Console\Command;

class eMcpListServersCommand extends Command
{
    protected $signature = 'emcp:list-servers';

    protected $description = 'List enabled eMCP servers resolved from config.';

    public function handle(ServerRegistry $registry): int
    {
        $servers = $registry->allEnabled();

        if ($servers === []) {
            $this->warn('No enabled MCP servers found in config(mcp.servers).');
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

        return self::SUCCESS;
    }
}
