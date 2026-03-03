<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\LaravelMcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Console\Commands\InspectorCommand;
use Laravel\Mcp\Console\Commands\MakePromptCommand;
use Laravel\Mcp\Console\Commands\MakeResourceCommand;
use Laravel\Mcp\Console\Commands\MakeServerCommand;
use Laravel\Mcp\Console\Commands\MakeToolCommand;
use Laravel\Mcp\Console\Commands\StartCommand;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Registrar;
use EvolutionCMS\eMCP\Services\ServerRegistry;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registrar::class, fn (): Registrar => new Registrar());
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/mcp.php', 'mcp');
    }

    public function boot(): void
    {
        $this->registerMcpScope();
        $this->registerRoutesFromRegistry();
        $this->registerContainerCallbacks();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }
    }

    protected function registerRoutesFromRegistry(): void
    {
        /** @var ServerRegistry $registry */
        $registry = $this->app->make(ServerRegistry::class);

        // Gate A: local transport from config + manager web routes from eMCP mgrRoutes.php.
        // Public API web routes are introduced by sApi integration in Gate B+.
        $registry->registerLocalServers();
    }

    protected function registerContainerCallbacks(): void
    {
        $this->app->resolving(Request::class, function (Request $request, $app): void {
            if ($app->bound('mcp.request')) {
                /** @var Request $currentRequest */
                $currentRequest = $app->make('mcp.request');

                $request->setArguments($currentRequest->all());
                $request->setSessionId($currentRequest->sessionId());
                $request->setMeta($currentRequest->meta());
            }
        });
    }

    protected function registerCommands(): void
    {
        $this->commands([
            StartCommand::class,
            MakeServerCommand::class,
            MakeToolCommand::class,
            MakePromptCommand::class,
            MakeResourceCommand::class,
            InspectorCommand::class,
        ]);
    }

    protected function registerPublishing(): void
    {
        $root = dirname(__DIR__, 2);

        $this->publishes([
            $root . '/stubs/mcp-prompt.stub' => base_path('stubs/mcp-prompt.stub'),
            $root . '/stubs/mcp-resource.stub' => base_path('stubs/mcp-resource.stub'),
            $root . '/stubs/mcp-server.stub' => base_path('stubs/mcp-server.stub'),
            $root . '/stubs/mcp-tool.stub' => base_path('stubs/mcp-tool.stub'),
        ], 'mcp-stubs');

        $this->publishes([
            $root . '/config/mcp.php' => $this->resolveConfigPath('mcp.php'),
        ], 'mcp-config');
    }

    protected function resolveConfigPath(string $path): string
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

    protected function registerMcpScope(): void
    {
        $this->app->booted(static function (): void {
            Registrar::ensureMcpScope();
        });
    }
}
