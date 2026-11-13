# LLM onboarding

Type: `navigation`. Status: `current`. Reviewed: 2026-11-13.

Zerno is an [engineering portfolio](portfolio/overview.md) backed by a working autonomous PHP HTTP/PDO core. [AGENTS.md](../AGENTS.md) defines working rules; [principles](project/principles.md) define technical decision criteria. Communicate with the owner in Russian; write project documentation and example UI in English. Preserve multilingual protocol fixtures and raw historical data.

## Start a task

1. Read AGENTS, [coding guidelines](development/coding-guidelines.md), and principles. Inspect git status and relevant code/tests.
2. Find the canonical document through the [index](README.md) and [inventory](documentation-inventory.json).
3. Deliver a bounded result for a concrete consumer, with explicit SQL and resource lifetimes.
4. Update documentation and run verify. Use PostgreSQL integration for database behavior, SQL-tool checks for generation, and actual HTTP for transport.

Plans do not establish implementation. Historical audits describe past runs. Attribute human/agent work accurately and do not invent productivity or adoption claims. Follow the [workflow](portfolio/workflow.md).

## Task map

| Task | Read | Code |
| --- | --- | --- |
| Portfolio and attribution | [Overview](portfolio/overview.md), [workflow](portfolio/workflow.md) | docs/portfolio, README.md |
| HTTP, routing, composition | [Architecture](architecture/overview.md), [lifecycle](architecture/request-lifecycle.md) | config/core.php, public/index.php, src/Platform |
| Bounded JSON | [Manual POST](../examples/json-echo/README.md) | Body::readLimited, examples/json-echo, tools/check-http.php |
| API contracts | [Protobuf scope](design/protobuf-contract-generation.md) | contracts/echo.proto, tools/proto-gen, gen/Generated/Echo |
| Transactions/migrations | [Recovery contract](design/transactions-and-migrations.md) | AbstractStorage, MigrationService, bin/migrate.php |
| SQL generation | [Contract](design/sql-gen-product.md), [tool guide](../tools/sql-gen/README.md) | sql, tools/sql-gen, src/Platform/Storage |
| Security | [Monitoring](development/security.md) | tools/security, .github/workflows/security.yml |
| Testing | [Infrastructure](development/infrastructure-and-testing.md) | tools/project.php, tests/Integration |
| RoadRunner/files/load | [HTTP guide](development/http-and-baseline.md) | tools/check-http.php, tools/http-fixtures, load-tests |
| Delivery | [Architecture](design/autonomous-core.md), [bundled sources](../third_party/README.md) | bootstrap.php, third_party/manifest.json |
| Catalog | [Run](../examples/document-catalog/README.md), [boundaries](project/document-catalog-example.md) | examples/document-catalog |
| Plans/documentation | [Roadmap](project/roadmap.md), [maintenance](development/documentation.md) | Canonical fact owner |

## Current implementation and limits

Concrete HTTP types, explicit routes/composition, errors, attachments, request IDs/metadata logs, PostgreSQL PDO/SQL/transactions/migrations, SQL generation, and RoadRunner 3.x are shipped. Containers/autowiring/providers/class-name resolution are removed. config/core.php returns Closure(Logger): App; public/index.php builds logger/transport and selects APP_CONFIG. CLI migrations build a separate graph.

The catalog composes Logger/Storage/private directory without SQL during construction. PDO connects at the first SQL operation, transaction, or lastInsertId. /health/404/405 do not need a database. HttpLogging never reads the body; RoadRunnerRuntime closes the body after sending, including HEAD/errors. App::handle does not close it. RR chunk 8192 is explicit in file fixtures/catalog; default 0 buffers.

HTTP needs PHP 8.5.12+ and RR 3.0.1+ in the 3.x line, plus sockets. RR is selected through PATH, not downloaded by project commands. The native HttpWorker logger-removal patch is recorded in the manifest.

PostgreSQL is the only application target. SQLiteStorage exists solely in migration unit fixtures and is excluded from runtime. Integration tests own random TEST_DB_* schemas; their bootstrap creates no shared application/database.

Sessions, GeoIP/fingerprints, analytics, caches, hydration/mapping, outgoing HTTP, and the legacy API/SDK were removed. Do not restore optional placeholders. A new, limited string/unary POST Echo contract generates plain readonly DTOs and HTTP adapters; unsupported declarations fail generation. Bundled protobuf/DTO runtime remains an internal RR dependency, not an application codec.

Runtime/codegen/quality dependencies are bundled. Ordinary commands need no installation or network. Separate security commands fetch advisory data only. Follow source boundaries; never hand-edit generated PHP or incidentally modify upstream sources. Deleted historical sources are referenced through Git history.
