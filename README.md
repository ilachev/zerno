# ProtoPHPStack

Type: `navigation`. Status: `current`. Reviewed: 2026-10-17.

[![CI](https://github.com/ilachev/ProtoPHPStack/actions/workflows/ci.yml/badge.svg)](https://github.com/ilachev/ProtoPHPStack/actions/workflows/ci.yml)

A small PHP HTTP/PDO core with explicit routes, prepared SQL, transactions and migrations. Optional profiles retain the protobuf API, generated endpoint handlers, OpenAPI, sessions, API statistics and RoadRunner.

Runtime sources, generator dependencies and pinned PHP quality tools are included in the repository. Running, generating, checking and packaging requires no package manager or dependency installation. Task is an optional alias for the PHP command runner. See the [delivery architecture](docs/design/autonomous-core.md) and [documentation index](docs/README.md).

## Project direction and documentation

Start with [LLM onboarding](docs/llm-onboarding.md) or the [documentation index](docs/README.md). The [vision](docs/project/vision.md) defines product boundaries; the [roadmap](docs/project/roadmap.md) separates the implemented baseline from planned HTML, forms, protected areas, publication and operational work. The first planned application is a [document catalog](docs/project/document-catalog-example.md); it is not implemented yet. Current runtime behavior is documented in the [architecture overview](docs/architecture/overview.md).

## Platform requirements

- PHP 8.5+ with PDO; use `pdo_pgsql` for PostgreSQL or `pdo_sqlite` for SQLite.
- The optional GeoIP integration requires `bcmath` and `curl`.
- Quality tools also use DOM/XML, XMLWriter, mbstring and tokenizer. Release compression uses Phar and zlib.
- PostgreSQL is required for integration tests and PostgreSQL SQL validation.
- RoadRunner is required only for its runtime profile; `protoc` for protobuf generation, and `protoc-gen-openapiv2` for OpenAPI regeneration. These are platform executables, not downloaded by project commands.

## Check and build

```bash
# Available commands; no installation step
php tools/project.php help

# Local gate: dependency integrity, documentation, style, analysis, SQL synchronization and unit tests
php tools/project.php verify

# A focused unit test; PHPUnit arguments are forwarded directly
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php

# Generate or verify typed SQL
php tools/project.php sql:gen
php tools/project.php sql:check

# Full checks: both generator quality gates plus PostgreSQL validation and integration
TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:full

# Runtime-only archive and OpenAPI sidecar in dist/
php tools/project.php build local
```

`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` and `TEST_DB_PASSWORD` configure isolated integration runs. The runner defaults to `localhost:5432/app_test`, user `app`, password `password`. Create that test database before integration checks; each run creates and removes its own random schema. Application `DB_*` settings are not used to select the test database.

The release archive includes application sources, committed generated artifacts, configuration, entrypoints, runtime dependencies with their notices, the dependency manifest and documentation. It excludes tests, generator dependencies and quality tools. The OpenAPI sidecar comes from `docs/api.swagger.json`.

## Run the minimal HTTP/PDO application

Explicit routes in `config/routes.php` provide GET and HEAD `/health`. The health request opens no database connection and enables no sessions, statistics, GeoIP or protobuf discovery.

```bash
# Local development; public/http.php is also the PHP-FPM front controller
php -S 127.0.0.1:8080 -t public public/http.php

# The same minimal application through RoadRunner
rr serve -c .rr.core.yaml
```

PDO and typed SQL execution are available when requested by a handler factory. Configure PostgreSQL with `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` and optionally `DB_SCHEMA`. Application migrations belong in `src/Migrations`, namespace `App\Migrations`:

```bash
php tools/project.php migrate migrate config/core.php
php tools/project.php migrate rollback config/core.php
```

## Optional protobuf API

The existing API composition lives in `config/container.php` and runs through `public/index.php`. Its RoadRunner configuration is `.rr.yaml`; sessions, GeoIP and API statistics remain enabled in this profile.

```bash
# Existing Docker services and API migrations
php tools/project.php services:start

# The Compose configuration creates app; create app_test once for integration checks
docker compose exec db-postgres createdb -U app app_test

# Run the API profile
rr serve -c .rr.yaml

# Regenerate contracts, endpoints and OpenAPI when the platform compilers are available
php tools/project.php proto:gen:all

php tools/project.php services:stop
```

Application requests use `bootstrap.php`; tests use `tests/bootstrap.php`. Runtime packages are under `third_party/runtime`, generator packages under `third_party/codegen`, and quality tools under `third_party/tools`. `php tools/project.php third-party:check` verifies the included files against the recorded hashes.

## CI and releases

CI runs `php tools/project.php verify:full` and builds the runtime archive. Release verification runs the same checks and packages committed generated artifacts; regeneration is an explicit development command. No dependency installer or Task setup runs in either workflow.

## Conventions

Use strict types, explicit constructors and small reusable components. Keep product policy in application code, and generated files under generator control. The canonical engineering rules are in [AGENTS.md](AGENTS.md).
