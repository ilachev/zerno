# Agent instructions

Type: `instructions`. Status: `current`. Reviewed: 2026-10-17.

These rules apply throughout the repository. Communicate with the user in Russian. Write code comments in English. This is the canonical instruction file; `CLAUDE.md` is a compatibility entry point.

## Start here

1. Read [LLM onboarding](docs/llm-onboarding.md) and the task-specific documents linked there.
2. Read [development principles](docs/project/principles.md): simplicity, useful functionality and performance are joint requirements. Use [architecture](docs/architecture/overview.md) for implemented behavior and [vision](docs/project/vision.md) / [roadmap](docs/project/roadmap.md) for accepted direction and planned work. A plan does not establish that a feature exists.
3. Use [the documentation index](docs/README.md) and [machine-readable inventory](docs/documentation-inventory.json) to find the relevant sources. Follow [documentation maintenance rules](docs/development/documentation.md).
4. Inspect the code and tests before changing behavior. Resolve stale documentation against implementation, then update both where needed. Historical audits are evidence of past runs, not current instructions.

## Product and architecture

- Zerno (Зерно), repository `zerno`, is a small autonomous PHP HTTP/PDO core for general-purpose websites and web applications. Municipal and public-sector developers are a priority audience. Keep CMS workflows, editorial roles and other product policy in application code.
- Current work prioritizes reusable core capabilities. Examples are consumers and verification fixtures; keep catalog editing and publication workflows deferred while developing the core. A working example does not establish that its local helpers are public core APIs.
- Database access is part of the core: PDO, explicit parameterized SQL, transactions and migrations. Do not introduce an ORM to hide SQL.
- Runtime sources, generator dependencies and PHP quality tools are bundled. Do not add package installation or downloads to runtime, generation, builds or checks. Platform requirements are documented separately.
- `config/core.php` is the minimal HTTP/PDO composition; `config/core-roadrunner.php` adds RoadRunner. `config/container.php` retains the optional protobuf API with sessions, statistics and integrations. Optional behavior must be explicitly composed.
- Keep `App` and the common handler independent of the server loop. Support ordinary PHP/SAPI and the RoadRunner adapter without making every site use protobuf.
- Keep domain models independent of infrastructure and protocol types where domain separation is needed. Use application/domain services and mappers when actual policy or reuse justifies them. Do not require a full layer hierarchy or wrappers for a straightforward handler with explicit dependencies.
- Prefer small reusable components with explicit constructors and providers. Add abstractions only when actual use cases justify them; solve root causes rather than narrow workarounds.
- Capability cache consumers use `ScopedCacheFactory` / `ScopedCache`; backend wiring owns `CacheService`. Fingerprints are analytics data, never credentials.

## Required decision criteria

- Apply [the three development goals](docs/project/principles.md) to significant changes: simpler use and maintenance, broader useful scenarios, and measured runtime efficiency. Preserve correctness, data integrity and access protection when comparing alternatives.
- For public contracts, core composition or hot paths, describe the developer steps, supported behavior, costs with/without the feature and relevant checks. Explain tradeoffs briefly in the task or PR.
- Prefer small compatible primitives and complete examples. Keep optional behavior explicitly composed and check real startup/request I/O; do not claim zero overhead solely because a service is lazy.
- New layers, interfaces, configuration switches and generator modules need a concrete consumer or demonstrated reduction in total complexity. Preserve explicit SQL and install-free delivery.

## Source boundaries

- Do not hand-edit `gen/Generated/*`, `protos/gen/*` or `docs/api.swagger.json`. Change SQL/protobuf inputs or generator code and regenerate the affected artifacts.
- SQL inputs: `sql/schema.sql`, `sql/queries/*`; project policy: `codegen/Sql`; generic engine: `tools/sql-gen`.
- Protobuf inputs: `protos/proto/app/v1/*`; project profile: `codegen/Protobuf`; generic engine: `tools/protoc-php-gen`. Read [the generator guidelines](docs/design/protoc-php-gen-guidelines.md) when touching this area.
- Bundled third-party files are governed by `third_party/manifest.json`. Preserve upstream notices and record deliberate adaptations and new hashes; do not edit bundled code incidentally. See [delivery architecture](docs/design/autonomous-core.md).
- Typhoon and other generator implementation dependencies belong to isolated codegen processes, not application runtime.

## Commands and verification

No installation step is required. `php tools/project.php help` is the command reference; Task aliases are optional.

```bash
php tools/project.php docs:check
php tools/project.php verify
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php
php tools/project.php test --filter=methodName
php tools/project.php tools:sql:check
php tools/project.php tools:proto:check
php tools/project.php verify:integration
php tools/project.php verify:full
```

- Always run `php tools/project.php verify` after completing a task and before committing. It checks bundled integrity, documentation, style, static analysis, SQL synchronization and root unit tests.
- Fix verification failures before committing; never disable checks or bypass CI. Never change PHPStan configuration to silence errors: fix the code and types. Re-read this file before considering such a configuration change.
- Changes to database behavior require actual PostgreSQL checks, not just unit tests. Use `verify:integration`; generator changes also require the corresponding tool checks. `verify:full` includes all of these.
- Default `verify` must not require external services. Use real internal implementations in tests rather than mocking internal services. Test each layer's responsibilities.
- `services:start` starts Docker services and runs **API** migrations. Integration tests use `TEST_DB_*` and isolated random schemas, independently of application `DB_*`. See [infrastructure and testing](docs/development/infrastructure-and-testing.md) before changing test setup.
- CI and releases run `verify:full` and build a runtime archive. Committed generated artifacts are packaged; protobuf regeneration is an explicit development command requiring platform compilers.

## Database conventions

- PostgreSQL is the development, integration-test and production target. An existing SQLite adapter does not replace PostgreSQL validation.
- Use prepared parameters, explicit transactions and PostgreSQL-compatible migrations. Reset integration state through isolated schemas and transactions where applicable.
- Prefer `TEXT` for strings, `JSONB` for structured persisted data, `BIGSERIAL` for generated IDs and `BIGINT` for timestamp fields, including values that may reach `PHP_INT_MAX`.
- When an existing insert API accepts an ID for a generated primary key, pass `null`. Handle SQL nullability explicitly. Design indexes for actual query patterns, including JSON queries.
- HTTP startup must not run migrations. In the minimal composition, database connections remain lazy and `/health` must not depend on a connection. The existing API composition resolves SQL-backed session/statistics services while building `App`.

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
