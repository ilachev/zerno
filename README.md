# Base API Template

[![CI](https://github.com/ilachev/base-api-template/actions/workflows/ci.yml/badge.svg)](https://github.com/ilachev/base-api-template/actions/workflows/ci.yml)

A pure PHP backend template with Protocol Buffers for endpoint contracts, generated endpoint handlers, protobuf-driven runtime routing, and a small RoadRunner-based runtime core.

Current target state for this repository: a production-ready set of reusable backend blocks on pure PHP, without framework coupling. The repository keeps runtime primitives and optional integrations, but it should not feel like a heavy framework.

The project is moving toward a small autonomous PHP web core with PDO and explicit SQL. Fixed third-party sources may be bundled; running, building and testing without package managers is the target. Composer and Task are still required by the current development workflow. See the [delivery plan](docs/design/autonomous-core.md).

The default runtime stays focused on reusable blocks. The only built-in endpoint example is a neutral health check that demonstrates the protobuf-first endpoint flow.

## Features

- Protocol Buffer based endpoint contracts
- Generated server-side endpoint handlers from protobuf services
- Protobuf-driven runtime routing from generated operation registry classes
- OpenAPI documentation generation
- Small framework-free runtime core
- RoadRunner-powered execution model
- Reusable backend building blocks instead of framework-driven architecture
- High test coverage and static analysis
- GitHub Actions CI/CD pipeline

## Documentation

Detailed project documentation for humans and LLM agents starts in [`docs/README.md`](docs/README.md).

## Setup

```bash
# Install dependencies
composer install

# Generate proto artifacts
task proto:gen:all

# Run unit tests
task test

# Check generated SQL artifacts against SQL sources
task sql:check

# Validate SQL queries against live PostgreSQL
task sql:check:pg

# Run integration tests against PostgreSQL
task test:integration

# Run the default verification gate (lint, static analysis, unit tests)
task verify

# Run the full verification gate, including integration tests
task verify:full
```

## Running the Application

The minimal HTTP/PDO composition uses explicit routes in `config/routes.php`, with GET and HEAD `/health`. It opens no database connection for the health request and enables no sessions, statistics, GeoIP or protobuf discovery. Run one of these servers:

```bash
# PHP development server; use public/http.php as the PHP-FPM front controller too
php -S 127.0.0.1:8080 -t public public/http.php

# RoadRunner using the same minimal application
rr serve -c .rr.core.yaml
```

PDO and SQL services are available when requested by a handler factory. Configure PostgreSQL with `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` and optionally `DB_SCHEMA`. Application migrations belong in `src/Migrations` (`App\Migrations` namespace):

```bash
php bin/migrate.php migrate config/core.php
php bin/migrate.php rollback config/core.php
```

The existing protobuf API remains available with its original application composition:

The application uses RoadRunner for high performance. To run:

```bash
# Install RoadRunner globally (if not already installed)
# Visit https://roadrunner.dev/download for installation instructions

# Start optional services and run the application
task run

# The API will be available at http://localhost:8080

# To stop all services
task services:stop
```

## Testing Strategy

- `task test` runs unit tests only and requires no external services
- `task verify` is the default local gate: lint, static analysis and unit tests
- `task sql:check` verifies generated SQL artifacts against `sql/schema.sql` and `sql/queries/*`
- `task sql:check:pg` validates SQL queries against live PostgreSQL
- `task test:integration` runs the PostgreSQL integration profile
- `task verify:full` runs the default gate, PostgreSQL SQL validation and integration verification

Use `task services:start` before integration runs or local runtime checks when you need a PostgreSQL instance.

## GitHub Actions

This project uses GitHub Actions for CI/CD:

- **CI Workflow**: Runs on every push and pull request to master
  - Validates code style
  - Runs static analysis
  - Executes all tests
  - Generates proto artifacts

- **Release Workflow**: Runs when a new release is created
  - Builds a production package
  - Attaches the package to the GitHub release
  - Uploads the OpenAPI documentation

## Conventions

### DTO
All DTOs are final readonly classes with public promoted properties:

```php
final readonly class Session
{
    public function __construct(
        public string $id,
        public string $token
    ) {}
}
```
