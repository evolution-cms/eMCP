<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Api\Routes;

use EvolutionCMS\eMCP\Http\Controllers\McpDispatchController;
use EvolutionCMS\eMCP\Http\Controllers\McpManagerController;
use EvolutionCMS\eMCP\Support\TransportError;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Seiger\sApi\Contracts\RouteProviderInterface;

class McpRouteProvider implements RouteProviderInterface
{
    public function register(Router $router): void
    {
        if (!(bool)config('cms.settings.eMCP.enable', true)) {
            return;
        }

        if (!(bool)config('cms.settings.eMCP.mode.api', true)) {
            return;
        }

        $prefix = trim((string)config('cms.settings.eMCP.route.api_prefix', 'mcp'), '/');

        $group = $router->middleware([
            'emcp.jwt',
            'emcp.scope',
            'emcp.actor',
            'emcp.rate',
        ]);

        if ($prefix !== '') {
            $group = $group->prefix($prefix);
        }

        $group->group(function () use ($router): void {
            $router->get('/{server}', function (Request $request) {
                return TransportError::response($request, 405, 'method_not_allowed', 'Method not allowed');
            })->withoutMiddleware(['sapi.jwt']);

            $router->post('/{server}', McpManagerController::class)
                ->withoutMiddleware(['sapi.jwt']);

            $router->post('/{server}/dispatch', McpDispatchController::class)
                ->withoutMiddleware(['sapi.jwt']);
        });
    }
}
