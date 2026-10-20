<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\Body;
use App\Platform\Http\UploadedFile;
use App\Platform\Runtime\SapiRequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SapiRequestFactoryTest extends TestCase
{
    public function testCreatesRequestWithNativeSapiDataAndIgnoresForwardedAuthority(): void
    {
        $body = Body::create('name=Alice');
        $request = (new SapiRequestFactory())->create(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/form?tag=a%20b',
                'SERVER_PROTOCOL' => 'HTTP/2.0',
                'HTTP_HOST' => '[2001:db8::1]:8443',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '192.0.2.1',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'CONTENT_LENGTH' => '10',
                'HTTP_AUTHORIZATION' => 'Bearer session-id',
                'HTTP_X_FORWARDED_HOST' => 'untrusted.test',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_FORWARDED' => 'host=other.test;proto=http',
            ],
            query: ['tag' => 'a b'],
            post: ['name' => 'Alice'],
            cookies: ['session' => 'cookie-id'],
            body: $body,
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://[2001:db8::1]:8443/form?tag=a%20b', (string) $request->getUri());
        self::assertSame('/form?tag=a%20b', $request->getRequestTarget());
        self::assertSame('2', $request->getProtocolVersion());
        self::assertSame('[2001:db8::1]:8443', $request->getHeaderLine('Host'));
        self::assertSame('Bearer session-id', $request->getHeaderLine('Authorization'));
        self::assertSame('10', $request->getHeaderLine('Content-Length'));
        self::assertSame(['tag' => 'a b'], $request->getQueryParams());
        self::assertSame(['name' => 'Alice'], $request->getParsedBody());
        self::assertSame(['session' => 'cookie-id'], $request->getCookieParams());
        self::assertSame('192.0.2.1', $request->getServerParams()['REMOTE_ADDR']);
        self::assertSame($body, $request->getBody());
        self::assertSame('name=Alice', (string) $request->getBody());
    }

    public function testPreservesDoubleSlashInOriginFormPath(): void
    {
        $request = (new SapiRequestFactory())->create([
            'HTTP_HOST' => 'origin.test',
            'REQUEST_URI' => '//other.test/path?query=1',
        ]);

        self::assertSame('origin.test', $request->getUri()->getHost());
        self::assertSame('http://origin.test//other.test/path?query=1', (string) $request->getUri());
        self::assertSame('//other.test/path?query=1', $request->getRequestTarget());
    }

    public function testUsesServerAuthorityWhenHostIsMissing(): void
    {
        $request = (new SapiRequestFactory())->create([
            'SERVER_NAME' => '2001:db8::2',
            'SERVER_PORT' => 8080,
            'REQUEST_URI' => '/health',
        ]);

        self::assertSame('http://[2001:db8::2]:8080/health', (string) $request->getUri());
        self::assertSame('[2001:db8::2]:8080', $request->getHeaderLine('Host'));
    }

    public function testSupportsOptionsAsterisk(): void
    {
        $request = (new SapiRequestFactory())->create([
            'HTTP_HOST' => 'example.test',
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '*',
        ]);

        self::assertSame('*', $request->getRequestTarget());
        self::assertSame('example.test', $request->getUri()->getHost());
        self::assertSame('', $request->getUri()->getQuery());
    }

    #[DataProvider('provideRejectsMalformedHostInsteadOfInventingAnAuthorityCases')]
    public function testRejectsMalformedHostInsteadOfInventingAnAuthority(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SapiRequestFactory())->create(['HTTP_HOST' => $host]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideRejectsMalformedHostInsteadOfInventingAnAuthorityCases(): iterable
    {
        yield 'empty' => [''];
        yield 'userinfo' => ['user@example.test'];
        yield 'path' => ['example.test/path'];
        yield 'query' => ['example.test?query=1'];
        yield 'fragment' => ['example.test#fragment'];
        yield 'space' => ['example.test other.test'];
        yield 'control characters' => ["example.test\r\nInjected: yes"];
        yield 'invalid port' => ['example.test:invalid'];
        yield 'empty port' => ['example.test:'];
        yield 'port out of range' => ['example.test:65536'];
        yield 'unbracketed IPv6' => ['2001:db8::1'];
        yield 'invalid IPv6' => ['[invalid::address]'];
        yield 'missing bracket' => ['[::1'];
    }

    #[DataProvider('provideRejectsInvalidOriginFormTargetsCases')]
    public function testRejectsInvalidOriginFormTargets(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SapiRequestFactory())->create(['HTTP_HOST' => 'example.test', 'REQUEST_URI' => $target]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideRejectsInvalidOriginFormTargetsCases(): iterable
    {
        yield 'absolute form' => ['http://other.test/path'];
        yield 'relative form' => ['relative/path'];
        yield 'fragment' => ['/path#fragment'];
        yield 'space' => ['/path with space'];
        yield 'control characters' => ["/path\nnext"];
        yield 'invalid escape' => ['/path%GG'];
        yield 'backslash' => ['/path\next'];
        yield 'asterisk outside OPTIONS' => ['*'];
    }

    #[DataProvider('provideUsesSapiParsedBodyOnlyForPostFormsCases')]
    public function testUsesSapiParsedBodyOnlyForPostForms(string $method, string $contentType, bool $parsed): void
    {
        $request = (new SapiRequestFactory())->create(
            ['HTTP_HOST' => 'example.test', 'REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $contentType],
            post: ['field' => 'value'],
            body: Body::create('{"field":"value"}'),
        );

        self::assertSame($parsed ? ['field' => 'value'] : null, $request->getParsedBody());
        self::assertSame('{"field":"value"}', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function provideUsesSapiParsedBodyOnlyForPostFormsCases(): iterable
    {
        yield 'urlencoded POST' => ['POST', 'application/x-www-form-urlencoded', true];
        yield 'multipart POST' => ['POST', 'Multipart/Form-Data; boundary=example', true];
        yield 'JSON POST' => ['POST', 'application/json', false];
        yield 'urlencoded PUT' => ['PUT', 'application/x-www-form-urlencoded', false];
        yield 'GET' => ['GET', 'application/x-www-form-urlencoded', false];
    }

    public function testPreservesBasicAuthenticationProvidedBySapi(): void
    {
        $request = (new SapiRequestFactory())->create([
            'HTTP_HOST' => 'example.test',
            'PHP_AUTH_USER' => 'alice',
            'PHP_AUTH_PW' => 'secret',
        ]);

        self::assertSame('Basic ' . base64_encode('alice:secret'), $request->getHeaderLine('Authorization'));
    }

    public function testNormalizesNestedUploadsAndPreservesUploadErrors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sapi-upload-');
        self::assertIsString($path);
        file_put_contents($path, 'uploaded');

        try {
            $request = (new SapiRequestFactory())->create(
                ['HTTP_HOST' => 'example.test'],
                files: [
                    'documents' => [
                        'name' => ['files' => ['first.txt', '']],
                        'type' => ['files' => ['text/plain', '']],
                        'tmp_name' => ['files' => [$path, '']],
                        'error' => ['files' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE]],
                        'size' => ['files' => [8, 0]],
                    ],
                ],
            );

            $documents = $request->getUploadedFiles()['documents'];
            self::assertIsArray($documents);
            $files = $documents['files'];
            self::assertIsArray($files);
            $first = $files[0];
            $missing = $files[1];
            self::assertInstanceOf(UploadedFile::class, $first);
            self::assertInstanceOf(UploadedFile::class, $missing);
            self::assertSame('first.txt', $first->getClientFilename());
            self::assertSame('text/plain', $first->getClientMediaType());
            self::assertSame(8, $first->getSize());
            self::assertSame('uploaded', (string) $first->getStream());
            self::assertSame(UPLOAD_ERR_NO_FILE, $missing->getError());
        } finally {
            unlink($path);
        }
    }

    public function testRejectsInconsistentUploadArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SapiRequestFactory())->create(
            ['HTTP_HOST' => 'example.test'],
            files: ['documents' => ['error' => [UPLOAD_ERR_OK], 'name' => []]],
        );
    }

    public function testReadsGlobalsWithoutReplacingThem(): void
    {
        $originals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];

        try {
            $_SERVER = [
                'HTTP_HOST' => 'example.test',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/form?query=value',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ];
            $_GET = ['query' => 'value'];
            $_POST = ['form' => 'value'];
            $_COOKIE = ['session' => 'cookie'];
            $_FILES = [];

            $request = (new SapiRequestFactory())->fromGlobals();

            self::assertSame($_SERVER, $request->getServerParams());
            self::assertSame($_GET, $request->getQueryParams());
            self::assertSame($_POST, $request->getParsedBody());
            self::assertSame($_COOKIE, $request->getCookieParams());
            self::assertTrue($request->getBody()->isReadable());
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $originals;
        }
    }
}
