# Zerno

Type: `navigation`. Status: `current`. Reviewed: 2026-11-13.

**An engineering portfolio case study in developing and reviewing a PHP backend with LLM assistance.** Zerno makes the work inspectable: architecture decisions, implementation, regression tests, runtime checks, and measurements live in the same repository.

The working system is a small PHP HTTP/PostgreSQL core running on RoadRunner. It provides a concrete setting for examining explicit composition, SQL generation, resource ownership, and verification of agent-produced changes. Its usefulness as a standalone framework remains unvalidated by an independent production application.

## Start with the case study

- [Overview and contribution boundaries](docs/portfolio/overview.md): purpose, the owner's role, delegated work, and evidence.
- [Working with an LLM](docs/portfolio/workflow.md): context, bounded tasks, decisions, verification, and handoff.
- [Scope and explicit composition](docs/portfolio/scope-and-composition.md): removing subsystems and inspecting construction and I/O.
- [Defects beyond a passing suite](docs/portfolio/contract-correctness.md): PostgreSQL booleans, uploads, generator failures, and archive instructions.
- [Persistent runtime measurement](docs/portfolio/runtime-measurement.md): workload, process resources, results, and limits.

The owner directs the project and authorizes its scope. LLM agents assist with investigation, implementation, tests, and documentation. Individual discoveries are attributed only where the record supports attribution. No measured productivity improvement is claimed.

## Working system

- Concrete `Request`, `Response`, `Body`, `Uri`, and `UploadedFile` types; explicit routing and middleware.
- Ordinary PHP constructors and composition functions, without a container or autowiring.
- JSON/HTML errors, attachments, bounded input reading, request IDs, and metadata logging.
- PostgreSQL PDO, parameterized SQL, transactions, and explicit CLI migrations.
- Typed SQL generation and a limited `.proto → DTO → endpoint → HTTP adapter → route` example.
- RoadRunner 3.x as the sole HTTP runtime, with application handling separate from the worker loop.
- Bundled runtime, generator, and quality dependencies; no package installation during ordinary project commands.

[Architecture](docs/architecture/overview.md) describes the implementation. [Scope](docs/project/vision.md) and [roadmap](docs/project/roadmap.md) separate present behavior from future work. Examples include [manual JSON Echo](examples/json-echo/README.md), [generated Echo](examples/contract-echo/README.md), and a [document catalog](examples/document-catalog/README.md).

## Run and verify

CLI checks require PHP 8.5+ and the documented extensions. HTTP requires upstream PHP 8.5.12+, sockets, and an installed RoadRunner 3.0.1+ from the 3.x line. Database scenarios also require pdo_pgsql and PostgreSQL. Platform executables are supplied separately; project commands do not download them.

From a source checkout:

```bash
php tools/project.php verify
php tools/project.php help
rr --version
php tools/project.php run
```

[GET /health](http://127.0.0.1:8080/health) returns `{"status":"ok"}` without opening a database connection. HEAD is registered separately. `verify` needs no external services or protoc; `verify:full` adds SQL-tool checks and PostgreSQL integration through `TEST_DB_*`.

```bash
php tools/project.php verify:full
php tools/project.php http:check --rr=/path/to/rr3
php tools/project.php security:audit
php tools/project.php security:platform --rr=/path/to/rr3
php tools/project.php build local
```

The [verification guide](docs/development/infrastructure-and-testing.md) covers platform requirements and isolation. Separate [security checks](docs/development/security.md) retrieve advisory data; unresolved findings and service failures block release. Historical checks do not establish the security of a different binary or a later deployment.

From an extracted runtime archive:

```bash
rr --version
rr serve -c .rr.yaml
```

The archive contains PHP sources, generated artifacts, and examples. Tools, tests, load scenarios, and contract/SQL inputs belong to the checkout and are excluded from the archive. Instructions for them assume a checkout.

## Database and composition

[config/core.php](config/core.php) returns a `Closure(Logger): App`; [public/index.php](public/index.php) constructs the transport and logger. `APP_CONFIG` selects another explicit composition function. Routes receive constructed handlers.

[Storage configuration](config/storage.php) reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_SCHEMA`. PDO connects on the first SQL operation or transaction. HTTP startup never runs migrations.

```bash
php bin/migrate.php migrate
php bin/migrate.php migrate examples/document-catalog/migrations
```

The default migration directory is the empty `src/Migrations`. Existing INTEGER migration timestamps have a separate maintenance command, `php bin/migrate.php upgrade-metadata`; see the [migration contract](docs/design/transactions-and-migrations.md). SQL generation runs in the checkout with `php tools/project.php sql:gen`.

## Status

The priority is a reviewable portfolio: concise cases, accurate attribution, reproducible checks, and a runnable example. Further core features require a concrete consumer. License, public versioning, and support policy for the project's own code remain open; bundled components retain their upstream notices.

[Documentation index](docs/README.md) · [Agent instructions](AGENTS.md) · [LLM onboarding](docs/llm-onboarding.md) · [Dependency provenance](third_party/README.md)
