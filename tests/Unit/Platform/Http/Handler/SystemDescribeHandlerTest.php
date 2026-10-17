<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Handler;

use App\Generated\Endpoint\Api\V1\SystemService\DescribeHttpHandler;
use App\Platform\Http\Endpoint\Api\V1\SystemService\DescribeEndpoint;
use App\Platform\Http\JsonResponse;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class SystemDescribeHandlerTest extends TestCase
{
    /**
     * @throws \JsonException
     */
    public function testHandleReturnsRuntimeDescriptionFromPostBody(): void
    {
        $handler = new DescribeHttpHandler(
            new DescribeEndpoint(),
            new JsonResponse(),
        );

        $request = new ServerRequest('POST', '/api/v1/system/describe');
        $request->getBody()->write(json_encode([
            'caller' => 'client',
            'requestedCapabilities' => ['sessions', 'metrics'],
            'includeRuntime' => true,
        ], JSON_THROW_ON_ERROR));

        $response = $handler->handle($request);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertIsArray($payload);
        self::assertSame('zerno', $payload['name'] ?? null);
        self::assertSame('core', $payload['mode'] ?? null);
        self::assertSame('client', $payload['caller'] ?? null);
        self::assertTrue($payload['runtimeIncluded'] ?? false);
        self::assertSame(
            ['sessions', 'metrics', 'http', 'protobuf', 'routing'],
            $payload['capabilities'] ?? null,
        );
        /** @var mixed $timestamp */
        $timestamp = $payload['timestamp'] ?? null;
        self::assertIsString($timestamp);
        self::assertGreaterThan(0, (int) $timestamp);
    }
}
