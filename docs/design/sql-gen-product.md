# SQL generation contract

Type: `design`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

## Role and inputs

sql-gen generates typed PHP queries/rows around explicit SQL and complements PDO. It does not run during requests. Inputs are [schema](../../sql/schema.sql), [named queries](../../sql/queries), and App\Platform\Storage\Sql passed directly by the runner. No project-profile wrapper remains. Schema input supplies analysis metadata, not migrations; persistence changes must reconcile input, migration, and actual PostgreSQL schema.

## Input and output

```sql
-- name: FindPublished :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id = :id
LIMIT 1;
```

Kinds are :one/:many/:exec, with :param placeholders. [PhpQueryGenerator](../../tools/sql-gen/src/Generator/PhpQueryGenerator.php) emits readonly Query classes with typed constructor/create, sql/params, Row classes for resolved projections, and rowClass. Matching result structures in one SQL file can share a row according to the naming algorithm. Separate Params/facade classes are not generated. Output lives under gen/Generated/Sql and must not be hand-edited.

## Parser and type model

Phplrt parsers use internal ASTs. [Query grammar](../../tools/sql-gen/src/Parser/grammar/sql_subset.pp2), [schema grammar](../../tools/sql-gen/src/Schema/grammar/schema_subset.pp2), and semantic resolvers jointly define support.

| Construct | Supported boundary |
| --- | --- |
| SELECT | Columns/aliases, star/table.star, FROM, joins, WHERE, ORDER BY, final LIMIT |
| Predicates | Column/placeholder comparisons =, <, >, <=, >=; AND/OR |
| INSERT | Explicit columns/placeholders, ON CONFLICT columns DO UPDATE SET column = EXCLUDED.column, RETURNING |
| UPDATE | SET column = :param, optional WHERE/RETURNING; no arithmetic assignments |
| DELETE | DELETE FROM table WHERE ... |
| Expressions | Limited COUNT/MIN/MAX/COALESCE/CASE WHEN with resolver type checks |
| Schema | Limited CREATE TABLE, nullability, primary/unique/foreign keys |

CTEs, subqueries, GROUP BY, OFFSET, arbitrary literals/expressions are outside the grammar. Parsing alone does not establish correct inference.

[PHP types](../../tools/sql-gen/src/Type/PhpTypeFactory.php): TEXT/JSONB → string; INTEGER/BIGINT/BIGSERIAL/SERIAL → int; REAL/DOUBLE/NUMERIC/DECIMAL → float; BOOLEAN/BOOL → bool. JSONB stays text until application decoding. Decimal-to-float is not exact decimal arithmetic. Extra PostgreSQL semantics are not established by this table. Typhoon\Type is the canonical generator model; Typhoon/Nette/Phplrt never load for query execution.

## Bounded SELECT

```sql
-- name: PageBefore :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT 21;
```

An application supplies published status and a positive cursor, shows 20 rows, and uses row 21 to detect continuation. Stable order is explicit SQL; the generator neither adds ordering nor proves uniqueness. No pagination service/profile is needed.

Supported form is SELECT ... [WHERE ...] [ORDER BY ...] [LIMIT N | LIMIT :name] [;], subject to the rest of the subset.

- N is decimal digits, including leading zeros, from 0 to PHP_INT_MAX. Overflow fails before casting: `LIMIT literal must be between 0 and PHP_INT_MAX.`
- A LIMIT parameter is non-null PHP int/PostgreSQL BIGINT without a column. Reuse with integer columns keeps the first JOIN/WHERE comparison's SQL type, matching native prepare. INTEGER/SERIAL retain PostgreSQL integer range; BIGINT/BIGSERIAL retain bigint. Nullable columns do not make LIMIT nullable. TEXT/JSONB/bool/float reuse fails with an integer-compatibility diagnostic.
- Reuse in projection expressions is rejected. PostgreSQL can infer projection argument types before LIMIT; explicit PREPARE(bigint) does not reproduce that inference. General expression typing/runtime casts remain outside this contract.
- Zero returns no rows. PostgreSQL rejects negative values during execution. Generated code adds no runtime bound validation; the caller chooses its positive cap. Null is excluded because PostgreSQL interprets LIMIT NULL as unbounded.
- WHERE/ORDER/LIMIT are optional. :one/:many choose materialization behavior without modifying SQL. :one fetches one PHP row but does not prevent the server/buffered PDO from receiving more rows.

