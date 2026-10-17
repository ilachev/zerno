<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\DocumentCatalog;

use App\Platform\Runtime\App;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompositionTest extends TestCase
{
    #[DataProvider('provideRequestsWithoutStorageCases')]
    public function testRequestsWithoutStorage(string $method, string $path, int $status, string $contentType): void
    {
        $previous = getenv('STORAGE_ENGINE');
        putenv('STORAGE_ENGINE=catalog-test-unavailable');

        try {
            $app = new App(\dirname(__DIR__, 4) . '/examples/document-catalog/config.php');
            $response = $app->handle(new ServerRequest($method, $path));

            self::assertSame($status, $response->getStatusCode());
            self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
            self::assertFalse($response->hasHeader('Set-Cookie'));
        } finally {
            putenv($previous === false ? 'STORAGE_ENGINE' : 'STORAGE_ENGINE=' . $previous);
        }
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function provideRequestsWithoutStorageCases(): iterable
    {
        yield 'health' => ['GET', '/health', 200, 'application/json'];
        yield 'head health' => ['HEAD', '/health', 200, 'application/json'];
        yield 'unknown route' => ['GET', '/unknown', 404, 'text/html; charset=utf-8'];
        yield 'unsupported method' => ['POST', '/documents', 405, 'text/html; charset=utf-8'];
    }
}
