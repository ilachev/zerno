# Infrastructure and verification

Type: `operations`. Status: `current`. Reviewed against code: 2026-11-13.

The [PHP runner](../../tools/project.php) is the command interface; no package installation or Task is needed. CLI checks require PHP 8.5+; HTTP requires upstream PHP 8.5.12+, PDO, sockets, and RR 3.0.1+ in the 3.x line. PostgreSQL needs pdo_pgsql. Unit/tool checks need ctype/dom/json/libxml/mbstring/tokenizer/xml/xmlwriter and pdo_sqlite for migration fixtures. SQLite is not an application backend and is excluded from runtime.

Select RR through PATH or http:check --rr=/path/to/rr. Load checks require k6. Docker supplies the local PostgreSQL service; a separately prepared PHP container can be a test platform. Dependencies/tools are bundled; checks download no executables.

## Commands

| Command | Behavior |
| --- | --- |
| verify | Integrity → docs → style → PHPStan 10 → SQL/API sync → root unit tests |
| verify:integration | sql:check:pg → PostgreSQL tests |
| verify:full | verify → tools:sql:check → verify:integration |
| proto:gen / proto:compile:check | Compile/full-compare Echo using platform protoc |
| proto:check | Source/descriptor hashes and exact PHP comparison, no compiler |
| tools:sql:check | SQL tool style, PHPStan, tests |
| http:check | Actual RR3, JSON/HTML errors and files with metadata logging |
| catalog:check --rr=PATH --report=var/catalog-check.json | Source catalog: isolated TEST_DB_* schema, migration/seed CLI, RR HTML/PDF/errors and cleanup; explicit platform required |
| security:inventory | Offline package versions from manifests |
| security:audit | Online OSV/PHP release policy; advisory data only |
| security:platform --rr=PATH | Current PHP and exact RR binary via bundled govulncheck |
| perf:soak | Mixed k6 run against prepared SQL/files and supported RR |
| build VERSION | Integrity and runtime archive; does not replace quality checks |
| services:start | PostgreSQL Docker service, --pull never, no migrations |
| services:stop / services:status | Compose stop/status |
| run | RR core via .rr.yaml; /health needs no database |
| migrate [action] [directory] | migrate/rollback; upgrade-metadata takes no directory |

Use `php tools/project.php help` for the full interface. protoc 26.1 was checked for full compilation. No cache/legacy load commands, Task aliases, Redis service, or OpenAPI sidecar remain. CI/release run verify:full/build and separate [security gates](security.md). CI's security job prepares a pinned RoadRunner source rebuild with corrected Go/dependencies before auditing that actual executable and checking HTTP; see the [platform recipe](security.md#pinned-security-rebuild-from-2026-11-13). Only this external platform provisioning downloads tools/modules; verify itself remains offline.

## Application database

config/storage.php constructs PostgreSQLStorage from DB_HOST/PORT/NAME/USER/PASSWORD/SCHEMA. The first SQL/transaction/lastInsertId opens PDO. STORAGE_ENGINE/backend selection is removed. DB_SCHEMA is one quoted identifier, not a search_path list.

Migrations return full SQL statements in arrays. Each version and history row are atomic; rerun the CLI explicitly after correcting failure. The [contract](../design/transactions-and-migrations.md) covers nesting, original errors, connection loss, and DDL limits. src/Migrations is empty; the catalog supplies its directory. Only explicit MigrationRepository construction creates history. HTTP/services:start run no migration. Existing legacy user tables are not removed.

## Unit and integration profiles

[phpunit.xml](../../phpunit.xml) selects tests/Unit. Real internal components run without an external database. tests/Fixtures/SQLiteStorage uses actual PDO SQLite solely for migration lifecycle and closed HTML error-path tests. It is excluded from production. RR tests double the external WorkerInterface and use real adapter/native HttpWorker. ObservedStream records actual stream reads/seeks/closes.

[phpunit.integration.xml](../../phpunit.integration.xml) selects tests/Integration. Bootstrap loads only test/runtime autoload, with no shared API App, migrations, or global schema. Each PostgreSqlTestDatabase owns a random phpunit_<24 hex> schema. Catalog tests own temporary private files; LIMIT fixtures generate in a separate process. SqlExecutor/generated rows use real PostgreSQL. QueryOneTest checks one-row semantics, SQLSTATE/reuse, and PHP-array allocation; its counter excludes libpq buffers/RSS.

The runner reads TEST_DB_HOST/PORT/NAME/USER/PASSWORD independently of DB_*. Direct PHPUnit requires TEST_DB_NAME. The test role needs permission to create/drop its schemas. For a separately prepared test database:

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:full
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php
php tools/project.php tools:sql:check
```

[PostgreSqlTestDatabase](../../tests/Integration/PostgreSqlTestDatabase.php) never chooses runtime DB and drops only a schema it successfully created. Tests preserve public sentinel/neighbor schemas. sql:check:pg creates a separate sql_gen_check_<random>. Cleanup after forced termination is not guaranteed. Never delete other owners' schemas/containers.

## HTTP, load, delivery

The [HTTP guide](http-and-baseline.md) covers real listeners, temporary ports, body/hash/HEAD checks, and memory limits. A Body object or a unit test alone proves no full-pipeline memory bound. Short runs establish neither a runtime winner nor long-term stability.

The [catalog source walkthrough](../../examples/document-catalog/README.md#reproduce-the-source-scenario) joins the actual application, CLI setup, PostgreSQL and RR. Unlike the integration-test profile's defaults, catalog:check requires all five TEST_DB_HOST/PORT/NAME/USER/PASSWORD values explicitly. It additionally needs PCNTL/POSIX and DOM, owns isolated schemas/files/process groups, and returns nonzero for scenario, deadline or cleanup failures. Its optional JSON records source-only coverage and checked input hashes. It runs separately from verify/verify:integration; extracted-archive execution and CI invocation are the remaining ADR slices.

Distribution changes check SQL inputs, migration loading without application autoload, HTTP/RR, and extracted archives. [Historical reports](../README.md#historical-evidence) describe previous compositions and do not instruct restoring removed APIs.
