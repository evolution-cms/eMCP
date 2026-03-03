<?php

return [
    'enable' => true,
    'toolset_version' => '1.0',

    'mode' => [
        'internal' => true,
        'api' => true,
    ],

    'route' => [
        'manager_prefix' => 'emcp',
        'api_prefix' => 'mcp',
    ],

    'auth' => [
        'mode' => 'sapi_jwt',
        'require_scopes' => true,
        'scope_map' => [
            'mcp:read' => [
                'initialize',
                'ping',
                'tools/list',
                'resources/list',
                'resources/read',
                'prompts/list',
                'prompts/get',
                'completion/complete',
            ],
            'mcp:call' => ['tools/call'],
            'mcp:admin' => ['admin/*'],
        ],
    ],

    'acl' => [
        'permission' => 'emcp',
    ],

    'queue' => [
        'driver' => 'stask',
        'failover' => 'sync',
    ],

    'rate_limit' => [
        'enabled' => true,
        'per_minute' => 60,
    ],

    'limits' => [
        'max_payload_kb' => 256,
        'max_result_items' => 100,
        'max_result_bytes' => 1048576,
    ],

    'stream' => [
        'enabled' => false,
        'max_stream_seconds' => 120,
        'heartbeat_seconds' => 15,
        'abort_on_disconnect' => true,
    ],

    'logging' => [
        'channel' => 'emcp',
        'audit_enabled' => true,
        'redact_keys' => [
            'authorization',
            'token',
            'jwt',
            'secret',
            'cookie',
            'password',
            'api_key',
        ],
    ],

    'security' => [
        'allow_servers' => ['*'],
        'deny_tools' => [],
        'enable_write_tools' => false,
    ],

    'actor' => [
        'mode' => 'initiator',
        'service_username' => 'MCP',
        'service_role' => 'MCP',
        'block_login' => true,
    ],

    'trace' => [
        'header' => 'X-Trace-Id',
        'generate_if_missing' => true,
    ],

    'idempotency' => [
        'header' => 'Idempotency-Key',
        'ttl_seconds' => 86400,
        'storage' => 'stask_meta',
    ],

    'domain' => [
        'content' => [
            'max_depth' => 6,
            'max_limit' => 100,
            'max_offset' => 5000,
        ],
        'models' => [
            'max_offset' => 5000,
            'allow' => [
                'SiteTemplate',
                'SiteTmplvar',
                'SiteTmplvarContentvalue',
                'SiteSnippet',
                'SitePlugin',
                'SiteModule',
                'Category',
                'User',
                'UserAttribute',
                'UserRole',
                'Permissions',
                'PermissionsGroups',
                'RolePermissions',
            ],
        ],
    ],
];
