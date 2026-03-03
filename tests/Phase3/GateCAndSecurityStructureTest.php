<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContains(string $haystack, string $needle, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " [missing: {$needle}]");
}

$mgrRoutes = file_get_contents(__DIR__ . '/../../src/Http/mgrRoutes.php');
assertTrue(is_string($mgrRoutes), 'Unable to read manager routes');
assertContains($mgrRoutes, 'McpDispatchController::class', 'Manager dispatch route must be wired to controller');
assertTrue(!str_contains($mgrRoutes, 'not_implemented'), 'Manager dispatch route must not return 501 stub');

$apiRoutes = file_get_contents(__DIR__ . '/../../src/Api/Routes/McpRouteProvider.php');
assertTrue(is_string($apiRoutes), 'Unable to read API routes');
assertContains($apiRoutes, 'McpDispatchController::class', 'API dispatch route must be wired to controller');
assertTrue(!str_contains($apiRoutes, 'not_implemented'), 'API dispatch route must not return 501 stub');

$dispatchController = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpDispatchController.php');
assertTrue(is_string($dispatchController), 'Unable to read McpDispatchController');
assertContains($dispatchController, 'idempotency_conflict', 'Dispatch must enforce idempotency 409 conflict');
assertContains($dispatchController, "'identifier' => 'emcp_dispatch'", 'Dispatch must enqueue sTask emcp_dispatch worker tasks');
assertContains($dispatchController, "queue.failover", 'Dispatch must support queue failover semantics');
assertContains($dispatchController, 'McpExecutionService', 'Dispatch must call execution service');

$worker = file_get_contents(__DIR__ . '/../../src/sTask/McpDispatchWorker.php');
assertTrue(is_string($worker), 'Unable to read McpDispatchWorker');
assertContains($worker, 'class McpDispatchWorker', 'Worker class must exist');
assertContains($worker, 'function taskDispatch', 'Worker must implement taskDispatch action');

$execution = file_get_contents(__DIR__ . '/../../src/Services/McpExecutionService.php');
assertTrue(is_string($execution), 'Unable to read McpExecutionService');
assertContains($execution, 'isServerAllowed', 'Execution service must enforce allow_servers policy');
assertContains($execution, 'isToolDenied', 'Execution service must enforce deny_tools policy');

$managerController = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpManagerController.php');
assertTrue(is_string($managerController), 'Unable to read McpManagerController');
assertContains($managerController, 'server_denied', 'Manager controller must enforce allow_servers policy');
assertContains($managerController, 'tool_denied', 'Manager controller must enforce deny_tools policy');
assertContains($managerController, 'AuditLogger', 'Manager controller must wire audit logger');

$migrationFiles = glob(__DIR__ . '/../../database/migrations/*_add_emcp_dispatch_permission.php') ?: [];
assertTrue(count($migrationFiles) === 1, 'emcp_dispatch permission migration must exist once');

$migrationContent = file_get_contents($migrationFiles[0]);
assertTrue(is_string($migrationContent), 'Unable to read emcp_dispatch migration');
assertContains($migrationContent, "'key', 'emcp_dispatch'", 'Migration must create emcp_dispatch permission');

$provider = file_get_contents(__DIR__ . '/../../src/eMCPServiceProvider.php');
assertTrue(is_string($provider), 'Unable to read service provider');
assertContains($provider, 'AuditLogger::class', 'Service provider must register AuditLogger');
assertContains($provider, 'SecurityPolicy::class', 'Service provider must register SecurityPolicy');
assertContains($provider, 'IdempotencyStore::class', 'Service provider must register IdempotencyStore');
assertContains($provider, 'Redactor::class', 'Service provider must register Redactor');

echo "Gate C and security structural checks passed.\n";
