# Agent instructions

Type: `instructions`. Status: `current`. Reviewed: 2026-10-20.

These rules apply throughout the repository. Communicate with the user in Russian. Write code comments in English. This is the canonical instruction file; `CLAUDE.md` is a compatibility entry point.

## Start here

1. Read [LLM onboarding](docs/llm-onboarding.md) and the task-specific documents linked there.
2. Read [coding guidelines](docs/development/coding-guidelines.md) for implementation and review. Read [development principles](docs/project/principles.md): simplicity, useful functionality and performance are joint requirements. Use [architecture](docs/architecture/overview.md) for implemented behavior and [vision](docs/project/vision.md) / [roadmap](docs/project/roadmap.md) for accepted direction and planned work. A plan does not establish that a feature exists.
3. Use [the documentation index](docs/README.md) and [machine-readable inventory](docs/documentation-inventory.json) to find the relevant sources. Follow [documentation maintenance rules](docs/development/documentation.md).
4. Inspect the code and tests before changing behavior. Resolve stale documentation against implementation, then update both where needed. Historical audits are evidence of past runs, not current instructions.

## Product and architecture

- Zerno (Зерно), repository `zerno`, is a small autonomous PHP HTTP/PDO core for general-purpose websites and web applications. Municipal and public-sector developers are a priority audience. Keep CMS workflows, editorial roles and other product policy in application code.
- The shipped scope is HTTP requests/responses, explicit routing/composition, error responses, attachments, request IDs/metadata logging, PostgreSQL PDO/SQL/transactions/migrations, SQL generation and SAPI/FPM/RoadRunner execution. The catalog is an application consumer, not a CMS module. Do not retain speculative subsystems as optional capabilities. Sessions, GeoIP/fingerprints, analytics, caches, hydration/mapping and the outgoing HTTP client have been removed.
- The legacy application protobuf API/generator was also removed, but contract-first generation from `.proto` into DTOs, endpoint contracts, HTTP adapters and explicit routes is an accepted product direction; see [the scoped plan](docs/design/protobuf-contract-generation.md). Do not interpret cleanup as a ban on this direction or restore the old API stack wholesale.
- Develop Zerno's own model of ordinary PHP, explicit composition, visible SQL and autonomous delivery. Do not use Laravel feature parity or API imitation as the product specification. Distinctive design must improve the developer path or measured costs; retain useful standards and bundled components instead of inventing substitutes for novelty alone.
- Database access is part of the core: PDO, explicit parameterized SQL, transactions and migrations. Do not introduce an ORM to hide SQL.
- Runtime sources, generator dependencies and PHP quality tools are bundled. Do not add package installation or downloads to runtime, generation, builds or checks. Platform requirements are documented separately.
- Compose dependencies with ordinary PHP constructors and small typed functions. There is no container, autowiring, service locator or service-provider registry; do not replace them with another generic resolver. `config/core.php` returns a `Closure(Logger): App` used by both SAPI/FPM and RoadRunner. Routes receive constructed `RequestHandler` objects. The catalog has an explicit `application.php` composition function; CLI migrations build their own graph from a directory. There is no legacy API profile or compatibility configuration. New behavior needs a concrete current consumer before it enters the distribution.
- Deliver complete scenarios with small concrete boundaries and explicit resource ownership as described in the coding guidelines. New generation work must include usable generated output, a manual implementation and an end-to-end check; keep business policy and SQL in application code.
- Use the concrete core `Request`, `Response`, `Body`, `Uri` and `UploadedFile` types. PSR HTTP/factory/log and Nyholm packages are removed; do not recreate their full interfaces or add compatibility shims. RoadRunner uses its native `HttpWorker` API with the recorded worker logger-removal patch.
- Keep `App` and the common handler independent of the server loop. Support ordinary PHP/SAPI and the RoadRunner adapter. The pinned RoadRunner HTTP package still requires bundled protobuf/DTO runtime libraries internally; this does not establish an application protobuf API.
- Keep domain models independent of infrastructure and protocol types where domain separation is needed. Use application/domain services and mappers when actual policy or reuse justifies them. Do not require a full layer hierarchy or wrappers for a straightforward handler with explicit dependencies.
- Prefer small reusable components with explicit constructors and composition functions. Add abstractions only when actual use cases justify them; solve root causes rather than narrow workarounds.

## Required decision criteria

