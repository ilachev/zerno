# Components and application boundaries

Type: `architecture`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

[Scope](project/vision.md) defines the shipped system. Components have concrete roles and are connected through ordinary application composition.

| Code | Responsibility | Consumer |
| --- | --- | --- |
| Platform/Runtime | App, request ID, RR loop | Entry points and real HTTP fixtures |
| Platform/Http | Handlers/middleware, errors/JSON, attachments, metadata logging | Core and catalog |
| Platform/Routing | RouteEntry with constructed handlers, method/path dispatch | Core and catalog routes |
| Platform/Storage | PostgreSQL PDO, SQL executor/contracts, transactions/migrations | Catalog and regression tests |
| Platform/Logging | Metadata output, ordinary and RR logger | RequestContext/HttpLogging/errors |
| tools/sql-gen | Parser, inference, PHP generation, PostgreSQL checker | SQL example and regression fixture |

/health and router 404/405 responses open no database, create no cookies, and run no visitor analytics. Constructors assemble middleware and services; HttpLogging reads no body. Bootstrap/autoload registration has a cost, so zero loading overhead is not claimed.

RoadRunner 3.x is the sole HTTP runtime. App is separate from its loop. Default RR body delivery buffers; the catalog selects 8192-byte chunks for seekable files. Verify the [complete transport path](development/http-and-baseline.md) before claiming bounded memory.

## Application boundary

The catalog owns Document, publication states, private FileStore, PDF rules, HTML, and cache policy. CatalogRepository holds direct SQL constants. sql/queries/catalog.sql is a separate generator example with a status parameter and its own column model. Platform knows neither the catalog table nor editor permissions.

A helper should remove repeated mechanics from a current scenario. Handlers need no compulsory repository/mapper/service hierarchy. Authorization, sessions, analytics, and editing are absent rather than hidden in a disabled profile.

The catalog's application.php composes Logger/Storage/private directory → repository/HTML/files → handlers → routes/pipeline. Migrations build a separate CLI graph. Contract changes describe developer steps, behavior, cost, resources, and checks according to the [principles](project/principles.md).
