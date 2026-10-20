<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\DocumentCatalog;

use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Logging\Logger;
use App\Platform\Runtime\App;
use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\Storage;
use Examples\DocumentCatalog\Errors;
use Examples\DocumentCatalog\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SQLiteStorage;
use Tests\Unit\Platform\Logging\TestLogger;

final class CompositionTest extends TestCase
{
    #[DataProvider('provideRequestsWithoutStorageCases')]
    public function testRequestsWithoutStorage(string $method, string $path, int $status, string $contentType): void
    {
        /** @var \Closure(Logger, Storage, string): App $compose */
        $compose = require \dirname(__DIR__, 4) . '/examples/document-catalog/application.php';
        $storage = new PostgreSQLStorage('/zerno-no-socket-' . bin2hex(random_bytes(12)), 5432, 'unused', 'unused', 'unused');
        $app = $compose(new TestLogger(), $storage, '/unused');
        $response = $app->handle(new Request($method, $path));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function provideRequestsWithoutStorageCases(): iterable
    {
        yield 'health' => ['GET', '/health', 200, 'application/json'];
        yield 'head health' => ['HEAD', '/health', 200, 'application/json'];
        yield 'unknown route' => ['GET', '/unknown', 404, 'text/html; charset=utf-8'];
        yield 'head unknown route' => ['HEAD', '/unknown', 404, 'text/html; charset=utf-8'];
        yield 'unsupported method' => ['POST', '/documents', 405, 'text/html; charset=utf-8'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHtmlErrorsUseTheCorePipelineWithoutLoadingJsonResponse(): void
    {
        $guard = static function (string $class): void {
            if ($class === 'App\Platform\Http\JsonResponse') {
                throw new \LogicException('HTML errors must not construct JSON responses.');
            }
        };
        spl_autoload_register($guard, true, true);

        try {
            /** @var \Closure(Logger, Storage, string): App $compose */
            $compose = require \dirname(__DIR__, 4) . '/examples/document-catalog/application.php';
            $logger = new TestLogger();
            $handler = $compose($logger, new SQLiteStorage(':memory:'), '/unused');

            foreach ([['GET', '/unknown', 404], ['POST', '/documents', 405], ['GET', '/documents', 500], ['GET', '/health', 200], ['GET', '/unknown', 404]] as $index => [$method, $path, $status]) {
                $response = $handler->handle(new Request($method, $path, ['X-Request-ID' => 'catalog-error-' . $index]));

                self::assertSame($status, $response->getStatusCode());
                self::assertSame('catalog-error-' . $index, $response->getHeaderLine('X-Request-ID'));
                self::assertSame($status === 405 ? 'GET, HEAD' : '', $response->getHeaderLine('Allow'));
                self::assertFalse($response->hasHeader('Set-Cookie'));

                if ($status === 200) {
                    self::assertSame('{"status":"ok"}', (string) $response->getBody());
                } else {
                    self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
                    self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
                    self::assertStringContainsString('<h1>', (string) $response->getBody());
                    self::assertStringNotContainsString('catalog_documents', (string) $response->getBody());
                }
            }

            $errors = array_values(array_filter($logger->logs, static fn(array $log): bool => $log['level'] === 'error'));
            self::assertCount(1, $errors);
            self::assertStringContainsString('catalog_documents', $errors[0]['message']);

            $expected = new Response(404, ['Content-Type' => 'application/json', 'X-Application' => 'kept'], '{"application":"not found"}');
            $application = new readonly class ($expected) implements RequestHandler {
                public function __construct(private Response $response) {}

                public function handle(Request $request): Response
                {
                    return $this->response;
                }
            };
            self::assertSame($expected, new ErrorHandlerMiddleware(new Errors(new Html()), $logger)->process(new Request('GET', '/application'), $application));
        } finally {
            spl_autoload_unregister($guard);
        }
    }
}
