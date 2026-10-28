#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Platform\Runtime\PhpRuntime;
use Project\Security\Advisories;
use Project\Security\Inventory;
use Project\Security\Process;
use Project\Security\RoadRunnerReview;
use Project\Security\ScanReport;

require_once __DIR__ . '/bootstrap.php';

try {
    $root = dirname(__DIR__, 2);
    $arguments = $argv ?? [];
    $command = $arguments[1] ?? '';
    if ($command === 'inventory' || $command === 'audit') {
        if (count($arguments) !== 2) {
            throw new InvalidArgumentException('Unexpected security audit arguments');
        }
        $packages = new Inventory($root)->packages();
        fwrite(STDOUT, json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if ($command === 'inventory') {
            exit(0);
        }
        $findings = Advisories::findings($packages, Advisories::request('https://api.osv.dev/v1/querybatch', json_encode([
            'queries' => array_map(static fn(array $package): array => [
                'package' => ['name' => $package['name'], 'ecosystem' => $package['ecosystem']], 'version' => $package['version'],
            ], $packages),
        ], JSON_THROW_ON_ERROR)));
        foreach ($findings as $finding) {
            fwrite(STDOUT, $finding['scope'] . ': ' . $finding['package'] . '@' . $finding['version'] . ' ' . $finding['advisory'] . "\n");
        }
        // Check the policy, not just the fresh PHP installed on this CI runner.
        Advisories::assertPhpPolicyCurrent(Advisories::request('https://www.php.net/releases/index.php?json&version=8.5&max=100'));
        if ($findings !== []) {
            throw new RuntimeException('Bundled PHP advisory findings require review');
        }
        fwrite(STDOUT, "PHP package audit and upstream security floor: OK\n");
    } elseif ($command === 'platform') {
        if ((count($arguments) !== 3 && count($arguments) !== 4) || !str_starts_with($arguments[2], '--rr=') || (isset($arguments[3]) && !str_starts_with($arguments[3], '--report='))) {
            throw new InvalidArgumentException('Usage: security:platform --rr=/absolute/path/to/rr [--report=report.sarif]');
        }
        PhpRuntime::assertSupported();
        $binary = realpath(substr($arguments[2], 5));
        if ($binary === false || !is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('Provide the actual executable used for HTTP workers');
        }
        $version = Process::run([$binary, '--version'], 10, 65_536);
        fwrite(STDOUT, 'PHP ' . PHP_VERSION . "\n" . $version['output']);
        if ($version['exit'] !== 0 || preg_match('/^\S+ version (3\.\d+\.\d+)(?=[ ,\r\n]|$)/m', $version['output'], $match) !== 1 || version_compare($match[1], '3.0.1', '<')) {
            throw new RuntimeException('RoadRunner 3.0.1 or newer within 3.x is required');
        }
        $platform = match (PHP_OS_FAMILY . '/' . php_uname('m')) {
            'Linux/x86_64' => 'linux-amd64',
            'Darwin/arm64' => 'darwin-arm64',
            default => throw new RuntimeException('Bundled scanner supports Linux amd64 and macOS arm64; audit on one of these hosts'),
        };
        $sha256 = hash_file('sha256', $binary);
        if ($sha256 === false) {
            throw new RuntimeException('Cannot identify the RoadRunner executable');
        }
        fwrite(STDOUT, 'RoadRunner SHA-256: ' . $sha256 . "\n");
        $result = Process::run([$root . '/third_party/tools/govulncheck/govulncheck-' . $platform, '-db=https://vuln.go.dev', '-mode=binary', '-format=sarif', $binary]);
        if (isset($arguments[3])) {
            $path = substr($arguments[3], 9);
            if ($path === '' || file_put_contents($path, $result['output']) !== strlen($result['output'])) {
                throw new RuntimeException('Cannot save the raw RoadRunner SARIF report');
            }
        }
        if ($result['exit'] !== 0) {
            throw new RuntimeException('RoadRunner vulnerability scanner failed (exit ' . $result['exit'] . '): ' . $result['output']);
        }
        // SARIF findings do not change the scanner exit code; classify every result.
        $findings = ScanReport::findings($result['output']);
        $review = new RoadRunnerReview(__DIR__ . '/assessments');
        $unresolved = [];
        foreach ($findings as $id) {
            $record = Advisories::request('https://vuln.go.dev/ID/' . $id . '.json');
            if ($review->notAffected($sha256, $id, $record, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
                fwrite(STDOUT, $id . ': not_affected; reviewed source imports exclude the affected packages (this binary only)' . "\n");
            } else {
                $unresolved[] = $id;
                fwrite(STDOUT, $id . ': unresolved; https://pkg.go.dev/vuln/' . $id . "\n");
            }
        }
        if ($unresolved !== []) {
            throw new RuntimeException('Unresolved RoadRunner findings: ' . implode(', ', $unresolved));
        }
        fwrite(STDOUT, "RoadRunner vulnerability assessment: OK\n");
    } else {
        throw new InvalidArgumentException('Expected inventory, audit or platform');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Security check failed: ' . $error->getMessage() . "\n");
    exit(1);
}
