# Implemented architecture

Type: `architecture`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

The system contains an HTTP/PDO core, SQL/API generation, a catalog example, and generated Echo. [Scope](../project/vision.md), [coding guidelines](../development/coding-guidelines.md), and [composition history](../history/2026-10-20-explicit-composition.md) explain the boundaries. Containers, autowiring, and providers are removed.

## Composition

[App](../../src/Platform/Runtime/App.php) receives a constructed RequestHandler and delegates handle(). It reads no configuration, resolves no services, and owns no server loop. [Pipeline](../../src/Platform/Http/Middleware/Pipeline.php) executes supplied middleware:

```text
RequestContext → ErrorHandler → Routing → RouteHandlerResolver → constructed handler
```

[config/core.php](../../config/core.php) returns Closure(Logger): App and explicitly constructs pipeline, router, errors, and context. [Routes](../../config/routes.php) provide a HealthHandler for GET/HEAD /health. [Router](../../src/Platform/Routing/Router.php) accepts list<RouteEntry> and returns the supplied handler; there is no class-name lookup. RouteHandlerResolver validates the RequestHandler attribute and invokes it.

The [catalog application](../../examples/document-catalog/application.php) is a Closure(Logger, Storage, string): App. It constructs CatalogRepository, Html/Errors, FileStore, and list/detail/download handlers, passing them to [routes](../../examples/document-catalog/routes.php). Its [config](../../examples/document-catalog/config.php) chooses database settings/private directory. No generic service bag or hidden resolver exists. Simple operations need no compulsory layer hierarchy.

## Transport and resources

[public/index.php](../../public/index.php) is the sole HTTP entry point. It passes RoadRunnerLogger to APP_CONFIG, constructs native transport, and runs RoadRunnerRuntime. APP_CONFIG defaults to config/core.php. CLI commands build separate graphs. `bin/migrate.php upgrade-metadata` explicitly upgrades migrations.executed_at to BIGINT; ordinary migrate/HTTP performs no such ALTER.

The platform target is RoadRunner 3.x, supplied separately. HTTP floors are upstream PHP 8.5.12+ and RR 3.0.1+; [security checks](../development/security.md) are separate from HTTP correctness. Bundled native sources: worker 3.8.0, HTTP 4.2.1, Goridge 4.6.0, API DTO 2.1.0. Upstream namespaces remain unchanged.

RoadRunnerRuntime::createWorker builds [RoadRunnerHttpWorker](../../src/Platform/Runtime/RoadRunnerHttpWorker.php) over native HttpWorker without PSR adapters. RR_HTTP_CHUNK_SIZE defaults to 0, materializing the body; catalog/fixtures select 8192. Chunk delivery retains current and next chunks. Seekable bodies rewind; others read from their current position. Native completion runs after generator failure, then the adapter rethrows the captured read error. Client cancellation and arbitrary-stream endurance are not fully covered.

[Request](../../src/Platform/Http/Request.php), [Response](../../src/Platform/Http/Response.php), [Body](../../src/Platform/Http/Body.php), [Uri](../../src/Platform/Http/Uri.php), and [UploadedFile](../../src/Platform/Http/UploadedFile.php) are concrete core types. Handlers accept Request and return Response. Metadata copies use with-methods; Body position/ownership is shared. String bodies open no php://temp resource; file bodies own supplied resources. Nyholm and PSR HTTP/factory/log packages are removed.

RequestContext holds the request ID. Middleware adds X-Request-ID, X-Response-Time, and Server-Timing. ErrorHandler/Routing receive ErrorResponse directly: JSON in core, HTML in catalog. Private Throwable details stay in logs. RR logs.level=error with channels.server.level=info preserves PHP stderr; the production server envelope adds time/uppercase level without changing PHP Logger. HttpLogging is explicitly composed and never reads payload.

AttachmentResponse sets safe headers and transfers the same body. RoadRunnerRuntime closes response bodies after sending or failure, including HEAD. App::handle sends/closes nothing. See [lifecycle](request-lifecycle.md).

## PostgreSQL and CLI

[config/storage.php](../../config/storage.php) constructs PostgreSQLStorage from DB_HOST/PORT/NAME/USER/PASSWORD/SCHEMA. Construction opens no PDO. The first query/queryOne/execute/transaction/lastInsertId connects and sets a quoted search_path; PDO is retained only after successful initialization. Connection failure occurs at that operation. The prepared-statement cache is bounded to 100 SQL strings.

Core HTTP creates no Storage. Catalog constructs its graph without connecting; /health/404/405 run no SQL. This still costs configuration reads and object construction. query uses fetchAll; queryOne fetches one row and closes the cursor. SqlExecutor.fetchOne and catalog lookups use queryOne. SQL is unchanged and statements are reused. The shared execution path normalizes boolean parameters and supplies the complete binding array on every call. Native libpq buffers may remain after closeCursor; explicit LIMIT controls returned rows. See [SQL semantics](../design/sql-gen-product.md).

Storage.transaction owns one transaction, rejects nesting, and retains SQL failures caught inside callbacks until rollback. Cleanup after failed commit preserves the original SQLSTATE. TransactionRollbackException retains operation and rollback failures. The [transaction contract](../design/transactions-and-migrations.md) covers connection loss and limits.

[bin/migrate.php](../../bin/migrate.php) validates action/directory arguments before connecting and explicitly builds Storage → Repository/Loader → Service → Command. Default src/Migrations is empty; catalog passes its own directory. Loader requires directory files directly, without application namespace registration. up/down return lists of full SQL statements. Each version and history row share a transaction; duplicate versions fail. Only explicit CLI construction creates the history table; HTTP does no DDL.

[SQL queries](../../sql/queries/catalog.sql)/[schema](../../sql/schema.sql) are generator examples. The runner sets the runtime namespace directly. Catalog retains published-literal SQL for its partial index. Generated query execution loads no codegen libraries.

## Lifetimes and checks

Composition owns the graph, created once per RR worker. Handlers may share repositories/storage; per-request state travels in attributes. There is no automatic service reset. Do not retain requests, bodies, or user data across calls. PDO laziness does not remove the rest of the graph.

[Distributions](../design/autonomous-core.md) are autonomous. verify needs no external services; verify:full adds SQL tools/PostgreSQL. Real RR HTTP checks run separately. Historical comparisons are bounded evidence, not deployment certification.

## Generated API

[Contract generation](../design/protobuf-contract-generation.md) supports proto3 string messages and unary POST JSON. tools/proto-gen reads a saved descriptor and generates readonly DTOs, an endpoint, and an adapter with route(). Platform protoc is required for full compilation. The [example](../../examples/contract-echo/config.php) constructs a manual endpoint and generated adapter. Runtime archives carry PHP without contracts/tools; /health loads no DTO/codec or database.