LIMIT ALL/NULL, signs/decimals/exponents/quoted values, column references, expressions/casts/parentheses, duplicate/misplaced LIMIT, OFFSET/comma pagination, and non-SELECT LIMIT fail syntax parsing. Numeric literals were added only for LIMIT, not WHERE.

[CatalogRepository](../../examples/document-catalog/src/CatalogRepository.php) keeps direct SQL with a published literal, unsupported by this grammar. A parameter could affect partial-index use in generic prepared plans, so the application is not rewritten merely to fit generation. [Root input](../../sql/queries/catalog.sql) and [LIMIT fixture](../../tests/Fixtures/SqlLimit/queries/catalog.sql) are separate parameterized examples. The catalog migration owns its actual DDL/index.

LIMIT bounds returned rows, not scanned candidates or row width. The application owns filters, indexes, and field bounds. An index such as (status, id DESC) supports ordered access; LIMIT may still require substantial work without a suitable plan.

Codegen adds an optional AST field, range/type checks, and checker preservation. Generated PHP retains SQL and adds no request-time parsing/library/validation. Queries without LIMIT remain unchanged. Fewer returned rows alone is not a proven speedup.

### Verification and measured cost

[SqlLimitTest](../../tests/Integration/Storage/SqlLimitTest.php) generates separately and executes using real PostgreSQLStorage/SqlExecutor. Cases cover 0/1/20/21/22 rows, pages, published filter, rows/types, 0/1/PHP_INT_MAX parameters, negative refusal, unbounded :one, actual pg_prepared_statements types, comparison order, and integer overflow. Unit checks keep checker inference aligned. Schemas/output are isolated and cleaned.

```bash
php tools/project.php tools:sql:check
php tools/project.php verify
php tools/project.php verify:integration
php tools/project.php sql:check:pg
php tools/sql-gen/bin/sql-check-pg.php --input-dir=tests/Fixtures/SqlLimit/queries --schema=tests/Fixtures/SqlLimit/schema.sql --db-host="$TEST_DB_HOST" --db-port="$TEST_DB_PORT" --db-name="$TEST_DB_NAME" --db-user="$TEST_DB_USER" --db-password="$TEST_DB_PASSWORD"
php tests/Fixtures/SqlLimit/measure.php --rows=50000
```

The measurement creates a random schema with 50,000 rows, 1,032-byte descriptions, 10,000 published rows, index, and ANALYZE. Separate PHP processes read identical projections with/without LIMIT. JSON contains rows, serialized bytes, PHP memory, codegen time, and EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON). Cleanup removes owned schema/output. Direct checker settings are explicitly test-only.

2026-10-18 snapshot: PHP/pdo_pgsql 8.5.9, CLI OPcache off, PostgreSQL 16.15 aarch64 in a cached postgres:16-alpine container with temporary loopback port. One sample per variant; concurrent host tasks were possible.

| Observation | LIMIT 21 | No LIMIT |
| --- | ---: | ---: |
| Rows | 21 | 10,000 |
| JSON bytes | 25,075 | 11,933,347 |
| Live result PHP bytes above baseline | 41,632 | 18,109,336 |
| Peak fetch PHP bytes above baseline | 42,008 | 18,109,712 |
| Top-node shared hit blocks | 19 | 12,930 |

LIMIT used Limit → Index Scan catalog_documents_status_id_idx, rows 21/21, zero shared reads. Unbounded used Gather Merge → Sort → Parallel Seq Scan, 10,000 rows, temp read/write 565/566 blocks. Full generation of the same six fixture queries took 132/135 ms, not an isolated LIMIT parsing delta. Three later reuse regressions make current full generation incomparable. Generator bootstrap was absent from runtime.

PHP peaks precede JSON/EXPLAIN after class loading and exclude libpq/RSS/PostgreSQL. This measures result volume, not overall speed or worker endurance. Large fields still need bounds; unbounded PHP arrays grow with matches.

## Runtime and use

[SqlExecutor](../../src/Platform/Storage/Sql/SqlExecutor.php) executes through Storage and maps with DatabaseRow::fromDatabaseRow. :one returns the first row/null, not a uniqueness guarantee. Storage supplies transactions.

