<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Runtime\PhpRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpRuntimeTest extends TestCase
{
    #[DataProvider('provideHttpSecurityFloorCases')]
    public function testHttpSecurityFloor(string $version, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\RuntimeException::class);
        }
        PhpRuntime::assertSupported($version);
        self::assertTrue($accepted);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideHttpSecurityFloorCases(): iterable
    {
        yield 'old local runtime' => ['8.5.9', false];
        yield 'last unpatched' => ['8.5.11', false];
        yield 'prerelease is not the security release' => ['8.5.12RC1', false];
        yield 'security release' => ['8.5.12', true];
        yield 'next patch' => ['8.5.13', true];
    }
}
