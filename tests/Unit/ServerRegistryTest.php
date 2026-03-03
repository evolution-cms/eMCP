<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    if (!class_exists(Log::class, false)) {
        final class Log
        {
            /** @var array<int, string> */
            public static array $warnings = [];

            public static function warning(string $message): void
            {
                self::$warnings[] = $message;
            }
        }
    }
}

namespace Illuminate\Support {
    if (!class_exists(Str::class, false)) {
        final class Str
        {
            public static function kebab(string $value): string
            {
                $value = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;
                return strtolower(str_replace('_', '-', $value));
            }

            public static function contains(string $haystack, string $needle): bool
            {
                return str_contains($haystack, $needle);
            }

            public static function is(string $pattern, string $value): bool
            {
                if ($pattern === '*') {
                    return true;
                }

                $quoted = preg_quote($pattern, '/');
                $regex = '/^' . str_replace('\\*', '.*', $quoted) . '$/u';

                return (bool)preg_match($regex, $value);
            }
        }
    }
}

namespace Laravel\Mcp {
    if (!class_exists(Server::class, false)) {
        abstract class Server
        {
        }
    }
}

namespace Laravel\Mcp\Server {
    if (!class_exists(Tool::class, false)) {
        abstract class Tool
        {
        }
    }
}

namespace Laravel\Mcp\Server\Attributes {
    if (!class_exists(Name::class, false)) {
        #[\Attribute(\Attribute::TARGET_CLASS)]
        final class Name
        {
            public function __construct(public string $value)
            {
            }
        }
    }
}

namespace Laravel\Mcp\Facades {
    if (!class_exists(Mcp::class, false)) {
        final class Mcp
        {
            /** @var array<int, array{handle:string,class:string}> */
            public static array $locals = [];

            public static function local(string $handle, string $class): void
            {
                self::$locals[] = ['handle' => $handle, 'class' => $class];
            }
        }
    }
}

namespace EvolutionCMS\eMCP\Tests\Stubs {
    use Laravel\Mcp\Server;
    use Laravel\Mcp\Server\Attributes\Name;
    use Laravel\Mcp\Server\Tool;

    #[Name('evo.content.search')]
    final class EvoContentSearchTool extends Tool
    {
    }

    #[Name('vendor.content.read')]
    final class VendorContentReadTool extends Tool
    {
    }

    final class CoreServer extends Server
    {
        /** @var array<int, class-string<Tool>> */
        protected array $tools = [EvoContentSearchTool::class];
    }

    final class CoreDuplicateServer extends Server
    {
        /** @var array<int, class-string<Tool>> */
        protected array $tools = [EvoContentSearchTool::class];
    }

    final class LocalServer extends Server
    {
        /** @var array<int, class-string<Tool>> */
        protected array $tools = [VendorContentReadTool::class];
    }
}

namespace Vendor\External\Stubs {
    use Laravel\Mcp\Server;
    use Laravel\Mcp\Server\Attributes\Name;
    use Laravel\Mcp\Server\Tool;

    #[Name('evo.content.bad')]
    final class ExternalEvoTool extends Tool
    {
    }

    final class ExternalEvoServer extends Server
    {
        /** @var array<int, class-string<Tool>> */
        protected array $tools = [ExternalEvoTool::class];
    }
}

namespace {
    use EvolutionCMS\eMCP\Services\ServerRegistry;
    use EvolutionCMS\eMCP\Tests\Stubs\CoreDuplicateServer;
    use EvolutionCMS\eMCP\Tests\Stubs\CoreServer;
    use EvolutionCMS\eMCP\Tests\Stubs\LocalServer;
    use Illuminate\Support\Facades\Log;
    use Laravel\Mcp\Facades\Mcp;
    use Vendor\External\Stubs\ExternalEvoServer;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function assertContainsText(string $haystack, string $needle, string $message): void
    {
        assertTrue(str_contains($haystack, $needle), $message . " [missing: {$needle}]");
    }

