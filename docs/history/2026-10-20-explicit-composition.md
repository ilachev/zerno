# Explicit application composition without a container

Type: `history`. Status: `historical`. Date: 2026-10-20. English edition: 2026-11-13.

The user endorsed vertical slices/Unix principles and requested removal of the dependency container. Before: e6ae4f66b6940c850454af2c269b01a8965ff274. [Data](2026-10-20-explicit-composition.json) contains counts/probe/fingerprints. [Architecture](../architecture/overview.md) owns current behavior.

## Result and developer steps

Removed Container/DIContainer, autowiring plans, providers, class-name factories, route definitions/collectors, and duplicate HandlerInterface. App receives a handler; config/core.php returns Closure(Logger): App. Entry points select logger/transport and invoke it. Router receives RouteEntry objects with constructed handlers. Own runtime files fell from 72 to 49; this is not a speed metric.

Catalog application.php constructs repository/Html/FileStore/handlers from Logger/Storage/private directory. GET/HEAD share handlers. Tests use the same function with real dependencies. No override registry or generic resolver remains; policy stays outside the composition root without compulsory layers.

PDO creation moved to the first SQL/transaction/lastInsertId operation, with schema setup and reuse. Objects are built upfront without SQL. Core creates no Storage; /health/404/405 remain database-independent. Connection errors now occur at first use. Statement cache/contracts remain.

CLI migrate takes a directory rather than HTTP config, validates before connection, constructs its graph explicitly, and directly loads files. Default is src/Migrations; catalog passes its directory. This breaks CLI/App/RouteEntry contracts without shims.

At this snapshot both HTTP transports shared APP_CONFIG; RR still used PSR17/PSR7 adaptation. Default chunk 0 buffered; catalog/fixtures chose 8192. Tests moved from deleted providers to actual composition. Future generated endpoints were required to use constructors/routes without a container.

## Costs and bounded comparison

The graph became visible without reflection/bindings/name lookup. Handlers were eagerly created, PDO lazy. Config/class reads and object construction still cost work.

PHP 8.5.9 CLI, OPcache off: discard one process per variant, then seven alternating process pairs. Measure bootstrap/composition followed by 10,000 in-process GET /health calls with identical ID, status/body checks, and stream close. No network/database/emission.

| Median | Before | After |
| --- | ---: | ---: |
| Bootstrap/composition ms | 1.816 | 1.045 |
| 10,000 handle/check/close ms | 53.266 | 52.641 |
| Loaded classes | 325 | 316 |
| PHP bytes after construction | 672,768 | 594,360 |

PHP memory is not RSS. Small hot-loop differences establish no HTTP throughput/endurance gain. Other host tasks could interfere. To reproduce, extract probe_source from JSON and run separate processes with php -d opcache.enable_cli=0 probe.php ROOT before for the specified snapshot and the corresponding after code, retaining ordering/sample counts.

## Verification

verify:full: 200 unit/804 assertions, 167 SQL-tool/625, 32 PostgreSQL/272; PHPStan 10, three distributions/1,661 files, synchronized SQL. Removed tests followed removed code; retained streaming and added ready-handler, direct migration-loading, and first-transaction checks.

Actual FPM/RR passed 188 cases. Another 43 smoke cases covered default startup, packaged catalog, source JSON/SELECT. Archive checks included migrate/rollback/reapply/seed without application namespace registration, no startup DDL, twenty-one generated rows via explicit SqlExecutor, and bad CLI arguments before connection. Runtime-only manifest, no container or separate RR profiles. Results remain historical and establish neither readiness nor a runtime winner.
