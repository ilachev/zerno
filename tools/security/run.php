#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Platform\Runtime\PhpRuntime;
use Project\Security\Advisories;
use Project\Security\Inventory;
use Project\Security\Process;

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
        if (count($arguments) !== 3 || !str_starts_with($arguments[2], '--rr=')) {
            throw new InvalidArgumentException('Usage: security:platform --rr=/absolute/path/to/rr');
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
        fwrite(STDOUT, 'RoadRunner SHA-256: ' . hash_file('sha256', $binary) . "\n");
        $result = Process::run([$root . '/third_party/tools/govulncheck/govulncheck-' . $platform, '-db=https://vuln.go.dev', '-mode=binary', $binary]);
        fwrite(STDOUT, $result['output']);
        // Text mode preserves govulncheck's failure exit status; JSON mode does not.
        if ($result['exit'] !== 0) {
            throw new RuntimeException('RoadRunner vulnerability scan failed (exit ' . $result['exit'] . ')');
        }
    } else {
        throw new InvalidArgumentException('Expected inventory, audit or platform');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Security check failed: ' . $error->getMessage() . "\n");
    exit(1);
}
