<?php

declare(strict_types=1);

namespace Tests\Integration\Examples\DocumentCatalog;

use App\Platform\Http\Request;
use App\Platform\Http\Response;
use App\Platform\Logging\Logger;
use App\Platform\Logging\ReadableOutputLogger;
use App\Platform\Runtime\App;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\Storage;
use App\Platform\Storage\StorageException;
use Examples\DocumentCatalog\DocumentId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class CatalogApplicationTest extends TestCase
{
    private ?PostgreSqlTestDatabase $database = null;

    private ?string $directory = null;

    /** @var array<string, string|false> */
    private array $environment = [];

    private App $app;

    private MigrationService $migrations;

    private CatalogRecordingStorage $storage;

    /** @var list<int> */
    private array $published = [];

    /** @var list<int> */
    private array $hidden = [];

    /** @var array<int, string> */
    private array $keys = [];

    private string $pdf;

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();
        $this->directory = sys_get_temp_dir() . '/zerno-catalog-' . bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0o700)) {
            throw new \RuntimeException('Cannot create the catalog test file directory.');
        }
        foreach ([...$this->database->applicationEnvironment(), 'CATALOG_FILES_DIR' => $this->directory] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        /** @var Storage $storage */
        $storage = require \dirname(__DIR__, 4) . '/config/storage.php';
        $this->storage = new CatalogRecordingStorage($storage);
        $logger = new ReadableOutputLogger();
        $this->migrations = new MigrationService(
            $this->storage,
            new MigrationRepository($this->storage),
            new MigrationLoader(\dirname(__DIR__, 4) . '/examples/document-catalog/migrations', $logger),
        );
        $this->migrations->loadMigrations();
        $this->migrations->migrate();
        /** @var \Closure(Logger, Storage, string): App $compose */
        $compose = require \dirname(__DIR__, 4) . '/examples/document-catalog/application.php';
        $this->app = $compose($logger, $this->storage, $this->directory);
        $this->pdf = self::pdf();
        $this->storage->transaction(function (): void {
            foreach (range(1, 47) as $number) {
                $status = match ($number) {
                    46 => 'draft',
                    47 => 'withdrawn',
                    default => 'published',
                };
                $key = hash('sha256', 'catalog-fixture-' . $number) . '.pdf';
                $rows = $this->storage->query(
                    <<<'SQL'
                        INSERT INTO catalog_documents
                            (title, description, status, published_at, storage_key, original_name, file_size)
                        VALUES (:title, :description, :status, :published_at, :storage_key, :original_name, :file_size)
                        RETURNING id
                        SQL,
                    [
                        'title' => $number === 45 ? '<script>alert("title")</script>' : $status . '-document-' . $number,
                        'description' => '<img src=x onerror="alert(1)">',
                        'status' => $status,
                        'published_at' => 1792195200 + $number,
                        'storage_key' => $key,
                        'original_name' => 'Отчёт "итоги".pdf',
                        'file_size' => \strlen($this->pdf),
                    ],
                );
                $id = DocumentId::parse($rows[0]['id'] ?? null);
                if ($id === null || file_put_contents($this->directory . '/' . $key, $this->pdf) !== \strlen($this->pdf)) {
                    throw new \RuntimeException('Cannot create a catalog document fixture.');
                }
                $this->keys[$id] = $key;
                if ($status === 'published') {
                    $this->published[] = $id;
                } else {
                    $this->hidden[] = $id;
                }
            }
        });
        $this->storage->queries = [];
    }

    protected function tearDown(): void
    {
        try {
            $this->database?->cleanup();
        } finally {
            foreach ($this->environment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            if ($this->directory !== null) {
                foreach (glob($this->directory . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($this->directory);
            }
        }
    }

    public function testPublishedPagesAreBoundedAndHaveStableKeysetNavigation(): void
    {
        $all = [];
        $query = [];
        foreach ([20, 20, 5] as $page => $expectedCount) {
            $this->storage->queries = [];
            $response = $this->request('GET', '/documents', $query);
            self::assertSame(200, $response->getStatusCode());
            $body = (string) $response->getBody();
            preg_match_all('~<h2><a href="/documents/([0-9]+)">~', $body, $matches);
            $ids = array_map('intval', $matches[1]);
            self::assertCount($expectedCount, $ids);
            self::assertCount(1, $this->storage->queries);
            self::assertSame($page < 2 ? 21 : 5, $this->storage->queries[0]['rows']);
            self::assertStringNotContainsString('draft-document-', $body);
            self::assertStringNotContainsString('withdrawn-document-', $body);
            $all = [...$all, ...$ids];
            $hasNext = preg_match('~rel="next" href="/documents\?before=([0-9]+)"~', $body, $next);
            self::assertSame($page < 2 ? 1 : 0, $hasNext);
            $query = $hasNext === 1 ? ['before' => $next[1]] : [];
        }
        self::assertSame(array_reverse($this->published), $all);
        self::assertCount(45, array_unique($all));
    }

    #[DataProvider('provideInvalidCursorFailsWithoutAQueryCases')]
    public function testInvalidCursorFailsWithoutAQuery(mixed $cursor): void
    {
        $response = $this->request('GET', '/documents', ['before' => $cursor]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $this->storage->queries);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideInvalidCursorFailsWithoutAQueryCases(): iterable
    {
        yield 'zero' => ['0'];
        yield 'array' => [['1']];
        yield 'overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'SQL text' => ['1 OR 1=1'];
    }

    public function testCardEscapesAllTextAndDoesNotStartASession(): void
    {
        $response = $this->request('GET', '/documents/' . $this->published[44]);
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->storage->queries);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', $body);
        self::assertStringContainsString('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;', $body);
        self::assertStringContainsString('Отчёт &quot;итоги&quot;.pdf', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('<img', $body);
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testDownloadStreamsExactContentWithExplicitHeadersAndHead(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $this->storage->queries = [];
            $response = $this->request($method, '/documents/' . $this->published[0] . '/download');
            self::assertSame(200, $response->getStatusCode());
            self::assertCount(1, $this->storage->queries);
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertSame((string) \strlen($this->pdf), $response->getHeaderLine('Content-Length'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertStringStartsWith('attachment; filename="document-' . $this->published[0] . '.pdf";', $response->getHeaderLine('Content-Disposition'));
            self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('Отчёт "итоги".pdf'), $response->getHeaderLine('Content-Disposition'));
            self::assertFalse($response->hasHeader('Set-Cookie'));
            self::assertSame($method === 'GET' ? $this->pdf : '', (string) $response->getBody());
            $response->getBody()->close();
        }
        $range = $this->request('GET', '/documents/' . $this->published[0] . '/download', [], ['Range' => 'bytes=0-9']);
        self::assertSame(200, $range->getStatusCode());
        self::assertFalse($range->hasHeader('Content-Range'));
        self::assertSame($this->pdf, (string) $range->getBody());
        $range->getBody()->close();
    }

    public function testHiddenMissingAndMalformedIdsDoNotExposeMetadataOrPaths(): void
    {
        foreach ([...$this->hidden, '999999', '0', '01', (string) PHP_INT_MAX . '0', '1%20OR%201=1'] as $id) {
            foreach (['', '/download'] as $suffix) {
                $response = $this->request('GET', '/documents/' . $id . $suffix);
                self::assertSame(404, $response->getStatusCode());
                self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
                $body = (string) $response->getBody();
                self::assertStringNotContainsString('draft-document-', $body);
                self::assertStringNotContainsString('withdrawn-document-', $body);
                self::assertStringNotContainsString((string) $this->directory, $body);
                self::assertStringNotContainsString($this->keys[$this->hidden[0]], $body);
            }
        }
    }

    public function testWithdrawalIsImmediatelyVisibleInTheSameApp(): void
    {
        $id = $this->published[44];
        self::assertSame(200, $this->request('GET', '/documents/' . $id)->getStatusCode());
        $this->storage->execute('UPDATE catalog_documents SET status = :status WHERE id = :id', ['status' => 'withdrawn', 'id' => $id]);
        foreach (['', '/download'] as $suffix) {
            self::assertSame(404, $this->request('GET', '/documents/' . $id . $suffix)->getStatusCode());
        }
        self::assertStringNotContainsString('href="/documents/' . $id . '"', (string) $this->request('GET', '/documents')->getBody());
    }

    public function testMissingAndTruncatedFilesReturn404(): void
    {
        unlink($this->directory . '/' . $this->keys[$this->published[0]]);
        file_put_contents($this->directory . '/' . $this->keys[$this->published[1]], substr($this->pdf, 0, -1));
        foreach ([$this->published[0], $this->published[1]] as $id) {
            $response = $this->request('GET', '/documents/' . $id . '/download');
            self::assertSame(404, $response->getStatusCode());
            self::assertStringNotContainsString((string) $this->directory, (string) $response->getBody());
            self::assertFalse($response->hasHeader('Content-Disposition'));
        }
    }

    public function testRouterErrorsAreHtmlAnd405PreservesAllow(): void
    {
        $missing = $this->request('GET', '/unknown');
        $method = $this->request('POST', '/documents');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(405, $method->getStatusCode());
        self::assertSame('GET, HEAD', $method->getHeaderLine('Allow'));
        foreach ([$missing, $method] as $response) {
            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertFalse($response->hasHeader('Set-Cookie'));
        }
        self::assertSame([], $this->storage->queries);
    }

    public function testMigrationsRepeatWithoutApiTablesAndRequirePublishedMetadata(): void
    {
        $this->migrations->migrate();
        $tables = $this->storage->query('SELECT tablename FROM pg_tables WHERE schemaname = :schema ORDER BY tablename', ['schema' => $this->database?->schema]);
        self::assertSame([['tablename' => 'catalog_documents'], ['tablename' => 'migrations']], $tables);
        self::assertCount(1, $this->storage->query('SELECT version FROM migrations'));
        $this->expectException(StorageException::class);
        $this->storage->execute("INSERT INTO catalog_documents (title, status) VALUES ('No file', 'published')");
    }

    /** @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $query = [], array $headers = []): Response
    {
        return $this->app->handle(new Request($method, 'http://catalog.test' . $path, $headers)->withQueryParams($query));
    }

    private static function pdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer << /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

final class CatalogRecordingStorage implements Storage
{
    /** @var list<array{sql: string, rows: int}> */
    public array $queries = [];

    public function __construct(private readonly Storage $storage) {}

    public function query(string $sql, array $params = []): array
    {
        $rows = $this->storage->query($sql, $params);
        $this->queries[] = ['sql' => $sql, 'rows' => \count($rows)];

        return $rows;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->storage->queryOne($sql, $params);
        $this->queries[] = ['sql' => $sql, 'rows' => $row === null ? 0 : 1];

        return $row;
    }

    public function execute(string $sql, array $params = []): bool
    {
        return $this->storage->execute($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->storage->transaction($callback);
    }

    public function lastInsertId(): string
    {
        return $this->storage->lastInsertId();
    }
}