- Apply [the three development goals](docs/project/principles.md) to significant changes: simpler use and maintenance, broader useful scenarios, and measured runtime efficiency. Preserve correctness, data integrity and access protection when comparing alternatives.
- For public contracts, core composition or hot paths, describe the developer steps, supported behavior, costs with/without the feature and relevant checks. Explain tradeoffs briefly in the task or PR.
- Prefer small compatible primitives and complete examples. Check real startup/request I/O and keep the core path independent of application data; do not claim zero overhead solely because a service is lazy.
- New layers, interfaces, configuration switches and generator modules need a concrete consumer or demonstrated reduction in total complexity. Preserve explicit SQL and install-free delivery.

## Source boundaries

- Do not hand-edit `gen/Generated/*`. Change SQL inputs or generator code and regenerate affected artifacts. Whole artifacts belonging to a removed feature are removed with that feature.
- SQL inputs: `sql/schema.sql`, `sql/queries/*`; engine: `tools/sql-gen`. The root runner sets `--runtime-namespace=App\Platform\Storage\Sql` directly; no pass-through project profile wrapper is retained.
- Bundled third-party files are governed by `third_party/manifest.json`. Preserve upstream notices and record deliberate adaptations and new hashes; do not edit bundled code incidentally. See [delivery architecture](docs/design/autonomous-core.md).
- Typhoon and other generator implementation dependencies belong to isolated codegen processes, not application runtime.

## Commands and verification

No installation step is required. `php tools/project.php help` is the command reference.

```bash
php tools/project.php docs:check
php tools/project.php verify
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php
php tools/project.php test --filter=methodName
php tools/project.php tools:sql:check
php tools/project.php verify:integration
php tools/project.php verify:full
```

- Always run `php tools/project.php verify` after completing a task and before committing. It checks bundled integrity, documentation, style, static analysis, SQL synchronization and root unit tests.
- Fix verification failures before committing; never disable checks or bypass CI. Never change PHPStan configuration to silence errors: fix the code and types. Re-read this file before considering such a configuration change.
- Changes to database behavior require actual PostgreSQL checks, not just unit tests. Use `verify:integration`; generator changes also require SQL tool checks. `verify:full` includes all of these.
- Default `verify` must not require external services. Use real internal implementations in tests rather than mocking internal services. Test each layer's responsibilities.
- `services:start` starts only the PostgreSQL development service and never runs migrations. Integration tests use `TEST_DB_*` and isolated random schemas independently of application `DB_*`; their bootstrap does not create a shared application/database. See [infrastructure and testing](docs/development/infrastructure-and-testing.md) before changing test setup.
- CI and releases run `verify:full` and build a runtime archive. Committed SQL artifacts are packaged; generator/quality distributions and test fixtures are excluded from runtime.

## Database conventions

- PostgreSQL is the only application database target. A SQLite PDO fixture exists solely for service-free migration unit tests and is excluded from the runtime archive; it never replaces PostgreSQL integration checks.
- Use prepared parameters, explicit transactions and PostgreSQL-compatible migrations. Reset integration state through isolated schemas and transactions where applicable.
- Prefer `TEXT` for strings, `JSONB` for structured persisted data, `BIGSERIAL` for generated IDs and `BIGINT` for timestamp fields, including values that may reach `PHP_INT_MAX`.
- When an existing insert API accepts an ID for a generated primary key, pass `null`. Handle SQL nullability explicitly. Design indexes for actual query patterns, including JSON queries.
- HTTP startup must not run migrations. `PostgreSQLStorage` opens PDO on its first SQL operation, transaction or lastInsertId, not during construction. `/health` must not depend on a connection. `bin/migrate.php` accepts a migration directory, loads its source files explicitly and does not construct the HTTP application.

## Performance and long-running workers

- Do not claim a performance gain without comparable measurements. The FPM/RoadRunner choice remains open pending the baseline described in the roadmap.
- Keep services stateless where possible. Bound caches, reset request state and consider circular references and retained objects in RoadRunner workers.
- Validate memory behavior under sustained load before declaring a runtime suitable for deployment. Do not replace verified HTTP behavior with assumptions from a benchmark.
- Use runtime checks such as `instanceof` where needed instead of relying on PHPDoc to establish runtime truth.

## Code and commits

- PHP 8.5+, `declare(strict_types=1)`, final classes where possible, PSR-4 namespaces and the checked-in fixer rules. Root static analysis uses PHPStan level 10.
- Use one-space concatenation, trailing commas in multiline arrays/arguments/parameters and imports ordered classes, functions, constants. Do not import global namespace symbols.
- Interfaces have no `Interface` suffix. Comments explain reasons where needed and are always in English, including temporary code.
- Use `self::assert*` in PHPUnit tests. Do not introduce mocks for internal services.
- Commit messages are short single-line sentences in English, with no agent attribution. Preserve unrelated user changes.
