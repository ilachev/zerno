#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Platform\Runtime\PhpRuntime;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/catalog-scenario.php';

final class CatalogCheck
{
    private readonly float $deadline;

    private string $operation = 'prerequisites';

    private ?string $workspace = null;

    private ?string $archive = null;

    private ?string $extracted = null;

    private ?string $archiveSha256 = null;

    private ?int $archiveBytes = null;

    /** @var array<string, string> */
    private array $archiveInputs = [];

    /** @var list<CatalogProcess> */
    private array $processes = [];

    /** @var array<string, string> */
    private array $cleanup = ['processes' => 'not-started', 'archive' => 'not-created', 'extracted_tree' => 'not-created', 'workspace' => 'not-created'];

    /** @var array<string, string> */
    private array $testDatabase = [];

    /** @var array<string, string> */
    private array $inputs = [];

    /** @var array<string, string|int> */
    private array $platform = [];

    /** @var list<array<string, mixed>> */
    private array $phases = [];

    /** @var list<array{name: string, outcome: string}> */
    private array $assertions = [];

    private ?string $revision = null;

    private bool $dirty = false;

    private ?string $failure = null;

    public function __construct(private readonly string $root)
    {
        $this->deadline = hrtime(true) / 1_000_000_000 + 120;
    }

    public function run(string $binary): bool
    {
        try {
            $this->prerequisites();
            $this->inputs = $this->fingerprints();
            $this->revision = trim($this->command('git-revision', ['git', 'rev-parse', 'HEAD']));
            $this->dirty = trim($this->command('git-state', ['git', 'status', '--porcelain', '--untracked-files=all'])) !== '';
            $this->operation = 'roadrunner-version';
            $binary = realpath(wireExecutable($binary)) ?: throw new RuntimeException('Cannot resolve RoadRunner');
            $version = $this->command('roadrunner-version', [$binary, '--version']);
            if (preg_match('/version (3\.\d+\.\d+)(?:[ ,]|$).*?(go\d+\.\d+(?:\.\d+)?)/', $version, $match) !== 1
                || version_compare($match[1], '3.0.1', '<')) {
                throw new RuntimeException('RoadRunner 3.0.1+ from the 3.x line is required');
            }
            $this->platform = [
                'php' => PHP_VERSION, 'rr' => $match[1], 'go' => $match[2],
                'rr_sha256' => $this->hash($binary), 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
                'workers' => 1, 'opcache_enable_cli' => 1, 'http_chunk_size' => 8192,
            ];
            if ($this->phase('source', $this->root, $binary)) {
                $root = $this->buildArchive();
                $this->archiveInputs = $this->archiveFingerprints($root);
                $this->phase('archive', $root, $binary);
                $this->same($this->archiveInputs, $this->archiveFingerprints($root), 'archive/inputs-unchanged');
            }
        } catch (Throwable $error) {
            $this->failure ??= $this->operation;
            $this->assertions[] = ['name' => $this->operation, 'outcome' => 'failed'];
            fwrite(STDERR, 'Catalog check failed at ' . $this->operation . ': ' . $error->getMessage() . "\n");
        } finally {
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGINT, SIG_IGN);
                pcntl_signal(SIGTERM, SIG_IGN);
            }
            $this->clean();
            if ($this->inputs !== []) {
                try {
                    $this->operation = 'inputs-unchanged';
                    $this->same($this->inputs, $this->fingerprints(), $this->operation);
                } catch (Throwable) {
                    $this->failure ??= 'inputs-unchanged';
                }
            }
        }

        return $this->failure === null;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'format_version' => 2, 'run_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => 'source-and-archive', 'outcome' => $this->failure === null ? 'passed' : 'failed',
            'failed_operation' => $this->failure, 'git_revision' => $this->revision, 'git_dirty' => $this->dirty,
            'input_sha256' => $this->inputs, 'platform' => $this->platform,
            'archive' => $this->archiveSha256 === null ? null : ['sha256' => $this->archiveSha256, 'bytes' => $this->archiveBytes, 'file_count' => count($this->archiveInputs)],
            'phases' => $this->phases, 'assertions' => $this->assertions, 'cleanup' => $this->cleanup,
        ];
    }

    private function prerequisites(): void
    {
        PhpRuntime::assertSupported();
        foreach (['pdo_pgsql', 'sockets', 'pcntl', 'posix', 'dom', 'phar', 'zlib'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new RuntimeException('Required checker extension is unavailable: ' . $extension);
            }
        }
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $name) {
            $value = getenv('TEST_DB_' . $name);
            if ($value === false || trim($value) === '') {
                throw new RuntimeException('Set TEST_DB_' . $name . ' explicitly; application DB_* settings are not used');
            }
            if (in_array($name, ['HOST', 'NAME'], true) && preg_match('/[;\s\x00]/', $value) === 1) {
                throw new RuntimeException('TEST_DB_' . $name . ' must be a single DSN value without whitespace or semicolons');
            }
            $this->testDatabase[$name] = $value;
        }
        if (filter_var($this->testDatabase['PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new RuntimeException('TEST_DB_PORT must be between 1 and 65535');
        }
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false || $temporary === $this->root || str_starts_with($temporary, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The checker temporary directory must be outside the source checkout');
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (): never {
                throw new RuntimeException('Catalog check interrupted');
            });
        }
    }

    /** @param 'source'|'archive' $name */
    private function phase(string $name, string $root, string $binary): bool
    {
        $this->operation = $name . '/setup';
        $scenario = new CatalogScenario($name, $root, $binary, $this->deadline, $this->testDatabase);
        $passed = $scenario->run();
        $this->phases[] = $scenario->report();
        if (!$passed) {
            $this->failure = $scenario->failure() ?? $this->operation;
        }

        return $passed;
    }

    private function buildArchive(): string
    {
        $this->operation = 'archive/workspace';
        $directory = sys_get_temp_dir() . '/zerno-catalog-archive-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Cannot create owned archive workspace');
        }
        $this->workspace = $directory;
        $this->cleanup['workspace'] = 'pending';
        if (!mkdir($directory . '/build', 0o700) || !mkdir($directory . '/runtime', 0o700)) {
            throw new RuntimeException('Cannot create owned archive directories');
        }
        $this->archive = $directory . '/build/zerno-catalog-check.tar.gz';
        $this->extracted = $directory . '/runtime';
        $this->cleanup['extracted_tree'] = 'pending';
        $this->command('archive/build', [PHP_BINARY, $this->root . '/tools/project.php', 'build', 'catalog-check', '--output-dir=' . $directory . '/build'], 60);
        $this->operation = 'archive/digest';
        $size = filesize($this->archive);
        if ($size === false || $size <= 0 || $size > 67_108_864) {
            throw new RuntimeException('Runtime archive must fit the 64 MiB check limit');
        }
        $this->cleanup['archive'] = 'pending';
        $this->archiveBytes = $size;
        $this->archiveSha256 = $this->hash($this->archive);
        // Only the archive just built in our private workspace is accepted.
        $extract = <<<'PHP'
            $package = new PharData($argv[1]);
            $bytes = 0;
            $entries = 0;
            foreach (new RecursiveIteratorIterator($package, RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if (!$file instanceof PharFileInfo || $file->isLink()) {
                    throw new RuntimeException('Unexpected runtime archive entry');
                }
                $bytes += $file->isFile() ? $file->getSize() : 0;
                if (++$entries > 10000 || $bytes > 134217728) {
                    throw new RuntimeException('Runtime archive extraction limit exceeded');
                }
            }
            $package->extractTo($argv[2]);
            PHP;
        $this->command('archive/extract', [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $extract, $this->archive, $this->extracted], 30);
        foreach (['tools', 'tests', 'load-tests', 'sql', 'contracts', 'third_party/codegen', 'third_party/tools'] as $excluded) {
            $path = $this->extracted . '/' . $excluded;
            $this->same(false, file_exists($path) || is_link($path), 'archive/excludes-' . str_replace('/', '-', $excluded));
        }
        $this->operation = 'archive/runtime-inventory';
        $bytes = file_get_contents($this->extracted . '/third_party/manifest.json', length: 1_048_577);
        if ($bytes === false || strlen($bytes) > 1_048_576) {
            throw new RuntimeException('Cannot read packaged runtime inventory within 1 MiB');
        }
        $inventory = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        $distributions = $inventory instanceof stdClass ? ($inventory->distributions ?? null) : null;
        if (!is_array($distributions) || count($distributions) !== 1 || !$distributions[0] instanceof stdClass) {
            throw new RuntimeException('Packaged inventory must contain only runtime');
        }
        $this->same('runtime/manifest.json', $distributions[0]->manifest ?? null, 'archive/runtime-only-inventory');

        return realpath($directory . '/runtime') ?: throw new RuntimeException('Cannot resolve extracted application');
    }

    private function same(mixed $expected, mixed $actual, string $name): void
    {
        $this->operation = $name;
        $this->assertions[] = ['name' => $name, 'outcome' => $expected === $actual ? 'passed' : 'failed'];
        if ($expected !== $actual) {
            throw new RuntimeException('Assertion failed');
        }
    }

    /** @param non-empty-list<string> $command */
    private function command(string $name, array $command, float $timeout = 15): string
    {
        $this->operation = $name;
        $this->remaining();
        $command[0] = realpath(wireExecutable($command[0])) ?: throw new RuntimeException('Cannot resolve child executable');
        $process = new CatalogProcess($command, $this->root, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => $this->workspace ?? sys_get_temp_dir(),
        ]);
        $this->processes[] = $process;
        $this->cleanup['processes'] = 'pending';

        try {
            $output = $process->wait(min($this->deadline, hrtime(true) / 1_000_000_000 + $timeout));
            $this->assertions[] = ['name' => $name, 'outcome' => 'passed'];

            return $output;
        } finally {
            $process->stop();
        }
    }

    private function remaining(): void
    {
        if (hrtime(true) / 1_000_000_000 >= $this->deadline) {
            throw new RuntimeException('Catalog check exceeded its 120 second deadline');
        }
    }

    private function hash(string $file): string
    {
        return hash_file('sha256', $file) ?: throw new RuntimeException('Cannot fingerprint a check input');
    }

    /** @return array<string, string> */
    private function archiveFingerprints(string $root): array
    {
        $result = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->remaining();
            if (!$file instanceof SplFileInfo || $file->isLink()) {
                throw new RuntimeException('Unexpected extracted runtime entry');
            }
            if ($file->isFile()) {
                $result[substr($file->getPathname(), strlen($root) + 1)] = $this->hash($file->getPathname());
            }
        }
        ksort($result);

        return $result;
    }

    /** @return array<string, string> */
    private function fingerprints(): array
    {
        $files = [];
        foreach (['bootstrap', 'src', 'gen', 'config', 'public', 'bin', 'examples', 'docs', 'third_party/runtime'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($this->root) + 1);
                }
            }
        }
        foreach (['LICENSE*', 'NOTICE*', 'COPYING*', 'third_party/*'] as $pattern) {
            foreach (glob($this->root . '/' . $pattern) ?: [] as $file) {
                if (is_file($file)) {
                    $files[] = substr($file, strlen($this->root) + 1);
                }
            }
        }
        $result = [];
        foreach ([...$files, '.rr.yaml', 'README.md', 'bootstrap.php', 'tools/project.php', 'tools/check-third-party.php', 'tools/check-catalog.php', 'tools/catalog-scenario.php', 'tools/catalog-process.php', 'tools/http-wire.php'] as $file) {
            $this->remaining();
            $result[$file] = $this->hash($this->root . '/' . $file);
        }
        ksort($result);

        return $result;
    }

    private function clean(): void
    {
        foreach (['processes', 'archive', 'extracted_tree', 'workspace'] as $resource) {
            try {
                if ($resource === 'processes') {
                    $failed = false;
                    foreach ($this->processes as $process) {
                        try {
                            $process->stop();
                        } catch (Throwable) {
                            $failed = true;
                        }
                    }
                    if ($failed) {
                        throw new RuntimeException('Cannot stop checker children');
                    }
                    if ($this->processes !== []) {
                        $this->cleanup[$resource] = 'stopped';
                    }
                } elseif ($resource === 'archive' && $this->archive !== null && is_file($this->archive)) {
                    if (!unlink($this->archive)) {
                        throw new RuntimeException('Cannot remove owned archive');
                    }
                    $this->cleanup[$resource] = 'removed';
                } elseif ($resource === 'extracted_tree' && $this->extracted !== null) {
                    $this->removeDirectory($this->extracted);
                    $this->cleanup[$resource] = 'removed';
                } elseif ($resource === 'workspace' && $this->workspace !== null) {
                    $this->removeDirectory($this->workspace);
                    $this->cleanup[$resource] = 'removed';
                }
            } catch (Throwable) {
                $this->cleanup[$resource] = 'failed';
                $this->failure ??= 'cleanup/' . $resource;
                fwrite(STDERR, 'Catalog cleanup failed: ' . $resource . "\n");
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !($file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()))) {
                throw new RuntimeException('Cannot remove owned archive fixture');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Cannot remove owned archive workspace');
        }
    }
}

