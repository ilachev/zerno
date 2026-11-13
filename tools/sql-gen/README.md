# SQL PHP generator

Type: `tool-guide`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

sql-gen creates typed queries/rows from [schema](../../sql/schema.sql) and [named SQL](../../sql/queries). Generation runs separately and is unnecessary for executing ready queries.

## Run

From the repository root:

```bash
php tools/project.php sql:gen
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php tools:sql:check
```

sql:gen stages a full set and replaces dedicated gen/Generated/Sql only after writing. Failure preserves prior output; success removes retired files. Do not generate concurrently or while an application executes that directory. sql:check rejects missing/stale/extra PHP offline. sql:check:pg uses TEST_DB_* and an isolated schema for PREPARE without executing queries. Tool checks run style/PHPStan/tests.

## Input and output

```sql
-- name: FindPublished :one
SELECT id, title
FROM catalog_documents
WHERE status = :status AND id = :id
LIMIT 1;
```

Markers are :one/:many/:exec. Query names, modules, and final paths must not collide case-insensitively. Input must contain a SQL file. Output is readonly Query with typed constructor/create, sql/params, and rowClass for typed rows. No Params/facade classes are generated.

The subset includes SELECT/INNER JOIN/WHERE/ORDER/LIMIT, INSERT placeholders/upsert/RETURNING, UPDATE placeholder assignment/RETURNING, and DELETE WHERE. See the [contract](../../docs/design/sql-gen-product.md) for grammar and explicit LEFT/RIGHT/FULL refusal until correct nullability. Full PostgreSQL is unsupported. Catalog uses direct published-literal SQL for a partial index; generator examples use parameterized status. Application migrations own runtime DDL.

Ready queries use [runtime contracts](../../src/Platform/Storage/Sql), without Nette/Phplrt/Typhoon.

## SELECT LIMIT

```sql
-- name: PageBefore :many
SELECT id, title FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT :limit;
```

With matching schema, PageBeforeQuery::create(status: 'published', before: $before, limit: 21) passes typed parameters to PDO without interpolation. A fixed LIMIT 21 needs no parameter. The application shows twenty rows and checks row twenty-one.

Decimal literals 0..PHP_INT_MAX allow leading zeros. Named LIMIT parameters are non-null int/BIGINT. Reuse with integer columns retains the first JOIN/WHERE comparison's SQL type/range, rather than silently widening INTEGER. Other-type reuse fails; nullable integer columns do not make LIMIT nullable. Zero is empty; PostgreSQL rejects negative parameters. Callers choose page caps.

LIMIT follows WHERE/ORDER before optional semicolon. ALL/NULL, signs/fractions/exponents, expressions/casts/parentheses, column limits, repeated/misplaced clauses, OFFSET/comma pagination, and DML LIMIT fail. Projection reuse also fails because native prepare may infer differently from explicit checker types. Overflow reports `LIMIT literal must be between 0 and PHP_INT_MAX.` :one adds no SQL limit; order needs ORDER BY.

Extra work stays in codegen; runtime gains no libraries/guards. LIMIT bounds returned rows, not scanning/row width. See [bounded SELECT](../../docs/design/sql-gen-product.md#bounded-select) and [measurement](../../docs/design/sql-gen-product.md#verification-and-measured-cost).

verify:integration includes [LIMIT regressions](../../tests/Integration/Storage/SqlLimitTest.php). With separate TEST_DB_*, `php tests/Fixtures/SqlLimit/measure.php --rows=50000` outputs plans/buffers, rows, bytes, PHP memory, and full generation time, then cleans owned schemas/output.

## Direct CLI and profiles

bin/sql-gen.php and bin/sql-check.php accept:

| Option | Purpose |
| --- | --- |
| --input-dir | Named SQL directory |
| --output-dir | Generated PHP directory |
| --namespace | Generated base namespace |
| --schema | Type-resolution schema |
| --runtime-namespace | Default-profile runtime contracts |
| --bootstrap / --profile-class | External SqlGenerationProfile |

The first four are required. An external profile owns naming/contracts. Root passes App\Platform\Storage\Sql directly without a wrapper. Use a custom profile only for a concrete consumer's policy.

bin/sql-check-pg.php accepts input/schema and --db-host/port/name/user/password; direct fallback uses DB_*. Root maps TEST_DB_* separately.

## Autonomous distribution

[Bootstrap](bootstrap.php) loads the [bundled distribution](../../third_party/codegen/sql/autoload.php). Its [manifest](../../third_party/codegen/sql/manifest.json) records versions/refs/licenses/SHA-256 and full upstream packages/notices. No installation is needed.

Keep generator/runtime bootstraps in separate PHP processes. Future ideas belong to the [design](../../docs/design/sql-gen-product.md); priorities belong to the [roadmap](../../docs/project/roadmap.md).
