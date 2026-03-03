<?php

use EvolutionCMS\eMCP\Servers\ContentServer;

return [
    'redirect_domains' => ['*'],

    'servers' => [
        [
            'handle' => 'content',
            'transport' => 'web',
            'route' => '/mcp/content',
            'class' => ContentServer::class,
            'enabled' => true,
            'auth' => 'sapi_jwt',
            'scopes' => ['mcp:read', 'mcp:call'],
            'scope_map' => [
                'mcp:read' => ['initialize', 'tools/list', 'resources/read'],
                'mcp:call' => ['tools/call'],
            ],
            'limits' => [
                'max_payload_kb' => 128,
                'max_result_items' => 50,
            ],
            'rate_limit' => [
                'per_minute' => 30,
            ],
            'security' => [
                'deny_tools' => [],
            ],
        ],
        [
            'handle' => 'content-local',
            'transport' => 'local',
            'class' => ContentServer::class,
            // Disabled by default to avoid duplicate tool-name registration conflict with "content" web server.
            'enabled' => false,
        ],
    ],
];
