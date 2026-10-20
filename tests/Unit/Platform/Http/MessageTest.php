<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Http\Body;
use App\Platform\Http\Request;
use App\Platform\Http\Response;
use App\Platform\Http\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testHeadersAreCaseInsensitivePreserveValuesAndDoNotMutateTheOriginal(): void
    {
        $original = new Response(201, ['Set-Cookie' => ['first=1', 'second=2'], 'X-Value' => '  initial\t']);
        $changed = $original->withAddedHeader('set-cookie', 'third=3')->withHeader('x-value', 'changed');

        self::assertSame(['first=1', 'second=2'], $original->getHeader('SET-COOKIE'));
        self::assertSame(['first=1', 'second=2', 'third=3'], $changed->getHeaders()['Set-Cookie']);
        self::assertSame('changed', $changed->getHeaderLine('X-VALUE'));
        self::assertFalse($changed->withoutHeader('set-cookie')->hasHeader('SET-cookie'));
        self::assertTrue($original->hasHeader('set-cookie'));
    }

    public function testPreservesNumericHeaderNamesStoredAsIntegerArrayKeys(): void
    {
        $request = new Request('GET', '/', [123 => 'request']);
        $response = new Response(200, [123 => ['first', 'second']]);

        self::assertSame('request', $request->getHeaderLine('123'));
        self::assertSame(['first', 'second'], $response->getHeader('123'));
        self::assertSame(['first', 'second', 'third'], $response->withAddedHeader('123', 'third')->getHeaders()[123]);
        self::assertFalse($response->withoutHeader('123')->hasHeader('123'));
    }

    #[DataProvider('provideRejectsInvalidHeadersAtConstructionAndReplacementCases')]
    public function testRejectsInvalidHeadersAtConstructionAndReplacement(string $name, string $value): void
    {
        foreach ([true, false] as $construct) {
            try {
                if ($construct) {
                    new Response(200, [$name => $value]);
                } else {
                    new Response()->withHeader($name, $value);
                }
                self::fail('Invalid HTTP header must be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideRejectsInvalidHeadersAtConstructionAndReplacementCases(): iterable
    {
        yield 'empty name' => ['', 'value'];
        yield 'colon' => ['X:Header', 'value'];
        yield 'whitespace' => ['X Header', 'value'];
        yield 'name injection' => ["X-Header\r\nAnother", 'value'];
        yield 'value injection' => ['X-Header', "value\r\nAnother: yes"];
        yield 'nul' => ['X-Header', "value\0suffix"];
        yield 'control' => ['X-Header', "value\x01suffix"];
        yield 'delete' => ['X-Header', "value\x7fsuffix"];
    }

    public function testRequestCopiesKeepTheirOwnMetadataAndShareBodyOwnership(): void
    {
        $body = Body::create('payload');
        $original = new Request('POST', 'https://EXAMPLE.test:443/files/a%2Fb?tag=a%20b', ['X-Value' => 'first'], $body);
        $changed = $original->withAttribute('user', 42)->withAttribute('null', null)
            ->withQueryParams(['tag' => 'a b'])->withParsedBody(['name' => 'value'])
            ->withCookieParams(['cookie' => 'value'])->withHeader('x-value', 'second');

        self::assertNull($original->getAttribute('user'));
        self::assertSame(42, $changed->getAttribute('user'));
        self::assertNull($changed->getAttribute('null', 'fallback'));
        self::assertSame([], $original->getQueryParams());
        self::assertNull($original->getParsedBody());
        self::assertSame(['name' => 'value'], $changed->getParsedBody());
        self::assertSame(['cookie' => 'value'], $changed->getCookieParams());
        self::assertSame('first', $original->getHeaderLine('x-value'));
        self::assertSame('second', $changed->getHeaderLine('X-Value'));
        self::assertSame('example.test', $original->getHeaderLine('Host'));
        self::assertSame('/files/a%2Fb?tag=a%20b', $original->getRequestTarget());
        self::assertSame('/files/a%2Fb', $original->getUri()->getPath());
        self::assertSame('tag=a%20b', $original->getUri()->getQuery());
        self::assertSame($body, $changed->getBody());
        self::assertSame('pay', $changed->getBody()->read(3));
        self::assertSame(3, $original->getBody()->tell());
        $changed->getBody()->close();
        self::assertFalse($original->getBody()->isReadable());
    }

    #[DataProvider('provideRejectsInvalidRequestUrisCases')]
    public function testRejectsInvalidRequestUris(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri($uri);
    }

    /** @return iterable<string, array{string}> */
    public static function provideRejectsInvalidRequestUrisCases(): iterable
    {
        yield 'scheme' => ['file:///etc/passwd'];
        yield 'credentials' => ['http://user:pass@example.test/path'];
        yield 'fragment' => ['/path#fragment'];
        yield 'escape' => ['/path%ZZ'];
        yield 'space' => ['/path with space'];
        yield 'control' => ["/path\r\nnext"];
        yield 'backslash' => ['/path\next'];
    }

    public function testBodyReplacementDoesNotCloseTheSharedOriginal(): void
    {
        $body = Body::create('original');
        $original = new Response(200, [], $body);
        $changed = $original->withBody(Body::create('replacement'));

        self::assertTrue($body->isReadable());
        self::assertSame('original', (string) $original->getBody());
        self::assertSame('replacement', (string) $changed->getBody());
    }

    #[DataProvider('provideRejectsInvalidResponseStatusesCases')]
    public function testRejectsInvalidResponseStatuses(int $status): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response($status);
    }

    /** @return iterable<array{int}> */
    public static function provideRejectsInvalidResponseStatusesCases(): iterable
    {
        yield [99];
        yield [600];
    }

    public function testReadsStringBodyFromItsCurrentPositionAndClosesIdempotently(): void
    {
        $body = Body::create('abcdef');
        self::assertSame(6, $body->getSize());
        self::assertSame('ab', $body->read(2));
        self::assertSame('cdef', $body->getContents());
        self::assertTrue($body->eof());
        $body->seek(-2, SEEK_END);
        self::assertSame('ef', $body->getContents());
        self::assertSame('abcdef', (string) $body);
        $body->close();
        $body->close();
        self::assertNull($body->getSize());
        self::assertFalse($body->isSeekable());
        $this->expectException(\RuntimeException::class);
        $body->read(1);
    }
}
