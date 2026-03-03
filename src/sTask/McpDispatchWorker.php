<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\sTask;

use EvolutionCMS\eMCP\Services\IdempotencyStore;
use EvolutionCMS\eMCP\Services\McpExecutionService;
use Seiger\sTask\Models\sTaskModel;
use Seiger\sTask\Workers\BaseWorker;

class McpDispatchWorker extends BaseWorker
{
    public function identifier(): string
    {
        return 'emcp_dispatch';
    }

    public function scope(): string
    {
        return 'eMCP';
    }

    public function icon(): string
    {
        return '<i class="fa fa-share-square"></i>';
    }

    public function title(): string
    {
        return 'eMCP Dispatch Worker';
    }

    public function description(): string
    {
        return 'Executes async MCP dispatch payloads and persists normalized results.';
    }

    public function taskDispatch(sTaskModel $task, array $options = []): void
    {
        $meta = is_array($task->meta) ? $task->meta : [];

        $this->pushProgress($task, [
            'status' => $task->status_text,
            'progress' => 10,
            'message' => 'Dispatching MCP request...',
        ]);

        try {
            /** @var McpExecutionService $executor */
            $executor = app()->make(McpExecutionService::class);
            $result = $executor->call($meta);

            $task->update([
                'status' => sTaskModel::TASK_STATUS_FINISHED,
                'progress' => 100,
                'message' => 'MCP dispatch completed',
                'result' => $result,
                'finished_at' => now(),
            ]);

            $this->pushProgress($task, [
                'status' => 'completed',
                'progress' => 100,
                'message' => 'MCP dispatch completed',
                'result' => $result,
            ]);

            $idempotencyKey = trim((string)($meta['idempotency_key'] ?? ''));
            $payloadHash = trim((string)($meta['payload_hash'] ?? ''));

            if ($idempotencyKey !== '' && $payloadHash !== '') {
                /** @var IdempotencyStore $store */
                $store = app()->make(IdempotencyStore::class);
                $store->rememberCompleted(
                    $idempotencyKey,
                    $payloadHash,
                    (int)$task->id,
                    trim((string)($result['trace_id'] ?? '')),
                    $result
                );
            }
        } catch (\Throwable $e) {
            $message = $this->sanitizeErrorMessage($e->getMessage());

            $task->update([
                'status' => sTaskModel::TASK_STATUS_FAILED,
                'message' => $message,
                'finished_at' => now(),
            ]);

            $this->pushProgress($task, [
                'status' => 'failed',
                'message' => $message,
            ]);
        }
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'MCP dispatch failed.';
        }

        if (strlen($message) > 240) {
            $message = substr($message, 0, 240) . '...';
        }

        return $message;
    }
}
