<?php

declare(strict_types=1);

use App\Platform\Storage\Sql\SqlExecutor;
use Tests\Fixtures\SqlLimit\Fixture;
use Tests\Integration\PostgreSqlTestDatabase;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$options = getopt('', ['worker:', 'schema:', 'rows:']);
if (!is_array($options)) {
    throw new RuntimeException('Cannot read the LIMIT measurement options.');
}
$worker = $options['worker'] ?? null;

if ($worker !== null) {
    $schema = $options['schema'] ?? null;
    if (!in_array($worker, ['bounded', 'unbounded'], true)
        || !is_string($schema) || preg_match('/\Aphpunit_[a-f0-9]{24}\z/', $schema) !== 1) {
        throw new RuntimeException('A measurement worker requires its parent fixture schema and bounded/unbounded mode.');
    }
    $fixture = new Fixture();

    try {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $storage = Fixture::storage($database, $schema);
        $executor = new SqlExecutor($storage);
        $query = $fixture->query(
            $worker === 'bounded' ? 'ListBounded' : 'ListUnbounded',
            ['status' => 'published', 'before' => PHP_INT_MAX, ...($worker === 'bounded' ? ['limit' => 21] : [])],
        );
        $storage->query('SELECT 1');
        $baselineUsed = memory_get_usage(false);
        $baselineAllocated = memory_get_usage(true);
        memory_reset_peak_usage();
        $started = hrtime(true);
        $rows = $executor->fetchAll($query);
        $fetchNanoseconds = hrtime(true) - $started;
        $liveResultBytes = memory_get_usage(false) - $baselineUsed;
        $peakUsedBytes = memory_get_peak_usage(false) - $baselineUsed;
        $peakAllocatedBytes = memory_get_peak_usage(true) - $baselineAllocated;
        $serializedBytes = strlen(json_encode($rows, JSON_THROW_ON_ERROR));
        $explain = $storage->query('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $query->sql(), $query->params());
        $planJson = $explain[0]['QUERY PLAN'] ?? null;
        if (!is_string($planJson)) {
            throw new RuntimeException('PostgreSQL did not return the JSON query plan.');
        }
        echo json_encode([
            'mode' => $worker,
            'codegen_process_wall_ms' => $fixture->generationNanoseconds / 1_000_000,
            'generator_bootstrap_loaded_in_runtime' => class_exists('SqlGen\Application\SqlGenerationService', false),
            'php_version' => PHP_VERSION,
            'pdo_pgsql_version' => phpversion('pdo_pgsql'),
            'opcache_cli' => ini_get('opcache.enable_cli'),
            'sql' => $query->sql(),
            'params' => $query->params(),
            'returned_rows' => count($rows),
            'first_id' => $rows[0]['id'] ?? null,
            'last_id' => $rows === [] ? null : $rows[array_key_last($rows)]['id'],
            'serialized_result_bytes' => $serializedBytes,
            'php_live_result_used_bytes' => $liveResultBytes,
            'php_fetch_peak_used_bytes' => $peakUsedBytes,
            'php_fetch_peak_allocated_bytes' => $peakAllocatedBytes,
            'fetch_wall_ms' => $fetchNanoseconds / 1_000_000,
            'explain_analyze_buffers' => json_decode($planJson, true, 512, JSON_THROW_ON_ERROR),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } finally {
        $fixture->cleanup();
    }

    exit(0);
}

$rowCount = filter_var($options['rows'] ?? '50000', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 10000, 'max_range' => 1000000],
]);
if (!is_int($rowCount)) {
    throw new RuntimeException('--rows must be an integer between 10000 and 1000000.');
}
$database = PostgreSqlTestDatabase::fromEnvironment();
$database->create();

try {
    $storage = Fixture::install($database);
    Fixture::seed($storage, $rowCount);
    $storage->execute("UPDATE catalog_documents SET status = 'draft' WHERE id % 5 <> 0");
    $storage->execute('ANALYZE catalog_documents');
    $reports = [];
    foreach (['bounded', 'unbounded'] as $mode) {
        $reports[$mode] = json_decode(Fixture::run([
            PHP_BINARY,
            __FILE__,
            '--worker=' . $mode,
            '--schema=' . $database->schema,
        ]), true, 512, JSON_THROW_ON_ERROR);
    }
    $version = $storage->query('SELECT version() AS version');
    echo json_encode([
        'total_seed_rows' => $rowCount,
        'published_rows' => intdiv($rowCount, 5),
        'description_bytes_per_row' => 1032,
        'postgresql' => $version[0]['version'] ?? null,
        'measurement' => 'Separate PHP runtime processes, same projection and dataset; PHP peaks exclude result JSON encoding and EXPLAIN. One local sample per case; wall times do not establish a speedup or worker memory stability.',
        'reports' => $reports,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $database->cleanup();
}
