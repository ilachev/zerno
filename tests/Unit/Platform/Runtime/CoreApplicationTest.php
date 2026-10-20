<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Runtime\App;
use App\Platform\Storage\PostgreSqlStorageConfig;
use App\Platform\Storage\Storage;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CoreApplicationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExplicitRoutesDoNotLoadNonHttpServices(): void
    {
        $symbolsBefore = [...get_declared_classes(), ...get_declared_interfaces()];
        $guard = static function (string $class): void {
            if (self::isOutsideHttpCore($class)) {
                throw new \RuntimeException('Core requested a service outside HTTP core: ' . $class);
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
            self::assertSame([], array_values(array_filter($newSymbols, self::isOutsideHttpCore(...))));
        } finally {
            spl_autoload_unregister($guard);
        }
    }

    public function testStorageIsAvailableWithoutOpeningItForHttpRequests(): void
    {
        $app = new App($this->configPath());
        $app->getContainer()->set(PostgreSqlStorageConfig::class, static function (): PostgreSqlStorageConfig {
            throw new \LogicException('Database configuration was resolved.');
        });
        self::assertTrue($app->getContainer()->has(Storage::class));
        self::assertSame(200, $app->handle(new ServerRequest('GET', '/health'))->getStatusCode());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Database configuration was resolved.');
        $app->getContainer()->get(Storage::class);
    }

    private static function isOutsideHttpCore(string $class): bool
    {
        foreach ([
            'Spiral\\',
            'Google\\',
            'App\Generated\\',
            'Nette\\',
            'Phplrt\\',
            'Typhoon\\',
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
