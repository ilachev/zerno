<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\Security\ScanReport;

final class ScanReportTest extends TestCase
{
    public function testSarifFindingsMustBeClassifiedEvenWhenTheScannerExitsSuccessfully(): void
    {
        $report = self::report();
        $report['runs'][0]['tool']['driver']['rules'] = [['id' => 'GO-2026-5932']];
        $report['runs'][0]['results'] = [['ruleId' => 'GO-2026-5932', 'level' => 'error']];
        self::assertSame(['GO-2026-5932'], ScanReport::findings(json_encode($report, JSON_THROW_ON_ERROR)));
    }

    public function testACompleteEmptyScanPasses(): void
    {
        self::assertSame([], ScanReport::findings(json_encode(self::report(), JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('provideMissingResultsOrWrongScanModeCannotPassCases')]
    public function testMissingResultsOrWrongScanModeCannotPass(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        ScanReport::findings($json);
    }

    /** @return iterable<string, array{string}> */
    public static function provideMissingResultsOrWrongScanModeCannotPassCases(): iterable
    {
        yield 'no report' => ['{}'];
        yield 'object instead of result list' => [str_replace('"results":[]', '"results":{}', json_encode(self::report(), JSON_THROW_ON_ERROR))];
        $report = self::report();
        unset($report['runs'][0]['results']);
        yield 'missing results' => [json_encode($report, JSON_THROW_ON_ERROR)];
        $report = self::report();
        $report['runs'][0]['results'] = null;
        yield 'null results' => [json_encode($report, JSON_THROW_ON_ERROR)];
        $report = self::report();
        $report['runs'][0]['tool']['driver']['properties']['scan_mode'] = 'source';
        yield 'source report' => [json_encode($report, JSON_THROW_ON_ERROR)];
        $report = self::report();
        $report['runs'][0]['results'] = [['ruleId' => 'GO-2026-99999']];
        yield 'missing advisory definition' => [json_encode($report, JSON_THROW_ON_ERROR)];
    }

    /** @return array{version: string, runs: array{array{tool: array{driver: array{name: string, properties: array{scan_mode: string, scan_level: string}, rules: list<array{id: string}>}}, results?: list<array{ruleId: string, level?: string}>|null}}} */
    private static function report(): array
    {
        return ['version' => '2.1.0', 'runs' => [['tool' => ['driver' => [
            'name' => 'govulncheck', 'properties' => ['scan_mode' => 'binary', 'scan_level' => 'symbol'], 'rules' => [],
        ]], 'results' => []]]];
    }
}
