<?php

declare(strict_types=1);

require_once __DIR__ . '/http-wire.php';
require_once __DIR__ . '/catalog-process.php';

/** Runs the same concrete catalog scenario with independently owned resources. */
final class CatalogScenario
{
    private string $operation;

    private ?string $temporary = null;

    private ?PDO $database = null;

    private ?string $schema = null;

    private ?CatalogProcess $server = null;

    /** @var list<CatalogProcess> */
    private array $processes = [];

    private int $port = 0;

    private ?string $notFoundBody = null;

    private ?string $postgresqlVersion = null;

    /** @var list<array{name: string, outcome: string}> */
    private array $assertions = [];

    /** @var array<string, string> */
    private array $cleanup = ['processes' => 'not-started', 'schema' => 'not-created', 'files' => 'not-created'];

    private ?string $failure = null;

    /** @param 'source'|'archive' $phase
     * @param array<string, string> $testDatabase
     */
    public function __construct(
        private readonly string $phase,
        private readonly string $root,
        private readonly string $binary,
        private readonly float $deadline,
        private readonly array $testDatabase,
    ) {
        $this->operation = $phase . '/setup';
    }

    public function run(): bool
    {
        try {
            $this->prepare();
            $environment = $this->environment();
            $this->port = wirePort();
            $this->server = new CatalogProcess([
                $this->binary, 'serve', '-w', $this->root, '-c', 'examples/document-catalog/rr.yaml',
                '-o', 'http.address=127.0.0.1:' . $this->port,
                '-o', 'http.pool.num_workers=1',
                '-o', 'http.pool.allocate_timeout=5s', '-o', 'http.pool.destroy_timeout=2s',
            ], $this->root, $environment);
            $this->processes[] = $this->server;
            $this->cleanup['processes'] = 'pending';
            $this->ready();
            $this->scenario($environment);
        } catch (Throwable $error) {
            $this->failure = $this->operation;
            $this->assertions[] = ['name' => $this->operation, 'outcome' => 'failed'];
            fwrite(STDERR, 'Catalog check failed at ' . $this->operation . ($error instanceof PDOException ? '; check TEST_DB_* connectivity and schema permissions' : ': ' . $error->getMessage()) . "\n");
        } finally {
            $handlers = [];
            foreach ([SIGINT, SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, SIG_IGN);
            }

            try {
                $this->clean();
            } finally {
                foreach ($handlers as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
            }
        }

        return $this->failure === null;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'name' => $this->phase, 'outcome' => $this->failure === null ? 'passed' : 'failed',
            'failed_operation' => $this->failure, 'postgresql' => $this->postgresqlVersion,
            'fixture_schema' => $this->schema,
            'fixture_directory' => $this->temporary === null ? null : basename($this->temporary),
            'php_file_access' => $this->phase === 'archive' ? 'application-and-fixture' : 'platform-default',
            'assertions' => $this->assertions, 'cleanup' => $this->cleanup,
        ];
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
        if (!mkdir($directory . '/bin', 0o700)) {
            throw new RuntimeException('Cannot select the checker PHP for the shipped worker command');
        }
        if ($this->phase === 'archive') {
            // Keep the shipped "php ..." command while enforcing archive-only PHP file access.
            $wrapper = "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY)
                . ' -d ' . escapeshellarg('open_basedir=' . $this->allowedPaths()) . ' "$@"' . "\n";
            if (file_put_contents($directory . '/bin/php', $wrapper) !== strlen($wrapper)
                || !chmod($directory . '/bin/php', 0o700)) {
                throw new RuntimeException('Cannot configure packaged worker file access');
            }
        } elseif (!symlink(PHP_BINARY, $directory . '/bin/php')) {
            throw new RuntimeException('Cannot select the checker PHP');
        }
        $this->database = new PDO(
            'pgsql:host=' . $this->testDatabase['HOST'] . ';port=' . $this->testDatabase['PORT'] . ';dbname=' . $this->testDatabase['NAME'] . ';connect_timeout=5',
            (string) $this->testDatabase['USER'],
            (string) $this->testDatabase['PASSWORD'],
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
        $this->postgresqlVersion = $version === false ? null : (string) $version->fetchColumn();
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
            $environment['DB_' . $name] = $this->testDatabase[$name];
        }

        return $environment;
    }

