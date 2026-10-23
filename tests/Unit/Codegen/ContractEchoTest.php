<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use App\Generated\Echo\EchoRequest;
use App\Generated\Echo\EchoSendHttp;
use App\Platform\Http\Body;
use App\Platform\Http\Request;
use Examples\ContractEcho\EchoEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 3) . '/examples/contract-echo/EchoEndpoint.php';

final class ContractEchoTest extends TestCase
{
    #[DataProvider('provideCompleteAdapterCases')]
    public function testCompleteAdapter(string $input, int $status, string $output): void
    {
        $body = Body::create($input);
        $handler = new EchoSendHttp(new EchoEndpoint(), 65_536);
        $response = $handler->handle(new Request('POST', '/api/echo', ['Content-Type' => 'application/json'], $body));

        try {
            self::assertSame($status, $response->getStatusCode());
            self::assertSame($output, (string) $response->getBody());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame('POST', $handler->route()->method);
            self::assertSame('/api/echo', $handler->route()->path);
            $this->expectException(\RuntimeException::class);
            $body->readLimited(1);
        } finally {
            $response->getBody()->close();
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function provideCompleteAdapterCases(): iterable
    {
        yield 'text' => ['{"message":"Привет"}', 200, '{"message":"Привет"}'];
        foreach (['{}', '{"message":null}', '{"message":""}'] as $input) {
            yield $input => [$input, 200, '{}'];
        }
        yield 'duplicates' => ['{"message":"first","message":"last"}', 200, '{"message":"last"}'];
        foreach (['', '{', '[]', 'null', '"string"', '{"message":1}', '{"message":[]}', '{"unknown":null}', "{\"message\":\"\xFF\"}"] as $input) {
            yield 'invalid ' . bin2hex($input) => [$input, 400, '{"error":"Invalid JSON request"}'];
        }
        yield 'exact limit' => ['{"message":"' . str_repeat('a', 65_522) . '"}', 200, '{"message":"' . str_repeat('a', 65_522) . '"}'];
        yield 'too large' => [str_repeat(' ', 65_537), 413, '{"error":"Request body too large"}'];
    }

    public function testMediaAndMethodErrorsCloseUnreadBodies(): void
    {
        $handler = new EchoSendHttp(new EchoEndpoint(), 1);
        foreach ([new Request('GET', '/api/echo'), new Request('POST', '/api/echo', ['Content-Type' => 'text/plain'], '{}')] as $index => $request) {
            $response = $handler->handle($request);
            self::assertSame($index === 0 ? 405 : 415, $response->getStatusCode());
            self::assertFalse($request->getBody()->isReadable());
            $response->getBody()->close();
        }
    }

    public function testInvalidOutputIsNotReportedAsBadClientInput(): void
    {
        $this->expectException(\JsonException::class);
        new EchoEndpoint()->handle(new EchoRequest("\xFF"))->toJson();
    }

    public function testIncompatibleImplementationCannotLoad(): void
    {
        $source = 'require ' . var_export(\dirname(__DIR__, 3) . '/bootstrap.php', true) . '; '
            . 'final class InvalidEndpoint implements App\Generated\Echo\EchoSendEndpoint {'
            . 'public function handle(string $request): App\Generated\Echo\EchoResponse { throw new RuntimeException(); }}';
        [$exit, $stdout, $stderr] = $this->php($source);
        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('must be compatible with', $stderr);
    }

    public function testHealthDoesNotLoadDtoCodecStorageOrGenerator(): void
    {
        $root = var_export(\dirname(__DIR__, 3), true);
        $source = 'chdir(' . $root . '); ' . <<<'PHP'
            require 'bootstrap.php';
            $compose = require 'examples/contract-echo/config.php';
            $app = $compose(new App\Platform\Logging\ReadableOutputLogger());
            $response = $app->handle(new App\Platform\Http\Request('GET', '/health'));
            echo json_encode([
                $response->getStatusCode(),
                class_exists(App\Generated\Echo\EchoRequest::class, false),
                class_exists(Google\Protobuf\Internal\Message::class, false),
                class_exists(App\Platform\Storage\PostgreSQLStorage::class, false),
                class_exists(Project\ProtoGen\Generator::class, false),
            ], JSON_THROW_ON_ERROR);
            PHP;
        [$exit, $stdout, $stderr] = $this->php($source);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertSame('[200,false,false,false,false]', $stdout);
    }

    /** @return array{int, string, string} */
    private function php(string $source): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $source], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [$exit, $stdout, $stderr];
    }
}
