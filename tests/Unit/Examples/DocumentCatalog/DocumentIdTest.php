<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\DocumentCatalog;

use Examples\DocumentCatalog\DocumentId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentIdTest extends TestCase
{
    #[DataProvider('provideAcceptsCanonicalIdsCases')]
    public function testAcceptsCanonicalIds(int|string $value, int $expected): void
    {
        self::assertSame($expected, DocumentId::parse($value));
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function provideAcceptsCanonicalIdsCases(): iterable
    {
        yield 'integer' => [1, 1];
        yield 'decimal' => ['42', 42];
        yield 'maximum integer' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'maximum decimal' => [(string) PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('provideRejectsInvalidAndOverflowingIdsCases')]
    public function testRejectsInvalidAndOverflowingIds(mixed $value): void
    {
        self::assertNull(DocumentId::parse($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideRejectsInvalidAndOverflowingIdsCases(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'zero' => [0];
        yield 'decimal zero' => ['0'];
        yield 'negative' => [-1];
        yield 'negative decimal' => ['-1'];
        yield 'leading zero' => ['01'];
        yield 'sign' => ['+1'];
        yield 'whitespace' => [' 1'];
        yield 'line ending' => ["1\n"];
        yield 'float' => [1.0];
        yield 'decimal fraction' => ['1.0'];
        yield 'exponent' => ['1e2'];
        yield 'boolean' => [true];
        yield 'array' => [['1']];
        yield 'injection' => ['1 OR 1=1'];
        yield 'integer overflow' => [substr((string) PHP_INT_MAX, 0, -1) . '8'];
        yield 'very long decimal' => [str_repeat('9', 1000)];
    }
}
