<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP;

use EvolutionCMS\ServiceProvider;
use EvolutionCMS\eMCP\Console\Commands\eMcpListServersCommand;
use EvolutionCMS\eMCP\Console\Commands\eMcpSyncWorkersCommand;
use EvolutionCMS\eMCP\Console\Commands\eMcpTestCommand;
use EvolutionCMS\eMCP\Middleware\EnsureApiJwt;
use EvolutionCMS\eMCP\Middleware\EnsureMcpPermission;
use EvolutionCMS\eMCP\Middleware\EnsureMcpScopes;
use EvolutionCMS\eMCP\Middleware\RateLimitMcpRequests;
use EvolutionCMS\eMCP\Middleware\ResolveMcpActor;
use EvolutionCMS\eMCP\Services\AuditLogger;
use EvolutionCMS\eMCP\Services\DispatchWorkerRegistrar;
use EvolutionCMS\eMCP\Services\IdempotencyStore;
use EvolutionCMS\eMCP\Services\McpExecutionService;
use EvolutionCMS\eMCP\Services\SecurityPolicy;
use EvolutionCMS\eMCP\Services\ServerRegistry;
use EvolutionCMS\eMCP\Support\Redactor;

class eMCPServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadPluginsFrom(dirname(__DIR__) . '/plugins/');

        $this->mergeConfigFrom(dirname(__DIR__) . '/config/eMCPSettings.php', 'cms.settings.eMCP');
        $this->registerLoggingChannel();
        $this->registerMiddlewareAliases();

        $this->app->singleton(ServerRegistry::class);
        $this->app->singleton(DispatchWorkerRegistrar::class);
        $this->app->singleton(SecurityPolicy::class);
        $this->app->singleton(IdempotencyStore::class);
        $this->app->singleton(Redactor::class, function () {
            $keys = config('cms.settings.eMCP.logging.redact_keys', []);
            return new Redactor(is_array($keys) ? $keys : []);
        });
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(McpExecutionService::class);
        $this->app->register(\EvolutionCMS\eMCP\LaravelMcp\McpServiceProvider::class);
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/mcp.php', 'mcp');
        $this->validateServerRegistry();

        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__) . '/lang', 'eMCP');
        $this->loadMgrRoutes();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->publishResources();
        }

        $this->autoRegisterDispatchWorker();

        $this->app->booted(function (): void {
            if ($this->app->runningInConsole()) {
                $this->flattenPublishDirectories();
            }
        });
    }

    protected function registerCommands(): void
    {
        $this->commands([
            eMcpTestCommand::class,
            eMcpListServersCommand::class,
            eMcpSyncWorkersCommand::class,
        ]);
    }

    protected function validateServerRegistry(): void
    {
        /** @var ServerRegistry $registry */
        $registry = $this->app->make(ServerRegistry::class);
        $registry->allEnabled();
    }

    protected function loadMgrRoutes(): void
    {
        $this->app->router->middlewareGroup('mgr', config('app.middleware.mgr', []));
        include dirname(__DIR__) . '/src/Http/mgrRoutes.php';
    }

    protected function registerLoggingChannel(): void
    {
        $channels = (array)$this->app['config']->get('logging.channels', []);

        if (isset($channels['emcp'])) {
            return;
        }

        $path = defined('EVO_STORAGE_PATH')
            ? rtrim((string)EVO_STORAGE_PATH, '/\\') . '/logs/emcp.log'
            : base_path('core/storage/logs/emcp.log');

        $this->app['config']->set('logging.channels.emcp', [
            'driver' => 'daily',
            'name' => env('APP_NAME', 'evo'),
            'path' => $path,
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ]);
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app->router;

        $router->aliasMiddleware('emcp.permission', EnsureMcpPermission::class);
        $router->aliasMiddleware('emcp.jwt', EnsureApiJwt::class);
        $router->aliasMiddleware('emcp.scope', EnsureMcpScopes::class);
        $router->aliasMiddleware('emcp.actor', ResolveMcpActor::class);
        $router->aliasMiddleware('emcp.rate', RateLimitMcpRequests::class);
    }

    protected function publishResources(): void
    {
        $this->publishes([
            dirname(__DIR__) . '/config/eMCPSettings.php' => $this->customConfigPath('cms/settings/eMCP.php'),
        ], 'emcp-config');

        $this->publishes([
            dirname(__DIR__) . '/config/mcp.php' => $this->customConfigPath('mcp.php'),
        ], 'emcp-mcp-config');

        $stubs = dirname(__DIR__) . '/stubs';
        if (is_dir($stubs)) {
            $this->publishes([
                $stubs . '/mcp-server.stub' => base_path('stubs/mcp-server.stub'),
                $stubs . '/mcp-tool.stub' => base_path('stubs/mcp-tool.stub'),
                $stubs . '/mcp-resource.stub' => base_path('stubs/mcp-resource.stub'),
                $stubs . '/mcp-prompt.stub' => base_path('stubs/mcp-prompt.stub'),
            ], 'emcp-stubs');
        }

        $langSource = dirname(__DIR__) . '/lang';
        if (is_dir($langSource)) {
            $langFiles = $this->collectPublishFiles($langSource, $this->resolveLangVendorPath('emcp'));
            if ($langFiles !== []) {
                $this->publishes($langFiles, 'emcp-lang');
            }
        }
    }

    protected function customConfigPath(string $path): string
    {
        $path = ltrim($path, '/\\');

        if (function_exists('config_path')) {
            $candidate = config_path($path, true);
            if (is_string($candidate) && $candidate !== '') {
                $normalized = str_replace('\\', '/', $candidate);
                if (str_contains($normalized, '/custom/config/')) {
                    return $candidate;
                }
            }
        }

        if (defined('EVO_CORE_PATH')) {
            return rtrim((string)EVO_CORE_PATH, '/\\') . '/custom/config/' . $path;
        }

        return base_path('core/custom/config/' . $path);
    }

    protected function autoRegisterDispatchWorker(): void
    {
        /** @var DispatchWorkerRegistrar $registrar */
        $registrar = $this->app->make(DispatchWorkerRegistrar::class);
        $registrar->sync();
    }

    protected function flattenPublishDirectories(): void
    {
        if (!class_exists(\Illuminate\Support\ServiceProvider::class)) {
            return;
        }

        $reflection = new \ReflectionClass(\Illuminate\Support\ServiceProvider::class);
        $publishesProperty = $reflection->getProperty('publishes');
        $publishesProperty->setAccessible(true);
        $publishGroupsProperty = $reflection->getProperty('publishGroups');
        $publishGroupsProperty->setAccessible(true);

        $publishes = $publishesProperty->getValue();
        $publishGroups = $publishGroupsProperty->getValue();

        foreach ($publishes as $provider => $paths) {
            $publishes[$provider] = $this->expandPublishPaths($paths);
        }

        foreach ($publishGroups as $group => $paths) {
            $publishGroups[$group] = $this->expandPublishPaths($paths);
        }

        $publishesProperty->setValue(null, $publishes);
        $publishGroupsProperty->setValue(null, $publishGroups);
    }

    protected function expandPublishPaths(array $paths): array
    {
        $expanded = [];

        foreach ($paths as $from => $to) {
            if (is_dir($from)) {
                $files = $this->collectPublishFiles($from, $to);
                if ($files !== []) {
                    $expanded = array_merge($expanded, $files);
                    continue;
                }
            }
            $expanded[$from] = $to;
        }

        return $expanded;
    }

    protected function collectPublishFiles(string $sourceDir, string $targetDir): array
    {
        if (!is_dir($sourceDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $targetDir = rtrim($targetDir, DIRECTORY_SEPARATOR);

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen($sourceDir) + 1);
            $files[$path] = $targetDir . DIRECTORY_SEPARATOR . $relative;
        }

        return $files;
    }

    protected function resolveLangVendorPath(string $package): string
    {
        $base = base_path('lang/vendor');
        if (!is_dir($base)) {
            $base = base_path('resources/lang/vendor');
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $package;
    }
}
