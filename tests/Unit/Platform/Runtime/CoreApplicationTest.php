<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Runtime\App;
use App\Platform\Storage\Storage;
use App\Platform\Storage\StorageException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CoreApplicationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExplicitRoutesDoNotLoadOptionalIntegrations(): void
    {
        $symbolsBefore = [...get_declared_classes(), ...get_declared_interfaces()];
        $guard = static function (string $class): void {
            if (self::isOptionalIntegration($class)) {
                throw new \RuntimeException('Core requested an optional integration: ' . $class);
            }
        };
        spl_autoload_register($guard, true, true);

        try {
            $app = new App($this->configPath());
            $response = $app->handle(new ServerRequest('GET', '/health', ['X-Request-ID' => 'core-request']));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame('{"status":"ok"}', (string) $response->getBody());
            self::assertSame('core-request', $response->getHeaderLine('X-Request-ID'));
            self::assertFalse($response->hasHeader('Set-Cookie'));

            $head = $app->handle(new ServerRequest('HEAD', '/health'));
            self::assertSame(200, $head->getStatusCode());
            self::assertSame('application/json', $head->getHeaderLine('Content-Type'));
            self::assertFalse($head->hasHeader('Set-Cookie'));

            foreach (['/missing', '/api/v1/health'] as $path) {
                $missing = $app->handle(new ServerRequest('GET', $path));
                self::assertSame(404, $missing->getStatusCode());
                self::assertSame('application/json', $missing->getHeaderLine('Content-Type'));
                self::assertSame(['error' => 'Not found'], json_decode((string) $missing->getBody(), true, 512, JSON_THROW_ON_ERROR));
                self::assertFalse($missing->hasHeader('Set-Cookie'));
            }

            $newSymbols = array_diff([...get_declared_classes(), ...get_declared_interfaces()], $symbolsBefore);
            self::assertSame([], array_values(array_filter($newSymbols, self::isOptionalIntegration(...))));
        } finally {
            spl_autoload_unregister($guard);
        }
    }

    public function testStorageIsAvailableWithoutOpeningItForHttpRequests(): void
    {
        $previousEngine = getenv('STORAGE_ENGINE');
        putenv('STORAGE_ENGINE=core-test-unavailable');

        try {
            $app = new App($this->configPath());
            self::assertTrue($app->getContainer()->has(Storage::class));
            $response = $app->handle(new ServerRequest('GET', '/health'));
            self::assertSame(200, $response->getStatusCode());

            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('Unsupported storage engine: core-test-unavailable');
            $app->getContainer()->get(Storage::class);
        } finally {
            putenv($previousEngine === false ? 'STORAGE_ENGINE' : 'STORAGE_ENGINE=' . $previousEngine);
        }
    }

    private static function isOptionalIntegration(string $class): bool
    {
        foreach ([
            'Spiral\\',
            'Google\\',
            'IP2Location\\',
            'App\Capabilities\\',
            'App\Generated\\',
            'App\Platform\Cache\\',
            'App\Platform\Hydration\\',
            'App\Platform\Http\Endpoint\\',
            'App\Platform\Http\GeneratedOperationManifestProvider',
            'App\Platform\Routing\Generator\GeneratedOperationRouteProvider',
        ] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function configPath(): string
    {
        return \dirname(__DIR__, 4) . '/config/core.php';
    }
}
