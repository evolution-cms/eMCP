<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Console\Commands;

use EvolutionCMS\eMCP\Services\DispatchWorkerRegistrar;
use Illuminate\Console\Command;

class eMcpSyncWorkersCommand extends Command
{
    protected $signature = 'emcp:sync-workers';

    protected $description = 'Synchronize eMCP workers with sTask registry.';

    public function handle(DispatchWorkerRegistrar $registrar): int
    {
        $result = $registrar->sync();
        $status = $result['status'] ?? 'error';
        $message = (string)($result['message'] ?? 'Unknown status');

        if ($status === 'error') {
            $this->error($message);
            return self::FAILURE;
        }

        if ($status === 'skipped') {
            $this->warn($message);
            return self::SUCCESS;
        }

        $this->info($message);
        return self::SUCCESS;
    }
}