    private function ready(): void
    {
        $this->operation = $this->phase . '/listener-ready';
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
        $this->same('{"status":"ok"}', $this->request('GET', '/health', 200)->body, $this->phase . '/health-before-migration');
        $this->same('', $this->request('HEAD', '/health', 200)->body, $this->phase . '/health-head');
        $this->request('GET', '/__catalog_missing', 404);
        $method = $this->request('POST', '/documents', 405);
        $this->same(['GET, HEAD'], $method->headers['allow'] ?? [], $this->phase . '/method-allow');
        $this->same('0', (string) $this->query('SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema()')->fetchColumn(), $this->phase . '/no-startup-ddl');
        $this->command($this->phase . '/migrate', [PHP_BINARY, $this->root . '/bin/migrate.php', 'migrate', 'examples/document-catalog/migrations'], $environment);
        $this->command($this->phase . '/seed-first', [PHP_BINARY, $this->root . '/examples/document-catalog/seed.php'], $environment);
        $rows = $this->rows();
        $files = $this->fileSnapshot();
        $this->same(26, count($rows), $this->phase . '/seed-total');
        $this->same(26, count($files), $this->phase . '/seed-files');
        $this->command($this->phase . '/seed-second', [PHP_BINARY, $this->root . '/examples/document-catalog/seed.php'], $environment);
        $this->same($rows, $this->rows(), $this->phase . '/seed-idempotent-rows');
        $this->same($files, $this->fileSnapshot(), $this->phase . '/seed-idempotent-files');
        $published = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === 'published'));
        $this->same(24, count($published), $this->phase . '/seed-published');
        foreach (['draft', 'withdrawn'] as $status) {
            $this->same(1, count(array_filter($rows, static fn(array $row): bool => $row['status'] === $status)), $this->phase . '/seed-' . $status);
        }
        $first = $this->request('GET', '/documents', 200);
        $this->same(true, str_contains($first->body, 'Published documents, most recently added first.'), $this->phase . '/list-english');
        $this->same(array_column(array_slice($published, 0, 20), 'id'), $this->listIds($first->body), $this->phase . '/list-first-twenty');
        $next = '/documents?before=' . $published[19]['id'];
        $this->same(true, str_contains($first->body, 'rel="next" href="' . $next . '"'), $this->phase . '/list-next');
        $second = $this->request('GET', $next, 200);
        $this->same(array_column(array_slice($published, 20), 'id'), $this->listIds($second->body), $this->phase . '/list-last-four');
        $this->same(false, str_contains($second->body, 'rel="next"'), $this->phase . '/list-last-no-next');
        $this->same(true, str_contains($second->body, 'href="/documents">Back to the first page'), $this->phase . '/list-back');
        $document = $published[0];
        $detail = $this->request('GET', '/documents/' . $document['id'], 200);
        foreach ([$document['title'], $document['description'], $document['original_name'], gmdate('Y-m-d', (int) $document['published_at'])] as $index => $text) {
            $this->same(true, str_contains($detail->body, $text), $this->phase . '/detail-field-' . $index);
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
        $this->same('{"status":"ok"}', $this->request('GET', '/health', 200)->body, $this->phase . '/health-after-errors');
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
        $this->same($bytes, $get->body, $this->phase . '/pdf-bytes');
        $this->same($this->hash($path), hash('sha256', $get->body), $this->phase . '/pdf-sha256');
        $this->same([$document['file_size']], $get->headers['content-length'] ?? [], $this->phase . '/pdf-length');
        $this->same(['nosniff'], $get->headers['x-content-type-options'] ?? [], $this->phase . '/pdf-nosniff');
        $this->same(['attachment; filename="document-' . $document['id'] . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($document['original_name'])], $get->headers['content-disposition'] ?? [], $this->phase . '/pdf-filename');
        $head = $this->request('HEAD', $target, 200, 'application/pdf');
        $this->same('', $head->body, $this->phase . '/pdf-head-empty');
        foreach (['content-type', 'content-length', 'content-disposition', 'x-content-type-options', 'cache-control'] as $header) {
            $this->same($get->headers[$header] ?? [], $head->headers[$header] ?? [], $this->phase . '/pdf-head-' . $header);
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
        $this->same($bytes, $this->request('GET', $target, 200, 'application/pdf')->body, $this->phase . '/pdf-restored');
    }

    private function request(string $method, string $target, int $status, string $type = 'text/html; charset=utf-8'): HttpWireResponse
    {
        $name = $this->phase . '/' . $method . ' ' . $target;
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
        if ($this->phase === 'archive') {
            array_splice($command, 1, 0, ['-d', 'open_basedir=' . $this->allowedPaths()]);
        }
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

    private function allowedPaths(): string
    {
        if ($this->temporary === null || str_contains($this->root, PATH_SEPARATOR) || str_contains($this->temporary, PATH_SEPARATOR)) {
            throw new RuntimeException('Cannot configure isolated PHP paths');
        }

        return $this->root . PATH_SEPARATOR . $this->temporary;
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
                $this->failure ??= $this->phase . '/cleanup/' . $resource;
                fwrite(STDERR, 'Catalog cleanup failed: ' . $resource . "\n");
            }
        }
        $this->database = null;
    }
}
