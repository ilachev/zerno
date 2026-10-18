<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Error\Error;
use App\Platform\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    #[DataProvider('provideErrorReturnsAJsonObjectWithTheRequestedStatusCases')]
    public function testErrorReturnsAJsonObjectWithTheRequestedStatus(Error $error, int $status): void
    {
        $response = (new JsonResponse())->error($error, $status);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['error' => $error->text()],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{Error, int}>
     */
    public static function provideErrorReturnsAJsonObjectWithTheRequestedStatusCases(): iterable
    {
        yield 'not found' => [Error::NOT_FOUND, 404];
        yield 'method not allowed' => [Error::METHOD_NOT_ALLOWED, 405];
        yield 'internal error' => [Error::INTERNAL_ERROR, 500];
    }

    public function testSuccessPreservesAlreadyEncodedJsonAndDefaultsToOk(): void
    {
        $data = "{\n    \"status\": \"ok\"\n}\n";
        $response = (new JsonResponse())->success($data);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($data, (string) $response->getBody());
    }

    public function testSuccessPreservesTheRequestedStatus(): void
    {
        $data = '{"accepted":true}';
        $response = (new JsonResponse())->success($data, 202);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($data, (string) $response->getBody());
    }
}
