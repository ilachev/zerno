#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Platform\Runtime\PhpRuntime;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/http-wire.php';
require_once __DIR__ . '/catalog-process.php';

final class CatalogCheck
{
    private readonly float $deadline;

    private string $operation = 'prerequisites';

    private ?string $temporary = null;

    private ?PDO $database = null;

    private ?string $schema = null;

    private ?CatalogProcess $server = null;

    /** @var list<CatalogProcess> */
    private array $processes = [];

    private int $port = 0;

    private ?string $notFoundBody = null;

    private bool $executed = false;

    /** @var array<string, string> */
    private array $inputs = [];

    /** @var array<string, string|int|bool|null> */
    private array $platform = [];

    /** @var list<array{name: string, outcome: string}> */
    private array $assertions = [];

    /** @var array<string, string> */
    private array $cleanup = ['processes' => 'not-started', 'schema' => 'not-created', 'files' => 'not-created'];

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
            $this->revision = trim($this->command('git-revision', ['git', 'rev-parse', 'HEAD'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']));
            $this->dirty = trim($this->command('git-state', ['git', 'status', '--porcelain', '--untracked-files=all'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'])) !== '';
            $this->operation = 'roadrunner-version';
            $binary = realpath(wireExecutable($binary)) ?: throw new RuntimeException('Cannot resolve RoadRunner');
            $version = $this->command('roadrunner-version', [$binary, '--version'], []);
            if (preg_match('/version (3\.\d+\.\d+)(?:[ ,]|$).*?(go\d+\.\d+(?:\.\d+)?)/', $version, $match) !== 1
                || version_compare($match[1], '3.0.1', '<')) {
                throw new RuntimeException('RoadRunner 3.0.1+ from the 3.x line is required');
            }
            $this->platform = [
                'php' => PHP_VERSION, 'rr' => $match[1], 'go' => $match[2],
                'rr_sha256' => $this->hash($binary), 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
                'workers' => 1, 'opcache_enable_cli' => 1, 'http_chunk_size' => 8192,
            ];
            $this->operation = 'source/setup';
            $this->prepare();
            $this->executed = true;
            $environment = $this->environment();
            $this->port = wirePort();
            $this->server = new CatalogProcess([
                $binary, 'serve', '-w', $this->root, '-c', 'examples/document-catalog/rr.yaml',
                '-o', 'http.address=127.0.0.1:' . $this->port,
                '-o', 'http.pool.num_workers=1',
                '-o', 'http.pool.allocate_timeout=5s', '-o', 'http.pool.destroy_timeout=2s',
            ], $this->root, $environment);
            $this->processes[] = $this->server;
            $this->cleanup['processes'] = 'pending';
            $this->ready();
            $this->scenario($environment);
        } catch (Throwable $error) {
            // Only checker-owned messages are public; database and server diagnostics stay private.
            $this->failure = $this->operation;
            $this->assertions[] = ['name' => $this->operation, 'outcome' => 'failed'];
            fwrite(STDERR, 'Catalog check failed at ' . $this->operation . ($error instanceof PDOException ? '; check TEST_DB_* connectivity and schema permissions' : ': ' . $error->getMessage()) . "\n");
        } finally {
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGINT, SIG_IGN);
                pcntl_signal(SIGTERM, SIG_IGN);
            }
            $this->clean();
            if ($this->inputs !== []) {
                try {
                    $this->operation = 'source/inputs-unchanged';
                    $this->same($this->inputs, $this->fingerprints(), $this->operation);
                } catch (Throwable) {
                    $this->failure ??= 'source/inputs-unchanged';
                }
            }
        }

        return $this->failure === null;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'format_version' => 1, 'run_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => 'source-only', 'outcome' => $this->failure === null ? 'passed' : 'failed',
            'failed_operation' => $this->failure, 'git_revision' => $this->revision, 'git_dirty' => $this->dirty,
            'input_sha256' => $this->inputs, 'platform' => $this->platform,
            'phases' => $this->executed ? [['name' => 'source', 'outcome' => $this->failure === null ? 'passed' : 'failed']] : [],
            'assertions' => $this->assertions, 'cleanup' => $this->cleanup,
        ];
    }

    private function prerequisites(): void
    {
        PhpRuntime::assertSupported();
        foreach (['pdo_pgsql', 'sockets', 'pcntl', 'posix', 'dom'] as $extension) {
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
        }
        if (filter_var(getenv('TEST_DB_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new RuntimeException('TEST_DB_PORT must be between 1 and 65535');
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (): never {
                throw new RuntimeException('Catalog check interrupted');
            });
        }
    }

    private function prepare(): void
    {
        $directory = sys_get_temp_dir() . '/zerno-catalog-check-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Cannot create private fixture directory');
        }
        $this->temporary = $directory;
        $this->cleanup['files'] = 'pending';
        if (!mkdir($directory . '/files', 0o700)) {
            throw new RuntimeException('Cannot create private PDF directory');
        }
        if (!mkdir($directory . '/bin', 0o700) || !symlink(PHP_BINARY, $directory . '/bin/php')) {
            throw new RuntimeException('Cannot select the checker PHP for the shipped worker command');
        }
        $this->database = new PDO(
            'pgsql:host=' . getenv('TEST_DB_HOST') . ';port=' . getenv('TEST_DB_PORT') . ';dbname=' . getenv('TEST_DB_NAME') . ';connect_timeout=5',
            (string) getenv('TEST_DB_USER'),
            (string) getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => true],
        );
        $this->database->exec("SET statement_timeout = '5s'");
        $this->database->exec("SET lock_timeout = '5s'");
        $schema = 'catalog_check_' . bin2hex(random_bytes(12));
        $this->database->exec('CREATE SCHEMA "' . $schema . '"');
        // Claim ownership only after CREATE succeeds.
        $this->schema = $schema;
        $this->cleanup['schema'] = 'pending';
        $this->database->exec('SET search_path TO "' . $schema . '"');
        $version = $this->database->query('SHOW server_version');
        $this->platform['postgresql'] = $version === false ? null : (string) $version->fetchColumn();
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        if ($this->schema === null || $this->temporary === null) {
            throw new LogicException('Prepare owned fixtures before starting the application');
        }
        $environment = [
            'PATH' => $this->temporary . '/bin:/usr/bin:/bin', 'TMPDIR' => $this->temporary,
            'DB_SCHEMA' => $this->schema, 'CATALOG_FILES_DIR' => $this->temporary . '/files',
            'PGCONNECT_TIMEOUT' => '5', 'PGOPTIONS' => '-c statement_timeout=5000 -c lock_timeout=5000',
            'ENVIRONMENT' => 'testing',
        ];
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $name) {
            $environment['DB_' . $name] = (string) getenv('TEST_DB_' . $name);
        }

        return $environment;
    }

    private function ready(): void
    {
        $this->operation = 'source/listener-ready';
        $deadline = min($this->deadline, hrtime(true) / 1_000_000_000 + 10);

        while (hrtime(true) / 1_000_000_000 < $deadline) {
            $this->tick();
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $error, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }

        throw new RuntimeException('Listener did not become ready within ten seconds');
    }

    /** @param array<string, string> $environment */
    private function scenario(array $environment): void
    {
        $this->same('{"status":"ok"}', $this->request('GET', '/health', 200)->body, 'source/health-before-migration');
        $this->same('', $this->request('HEAD', '/health', 200)->body, 'source/health-head');
        $this->request('GET', '/__catalog_missing', 404);
        $method = $this->request('POST', '/documents', 405);
        $this->same(['GET, HEAD'], $method->headers['allow'] ?? [], 'source/method-allow');
        $this->same('0', (string) $this->query('SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema()')->fetchColumn(), 'source/no-startup-ddl');
        $this->command('source/migrate', [PHP_BINARY, $this->root . '/bin/migrate.php', 'migrate', 'examples/document-catalog/migrations'], $environment);
        $this->command('source/seed-first', [PHP_BINARY, $this->root . '/examples/document-catalog/seed.php'], $environment);
        $rows = $this->rows();
        $files = $this->fileSnapshot();
        $this->same(26, count($rows), 'source/seed-total');
        $this->same(26, count($files), 'source/seed-files');
        $this->command('source/seed-second', [PHP_BINARY, $this->root . '/examples/document-catalog/seed.php'], $environment);
        $this->same($rows, $this->rows(), 'source/seed-idempotent-rows');
        $this->same($files, $this->fileSnapshot(), 'source/seed-idempotent-files');
        $published = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === 'published'));
        $this->same(24, count($published), 'source/seed-published');
        foreach (['draft', 'withdrawn'] as $status) {
            $this->same(1, count(array_filter($rows, static fn(array $row): bool => $row['status'] === $status)), 'source/seed-' . $status);
        }
        $first = $this->request('GET', '/documents', 200);
        $this->same(true, str_contains($first->body, 'Published documents, most recently added first.'), 'source/list-english');
        $this->same(array_column(array_slice($published, 0, 20), 'id'), $this->listIds($first->body), 'source/list-first-twenty');
        $next = '/documents?before=' . $published[19]['id'];
        $this->same(true, str_contains($first->body, 'rel="next" href="' . $next . '"'), 'source/list-next');
        $second = $this->request('GET', $next, 200);
        $this->same(array_column(array_slice($published, 20), 'id'), $this->listIds($second->body), 'source/list-last-four');
        $this->same(false, str_contains($second->body, 'rel="next"'), 'source/list-last-no-next');
        $this->same(true, str_contains($second->body, 'href="/documents">Back to the first page'), 'source/list-back');
        $document = $published[0];
        $detail = $this->request('GET', '/documents/' . $document['id'], 200);
        foreach ([$document['title'], $document['description'], $document['original_name'], gmdate('Y-m-d', (int) $document['published_at'])] as $index => $text) {
            $this->same(true, str_contains($detail->body, $text), 'source/detail-field-' . $index);
        }
        $this->downloads($document);
        $this->request('GET', '/documents?before=01', 400);
        $this->request('GET', '/documents/' . PHP_INT_MAX, 404);
        $this->request('GET', '/documents/' . PHP_INT_MAX . '/download', 404);
        foreach ($rows as $row) {
            if ($row['status'] !== 'published') {
                $this->request('GET', '/documents/' . $row['id'], 404);
                $this->request('GET', '/documents/' . $row['id'] . '/download', 404);
            }
        }
        $this->same('{"status":"ok"}', $this->request('GET', '/health', 200)->body, 'source/health-after-errors');
    }

    /** @param array<string, string> $document */
    private function downloads(array $document): void
    {
        $path = $this->temporary . '/files/' . $document['storage_key'];
        $target = '/documents/' . $document['id'] . '/download';
        $bytes = file_get_contents($path, length: 1_048_577);
        if ($bytes === false || strlen($bytes) > 1_048_576) {
            throw new RuntimeException('Cannot read owned PDF fixture within 1 MiB');
        }
        $get = $this->request('GET', $target, 200, 'application/pdf');
        $this->same($bytes, $get->body, 'source/pdf-bytes');
        $this->same($this->hash($path), hash('sha256', $get->body), 'source/pdf-sha256');
        $this->same([$document['file_size']], $get->headers['content-length'] ?? [], 'source/pdf-length');
        $this->same(['nosniff'], $get->headers['x-content-type-options'] ?? [], 'source/pdf-nosniff');
        $this->same(['attachment; filename="document-' . $document['id'] . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($document['original_name'])], $get->headers['content-disposition'] ?? [], 'source/pdf-filename');
        $head = $this->request('HEAD', $target, 200, 'application/pdf');
        $this->same('', $head->body, 'source/pdf-head-empty');
        foreach (['content-type', 'content-length', 'content-disposition', 'x-content-type-options', 'cache-control'] as $header) {
            $this->same($get->headers[$header] ?? [], $head->headers[$header] ?? [], 'source/pdf-head-' . $header);
        }
        if (!rename($path, $path . '.unavailable')) {
            throw new RuntimeException('Cannot make owned PDF unavailable');
        }

        try {
            $this->request('GET', $target, 404);
        } finally {
            if (!rename($path . '.unavailable', $path)) {
                throw new RuntimeException('Cannot restore owned PDF');
            }
        }
        $this->same($bytes, $this->request('GET', $target, 200, 'application/pdf')->body, 'source/pdf-restored');
    }

    private function request(string $method, string $target, int $status, string $type = 'text/html; charset=utf-8'): HttpWireResponse
    {
        $name = 'source/' . $method . ' ' . $target;
        $this->operation = $name;
        $this->tick();
        $response = wireHttp($this->port, $method, $target, [], timeout: min(5, $this->remaining()), responseLimit: 1_048_576);
        $this->same($status, $response->status, $name . '/status');
        $this->same([], $response->headers['set-cookie'] ?? [], $name . '/no-cookie');
        if ($target === '/health') {
            $this->same(['application/json'], $response->headers['content-type'] ?? [], $name . '/type');
        } else {
            $this->same([$type], $response->headers['content-type'] ?? [], $name . '/type');
            $this->same(['no-store'], $response->headers['cache-control'] ?? [], $name . '/no-store');
        }
        if ($status === 404) {
            $this->notFoundBody ??= $response->body;
            $this->same($this->notFoundBody, $response->body, $name . '/public-not-found');
        }

        return $response;
    }

    /** @return list<string> */
    private function listIds(string $html): array
    {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html, LIBXML_NONET)) {
            throw new RuntimeException('Invalid catalog HTML');
        }
        $nodes = new DOMXPath($document)->query('//ol/li/h2/a');
        if ($nodes === false) {
            throw new RuntimeException('Cannot read catalog list');
        }
        $ids = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement || preg_match('~\A/documents/([1-9][0-9]*)\z~', $node->getAttribute('href'), $match) !== 1) {
                throw new RuntimeException('Unexpected catalog list link');
            }
            $ids[] = $match[1];
        }

        return $ids;
    }

    private function query(string $sql): PDOStatement
    {
        $this->tick();
        $statement = $this->database?->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Cannot query owned catalog schema');
        }

        return $statement;
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $rows = $this->query('SELECT id, title, description, status, published_at, storage_key, original_name, file_size FROM catalog_documents ORDER BY id DESC LIMIT 27')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Unexpected catalog fixture row');
            }
            $values = [];
            foreach (['id', 'title', 'description', 'status', 'published_at', 'storage_key', 'original_name', 'file_size'] as $name) {
                $value = $row[$name] ?? null;
                if (!is_string($value)) {
                    throw new RuntimeException('Unexpected catalog fixture field');
                }
                $values[$name] = $value;
            }
            if (preg_match('/\A[1-9][0-9]*\z/', $values['id']) !== 1
                || preg_match('/\A[a-f0-9]{64}\.pdf\z/', $values['storage_key']) !== 1) {
                throw new RuntimeException('Unexpected owned fixture identifier or storage key');
            }
            $result[] = $values;
        }

        return $result;
    }

    /** @return array<string, string> */
    private function fileSnapshot(): array
    {
        $result = [];
        clearstatcache();
        foreach (new FilesystemIterator($this->temporary . '/files', FilesystemIterator::SKIP_DOTS) as $file) {
            $this->remaining();
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()
                || preg_match('/\A[a-f0-9]{64}\.pdf\z/', $file->getFilename()) !== 1
                || $file->getSize() > 1_048_576 || count($result) >= 26) {
                throw new RuntimeException('Unexpected owned PDF fixture or file limit exceeded');
            }
            $result[$file->getFilename()] = $this->hash($file->getPathname()) . ':' . $file->getSize() . ':' . $file->getMTime() . ':' . $file->getInode();
        }
        ksort($result);

        return $result;
    }

    private function same(mixed $expected, mixed $actual, string $name): void
    {
        $this->operation = $name;
        $this->assertions[] = ['name' => $name, 'outcome' => $expected === $actual ? 'passed' : 'failed'];
        if ($expected !== $actual) {
            throw new RuntimeException('Assertion failed');
        }
    }

    /** @param non-empty-list<string> $command
     * @param array<string, string> $environment
     */
    private function command(string $name, array $command, array $environment): string
    {
        $this->operation = $name;
        $this->tick();
        $command[0] = realpath(wireExecutable($command[0])) ?: throw new RuntimeException('Cannot resolve child executable');
        $process = new CatalogProcess($command, $this->root, $environment);
        $this->processes[] = $process;
        $this->cleanup['processes'] = 'pending';

        try {
            $output = $process->wait(min($this->deadline, hrtime(true) / 1_000_000_000 + 15));
            $this->assertions[] = ['name' => $name, 'outcome' => 'passed'];

            return $output;
        } finally {
            $process->stop();
        }
    }

    private function remaining(): float
    {
        $remaining = $this->deadline - hrtime(true) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new RuntimeException('Catalog check exceeded its 120 second deadline');
        }

        return $remaining;
    }

    private function tick(): void
    {
        $this->remaining();
        if ($this->server !== null && $this->server->poll() !== null) {
            throw new RuntimeException('RoadRunner exited before the scenario completed');
        }
    }

    private function hash(string $file): string
    {
        return hash_file('sha256', $file) ?: throw new RuntimeException('Cannot fingerprint a check input');
    }

    /** @return array<string, string> */
    private function fingerprints(): array
    {
        $files = [];
        foreach (['bootstrap', 'src', 'gen', 'config', 'public', 'bin', 'examples/document-catalog', 'third_party/runtime'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($this->root) + 1);
                }
            }
        }
        $result = [];
        foreach ([...$files, 'bootstrap.php', 'third_party/manifest.json', 'tools/project.php', 'tools/check-catalog.php', 'tools/catalog-process.php', 'tools/http-wire.php'] as $file) {
            $this->remaining();
            $result[$file] = $this->hash($this->root . '/' . $file);
        }
        ksort($result);

        return $result;
    }

    private function clean(): void
    {
        foreach (['processes', 'schema', 'files'] as $resource) {
            try {
                if ($resource === 'processes' && $this->processes !== []) {
                    $failed = false;
                    foreach ($this->processes as $process) {
                        try {
                            $process->stop();
                        } catch (Throwable) {
                            $failed = true;
                        }
                    }
                    if ($failed) {
                        throw new RuntimeException('Cannot stop all owned children');
                    }
                    $this->cleanup[$resource] = 'stopped';
                } elseif ($resource === 'schema' && $this->schema !== null) {
                    $this->database?->exec('DROP SCHEMA "' . $this->schema . '" CASCADE');
                    $this->cleanup[$resource] = 'removed';
                } elseif ($resource === 'files' && $this->temporary !== null) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($iterator as $file) {
                        if (!$file instanceof SplFileInfo || !($file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()))) {
                            throw new RuntimeException('Cannot remove owned fixture');
                        }
                    }
                    if (!rmdir($this->temporary)) {
                        throw new RuntimeException('Cannot remove owned fixture directory');
                    }
                    $this->cleanup[$resource] = 'removed';
                }
            } catch (Throwable) {
                $this->cleanup[$resource] = 'failed';
                $this->failure ??= 'cleanup/' . $resource;
                fwrite(STDERR, 'Catalog cleanup failed: ' . $resource . "\n");
            }
        }
        $this->database = null;
    }
}

$exit = 1;
$report = null;

try {
    $binary = 'rr';
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, "Usage: php tools/project.php catalog:check [--rr=/path/to/rr3] [--report=var/catalog-check.json]\nSource checkout only; requires explicit TEST_DB_HOST/PORT/NAME/USER/PASSWORD, PostgreSQL and prepared RoadRunner. No downloads or service startup.\n");
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
        fwrite(STDOUT, "Catalog source check: passed; migration, repeated seed, HTML, PDF, errors and cleanup. Archive phase is not implemented.\n");
        $exit = 0;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Catalog check failed: ' . $error->getMessage() . "\n");
}
exit($exit);
