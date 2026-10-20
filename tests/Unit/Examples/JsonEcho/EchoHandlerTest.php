<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\JsonEcho;

use App\Platform\Http\Body;
use App\Platform\Http\Request;
use App\Platform\Logging\Logger;
use App\Platform\Runtime\App;
use Examples\JsonEcho\EchoHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ObservedStream;
use Tests\Unit\Platform\Logging\TestLogger;

require_once \dirname(__DIR__, 4) . '/examples/json-echo/EchoHandler.php';

final class EchoHandlerTest extends TestCase
{
    #[DataProvider('provideValidatesOneJsonOperationCases')]
    public function testValidatesOneJsonOperation(string $json, string $type, int $limit, int $status): void
    {
        $body = Body::create($json);
        $response = new EchoHandler($limit)->handle(new Request('POST', '/echo', ['Content-Type' => $type, 'Content-Length' => '0'], $body));

        try {
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertFalse($body->isReadable());
            if ($status === 200) {
                self::assertSame(json_decode($json, true, 32, JSON_THROW_ON_ERROR), json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR));
            } else {
                $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
                self::assertIsArray($data);
                self::assertIsString($data['error'] ?? null);
            }
        } finally {
            $response->getBody()->close();
        }
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function provideValidatesOneJsonOperationCases(): iterable
    {
        yield 'valid' => ['{"message":"hello"}', 'application/json', 64, 200];
        yield 'unicode' => ['{"message":"Привет"}', 'application/json', 64, 200];
        yield 'empty message' => ['{"message":""}', 'application/json', 64, 200];
        yield 'parameters and casing' => ['{"message":"hello"}', 'Application/JSON; charset=UTF-8', 64, 200];
        yield 'missing type' => ['{"message":"hello"}', '', 64, 415];
        yield 'wrong type before oversized body' => [str_repeat('x', 128), 'text/plain', 64, 415];
        yield 'invalid syntax' => ['{"message":', 'application/json', 64, 400];
        yield 'invalid UTF8' => ["{\"message\":\"\xff\"}", 'application/json', 64, 400];
        yield 'empty body' => ['', 'application/json', 64, 400];
        yield 'null root' => ['null', 'application/json', 64, 400];
        yield 'array root' => ['[]', 'application/json', 64, 400];
        yield 'scalar root' => ['"message"', 'application/json', 64, 400];
        yield 'missing field' => ['{}', 'application/json', 64, 400];
        yield 'null field' => ['{"message":null}', 'application/json', 64, 400];
        yield 'wrong field type' => ['{"message":123}', 'application/json', 64, 400];
        yield 'unknown field' => ['{"message":"hello","extra":true}', 'application/json', 64, 400];
        yield 'oversized before decode' => [str_repeat('x', 65), 'application/json', 64, 413];
        yield 'zero limit' => ['{}', 'application/json', 0, 413];
    }

    public function testClosesUnsupportedInputWithoutReadingIt(): void
    {
        $observed = new ObservedStream('unread');
        $body = $observed->open();
        $response = new EchoHandler(64)->handle(new Request('POST', '/echo', ['Content-Type' => 'text/plain'], $body));
        self::assertSame(415, $response->getStatusCode());
        self::assertSame(0, $observed->reads);
        self::assertSame(0, $observed->seeks);
        self::assertSame(1, $observed->closes);
    }

    public function testIoFailureClosesInputAndProducesGeneric500ThroughTheApplication(): void
    {
        $observed = new ObservedStream('payload', failRead: true);
        $logger = new TestLogger();
        /** @var \Closure(Logger): App $compose */
        $compose = require \dirname(__DIR__, 4) . '/examples/json-echo/config.php';
        $app = $compose($logger);
        $response = $app->handle(new Request('POST', '/echo', ['Content-Type' => 'application/json'], $observed->open()));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"error":"Internal Server Error"}', (string) $response->getBody());
        self::assertSame(1, $observed->closes);
        self::assertCount(1, $logger->logs);
        $next = $app->handle(new Request('POST', '/echo', ['Content-Type' => 'application/json'], '{"message":"next"}'));
        self::assertSame(200, $next->getStatusCode());
        self::assertSame('{"message":"next"}', (string) $next->getBody());
    }

    public function testRejectsAmbiguousContentTypeBeforeReading(): void
    {
        $observed = new ObservedStream('{"message":"hello"}');
        $response = new EchoHandler(64)->handle(new Request('POST', '/echo', ['Content-Type' => ['application/json', 'application/json']], $observed->open()));
        self::assertSame(415, $response->getStatusCode());
        self::assertSame(0, $observed->reads);
        self::assertSame(1, $observed->closes);
    }
}
