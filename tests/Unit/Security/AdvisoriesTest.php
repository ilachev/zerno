<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\Security\Advisories;
use Project\Security\Inventory;
use Project\Security\Process;

final class AdvisoriesTest extends TestCase
{
    public function testDeliveredPhpInventoryCoversEveryRuntimeSourcePackage(): void
    {
        $root = \dirname(__DIR__, 3);
        $packages = new Inventory($root)->packages();
        $manifest = Inventory::read($root . '/third_party/runtime/manifest.json');
        self::assertIsArray($manifest['packages']);
        foreach ($manifest['packages'] as $entry) {
            self::assertIsArray($entry);
            $names = array_column(array_filter($packages, static fn(array $package): bool => $package['scope'] === 'runtime'), 'name');
            if (($entry['type'] ?? null) === 'metapackage') {
                self::assertNotContains($entry['name'], $names);
            } else {
                self::assertContains($entry['name'], $names);
            }
        }
        self::assertContains('phpunit/phpunit', array_column($packages, 'name'));
        self::assertContains('typhoon/type', array_column($packages, 'name'));
    }

    public function testFindingsRetainTheAffectedDistribution(): void
    {
        $packages = [['name' => 'example/library', 'version' => '1.0.0', 'ecosystem' => 'Packagist', 'scope' => 'runtime']];
        self::assertSame([['package' => 'example/library', 'version' => '1.0.0', 'scope' => 'runtime', 'advisory' => 'GHSA-example']], Advisories::findings($packages, '{"results":[{"vulns":[{"id":"GHSA-example"}]}]}'));
        self::assertSame([], Advisories::findings($packages, '{"results":[{}]}'));
    }

    #[DataProvider('provideIncompleteOrInvalidResponseCannotPassCases')]
    public function testIncompleteOrInvalidResponseCannotPass(string $response): void
    {
        $this->expectException(\RuntimeException::class);
        Advisories::findings([['name' => 'a/b', 'version' => '1', 'ecosystem' => 'Packagist', 'scope' => 'runtime']], $response);
    }

    /** @return iterable<string, array{string}> */
    public static function provideIncompleteOrInvalidResponseCannotPassCases(): iterable
    {
        yield 'missing' => ['{}'];
        yield 'partial coverage' => ['{"results":[]}'];
        yield 'array instead of package result object' => ['{"results":[[]]}'];
        yield 'pagination' => ['{"results":[{"next_page_token":"more"}]}'];
        yield 'invalid findings' => ['{"results":[{"vulns":false}]}'];
        yield 'null findings' => ['{"results":[{"vulns":null}]}'];
        yield 'per-package error' => ['{"results":[{"error":"quota exceeded"}]}'];
        yield 'missing id' => ['{"results":[{"vulns":[{}]}]}'];
    }

    public function testNewSecurityReleaseForcesReviewEvenWhenCiUsesFreshPhp(): void
    {
        Advisories::assertPhpPolicyCurrent('{"8.5.12":{"tags":["security"]},"8.5.13":{"tags":[]}}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Review the PHP security floor');
        Advisories::assertPhpPolicyCurrent('{"8.5.13":{"tags":["security"]}}');
    }

    #[DataProvider('provideEmptyOrWrongPhpFeedCannotPassCases')]
    public function testEmptyOrWrongPhpFeedCannotPass(string $feed): void
    {
        $this->expectException(\RuntimeException::class);
        Advisories::assertPhpPolicyCurrent($feed);
    }

    /** @return iterable<string, array{string}> */
    public static function provideEmptyOrWrongPhpFeedCannotPassCases(): iterable
    {
        yield 'empty' => ['{}'];
        yield 'no security release' => ['{"8.5.12":{"tags":[]}}'];
        yield 'wrong branch' => ['{"8.4.27":{"tags":["security"]}}'];
        yield 'invalid entry' => ['{"8.5.12":{}}'];
    }

    public function testScannerFailureAndDiagnosticsArePreserved(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'fwrite(STDERR, "scanner finding"); exit(3);']);
        self::assertSame(3, $result['exit']);
        self::assertSame('scanner finding', $result['output']);
    }

    public function testScannerTimeoutIsAClosedFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('time or output limit');
        Process::run([PHP_BINARY, '-r', 'usleep(500000);'], 0.05);
    }

    public function testScannerOutputIsBounded(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output limit');
        Process::run([PHP_BINARY, '-r', 'echo str_repeat("x", 65536);'], 10, 1024);
    }
}
