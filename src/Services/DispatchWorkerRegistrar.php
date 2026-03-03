<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Services;

use Illuminate\Support\Facades\Schema;
use Seiger\sTask\Models\sWorker;

final class DispatchWorkerRegistrar
{
    /**
     * @return array{status: string, message: string}
     */
    public function sync(): array
    {
        if (!class_exists(sWorker::class) || !class_exists(Schema::class)) {
            return [
                'status' => 'skipped',
                'message' => 'sTask is not available in runtime.',
            ];
        }

        if ((string)config('cms.settings.eMCP.queue.driver', 'stask') !== 'stask') {
            return [
                'status' => 'skipped',
                'message' => 'queue.driver is not stask.',
            ];
        }

        try {
            if (!Schema::hasTable('s_workers')) {
                return [
                    'status' => 'skipped',
                    'message' => 's_workers table does not exist.',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Cannot inspect s_workers table: ' . $e->getMessage(),
            ];
        }

        $workerClass = \EvolutionCMS\eMCP\sTask\McpDispatchWorker::class;
        if (!class_exists($workerClass)) {
            return [
                'status' => 'skipped',
                'message' => 'McpDispatchWorker class is not available yet.',
            ];
        }

        try {
            $existing = sWorker::query()->where('identifier', 'emcp_dispatch')->first();
            if ($existing) {
                $changed = false;

                if ((string)$existing->class !== $workerClass) {
                    $existing->class = $workerClass;
                    $changed = true;
                }
                if ((string)$existing->scope !== 'eMCP') {
                    $existing->scope = 'eMCP';
                    $changed = true;
                }
                if ((int)$existing->active !== 1) {
                    $existing->active = 1;
                    $changed = true;
                }

                if ($changed) {
                    $existing->save();
                    return [
                        'status' => 'updated',
                        'message' => 'emcp_dispatch worker updated.',
                    ];
                }

                return [
                    'status' => 'ok',
                    'message' => 'emcp_dispatch worker already in sync.',
                ];
            }

            $position = (int)(sWorker::max('position') ?? 0) + 1;

            sWorker::query()->create([
                'identifier' => 'emcp_dispatch',
                'scope' => 'eMCP',
                'class' => $workerClass,
                'active' => true,
                'position' => $position,
                'settings' => [],
                'hidden' => 0,
            ]);

            return [
                'status' => 'created',
                'message' => 'emcp_dispatch worker created.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to sync emcp_dispatch worker: ' . $e->getMessage(),
            ];
        }
    }
}