$exit = 1;
$report = null;

try {
    $binary = 'rr';
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, "Usage: php tools/project.php catalog:check [--rr=/path/to/rr3] [--report=var/catalog-check.json]\nChecks source and runtime archive; requires Git and explicit TEST_DB_HOST/PORT/NAME/USER/PASSWORD, PostgreSQL and prepared RoadRunner. No downloads or service startup.\n");
            exit(0);
        }
        if (str_starts_with($argument, '--rr=') && strlen($argument) > 5) {
            $binary = substr($argument, 5);
        } elseif (str_starts_with($argument, '--report=') && strlen($argument) > 9) {
            $report = substr($argument, 9);
        } else {
            throw new InvalidArgumentException('Unknown catalog check option');
        }
    }
    if ($report !== null && !is_dir(dirname($report))) {
        throw new InvalidArgumentException('Report parent directory must already exist');
    }
    $check = new CatalogCheck(dirname(__DIR__));
    $passed = $check->run($binary);
    if ($report !== null) {
        $bytes = json_encode($check->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam(dirname($report), '.catalog-report-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary report');
        }

        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $report)) {
                throw new RuntimeException('Cannot publish catalog report');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    if ($passed) {
        fwrite(STDOUT, "Catalog check: source and runtime archive passed; migration, repeated seed, HTML, PDF, errors and cleanup.\n");
        $exit = 0;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Catalog check failed: ' . $error->getMessage() . "\n");
}
exit($exit);