    if (!function_exists('class_basename')) {
        function class_basename(string $class): string
        {
            $class = trim($class, '\\');
            $parts = explode('\\', $class);

            return (string)end($parts);
        }
    }

    /** @var array<string, mixed> $config */
    $config = [
        'app.debug' => false,
        'mcp.servers' => [],
    ];

    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            global $config;

            return $config[$key] ?? $default;
        }
    }

    require_once __DIR__ . '/../../src/Services/ServerRegistry.php';

    Log::$warnings = [];
    $config['mcp.servers'] = [
        ['enabled' => true, 'handle' => 'content', 'class' => CoreServer::class, 'transport' => 'web'],
        ['enabled' => false, 'handle' => 'disabled', 'class' => LocalServer::class, 'transport' => 'local'],
    ];

    $registry = new ServerRegistry();
    $resolved = $registry->allEnabled();
    assertTrue(isset($resolved['content']), 'Enabled content server must be registered');
    assertTrue(!isset($resolved['disabled']), 'Disabled server must be ignored');
    assertTrue($registry->handles() === ['content'], 'handles() should expose only enabled handle');
    assertTrue(
        $registry->resolveWebServerClassByHandle('content') === CoreServer::class,
        'resolveWebServerClassByHandle must resolve web server class'
    );
    assertTrue($registry->resolveWebServerClassByHandle('missing') === null, 'Unknown handle should return null');
    assertTrue(Log::$warnings === [], 'Valid config should not emit warnings');

    Log::$warnings = [];
    $config['mcp.servers'] = [
        ['enabled' => true, 'handle' => 'dup', 'class' => CoreServer::class, 'transport' => 'web'],
        ['enabled' => true, 'handle' => 'dup', 'class' => LocalServer::class, 'transport' => 'local'],
    ];
    $registry = new ServerRegistry();
    $resolved = $registry->allEnabled();
    assertTrue(count($resolved) === 1, 'Duplicate handle must not register second server');
    assertContainsText(implode("\n", Log::$warnings), 'Duplicate server handle', 'Duplicate handle warning must be logged');

    Log::$warnings = [];
    $config['mcp.servers'] = [
        ['enabled' => true, 'handle' => 'content', 'class' => CoreServer::class, 'transport' => 'web'],
        ['enabled' => true, 'handle' => 'content-dup', 'class' => CoreDuplicateServer::class, 'transport' => 'web'],
    ];
    $registry = new ServerRegistry();
    $resolved = $registry->allEnabled();
    assertTrue(isset($resolved['content']), 'Primary server should stay registered');
    assertTrue(!isset($resolved['content-dup']), 'Duplicate tool-name server must be rejected');
    assertContainsText(implode("\n", Log::$warnings), 'Duplicate tool name', 'Duplicate tool warning must be logged');

    Log::$warnings = [];
    $config['mcp.servers'] = [
        ['enabled' => true, 'handle' => 'external', 'class' => ExternalEvoServer::class, 'transport' => 'web'],
    ];
    $registry = new ServerRegistry();
    $resolved = $registry->allEnabled();
    assertTrue($resolved === [], 'External server with evo.* tool namespace must be rejected');
    assertContainsText(implode("\n", Log::$warnings), 'Namespace violation', 'Namespace violation warning must be logged');

    Log::$warnings = [];
    Mcp::$locals = [];
    $config['mcp.servers'] = [
        ['enabled' => true, 'handle' => 'content', 'class' => CoreServer::class, 'transport' => 'web'],
        ['enabled' => true, 'handle' => 'content-local', 'class' => LocalServer::class, 'transport' => 'local'],
    ];
    $registry = new ServerRegistry();
    $registry->registerLocalServers();
    assertTrue(count(Mcp::$locals) === 1, 'registerLocalServers must register only local transport entries');
    assertTrue(Mcp::$locals[0]['handle'] === 'content-local', 'Local transport handle must be registered');

    echo "ServerRegistry unit checks passed.\n";
}