Storage::queryOne returns an associative first row/null in SQL order. All-null rows remain arrays. PDO errors preserve StorageException/PDOException. It executes unchanged SQL, fetches once, and closes the cursor in finally, including execute/fetch failure. It neither adds LIMIT nor limits changed rows/RETURNING. Buffered errors after the first row still fail.

```php
$row = $storage->queryOne(
    'SELECT id, title FROM catalog_documents WHERE id = :id',
    ['id' => 42],
);
```

fetchOne delegates; fetchOneRow maps only that row. query/fetchAll retain full arrays; fetchAllRows also creates typed rows. Catalog lookup retains its SQL/partial index. Storage methods are impure for PHPStan because repeated reads can differ after writes.

Connections/native statements are reused with a 100-SQL cache. closeCursor does not promise native PGresult release; [pdo_pgsql source](https://github.com/php/php-src/blob/PHP-8.5/ext/pdo_pgsql/pgsql_statement.c), [fetch](https://www.php.net/manual/en/pdostatement.fetch.php), and [closeCursor](https://www.php.net/manual/en/pdostatement.closecursor.php) do not establish server LIMIT. Native buffers can survive until reexecution/destruction/eviction. The one-row path removes full PHP arrays while retaining buffered PDO.

Use explicit LIMIT/order for growing lists/nonunique first-row queries and bound row width. Unique lookups already returned small results, so no universal gain is assumed. See the [comparison](../history/2026-10-23-query-one.md).

Custom Storage implementations/decorators must implement queryOne; no query/fetchAll fallback exists. SQLite remains test-only. Unused queryOne adds no execution/reads/cursor close; prior query/execute semantics remain.

SqlGenerationProfile owns contract FQCNs/naming. CLI supports --runtime-namespace with default profile, or --bootstrap/--profile-class for an explicit external profile. Root inputs generate ListPublished/ListPublishedBefore/FindPublished as examples. PostgreSQL fixtures execute generated contracts; removed API repositories are not consumers. Integration must preserve application SQL/plans.

### Parameters and safe publication

Every cached statement execution receives the complete PDO parameter array. Bool becomes PostgreSQL true/false; otherwise PDO converts false to invalid empty text. Null/other scalars retain previous semantics. The linear parameter pass adds no SQL or binding cache.

Query names within a file, modules across files, and final PHP paths are checked case-insensitively before publication. Generation/check report the source conflict rather than selecting a last declaration.

sql:gen builds all artifacts, writes a neighboring staging directory, then replaces the dedicated output. Ordinary parse/type/staging failures preserve old output; failed rename triggers restoration. Success removes retired files. Writer checks complete writes, path boundaries, and output symlinks. Missing/empty input directories fail. Concurrent writers and forced termination between renames are unsupported. Temporary space for old/new trees must share a filesystem. See [ADR](adr-2026-10-28-contract-correctness.md).

## Checks

```bash
php tools/project.php sql:gen
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php tools:sql:check
```

verify:integration checks 0/1/many, null/falsy, statement reuse, RETURNING mutations, SQLSTATE, PHP allocation, and generated mapping using real SQL. sql:check rejects missing/stale/extra PHP. Root sql:check:pg uses TEST_DB_* and an owned sql_gen_check_... schema, trusted DDL, and PREPARE, then cleans up. Direct sql-check-pg accepts --db-*/DB_* separately. tools:sql:check runs style/PHPStan/tests. Default verify is offline; integration/full add PostgreSQL.

PREPARE checks syntax/type resolution, not mutations, data, row mapping, plans, or performance. Those need integration scenarios.

## Limits and future work

Grammar recognizes LEFT/RIGHT/FULL but resolvers reject them by query name until missing-side nullability is implemented. Ordinary/INNER JOIN remain supported. Relation metadata does not prove arbitrary compound conditions. Compatibility tests establish specific cases, not all PostgreSQL.

Keep readable SQL and no ORM/Active Record/query builder or runtime interpretation. Apply the [principles](../project/principles.md) and [roadmap](../project/roadmap.md). New syntax needs coordinated parser/inference/generation/PostgreSQL checks. Outer joins need actual nullability/mapping. More precise expressions, composite relations, and diagnostics follow concrete SQL. Params/facades/new AST layers are justified only by a simpler useful scenario without unreasonable runtime cost. See the [tool guide](../../tools/sql-gen/README.md).
