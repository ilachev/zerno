# Zerno

Type: `navigation`. Status: `current`. Reviewed: 2026-11-13.

**An engineering portfolio case study in developing and reviewing a PHP backend with LLM assistance.** Zerno makes the work inspectable: architecture decisions, implementation, regression tests, runtime checks, and measurements live in the same repository.

The working system is a small PHP HTTP/PostgreSQL core running on RoadRunner. It provides a concrete setting for examining explicit composition, SQL generation, resource ownership, and verification of agent-produced changes. Its usefulness as a standalone framework remains unvalidated by an independent production application.

## Start with the case study

For **Tech Lead / Lead Engineer** reviewers, the [short reading path](docs/portfolio/overview.md#short-reading-path) connects owner direction, agent work, review and evidence:

1. [Follow the scope correction](docs/portfolio/scope-and-composition.md#decision-trail): the agent removed API generation with the legacy stack; a recorded owner correction retained contract-first generation. Inspect the linked removal, clarification and bounded Echo implementation diffs.
2. [Read the attributed review](docs/portfolio/api-generation-review-2026-11-13.md#owner-input-and-attribution): the owner confirms .proto as the source of truth for endpoints and delegates DTO/risk assessment. Alternatives and technical judgments are attributed to the agent; personal owner design/risk review remains open.
3. [Inspect the evidence](docs/portfolio/overview.md#inspect-the-result): generated-API checks have their own dated record; the permanent catalog CI snapshot covers source/archive delivery at f1ed996. Use [Run and verify](#run-and-verify) below for a current checkout and [current CI runs](https://github.com/ilachev/zerno/actions/workflows/ci.yml) for later revisions.

The overview also links the [workflow](docs/portfolio/workflow.md), correctness case and runtime measurements for deeper inspection.

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

For a finite application walkthrough, [prepare the separate test database and platform](examples/document-catalog/README.md#reproduce-source-and-runtime-archive), then run:

```bash
php tools/project.php catalog:check --rr=/path/to/rr3 --report=var/catalog-check.json
```

CI runs this source-and-runtime-archive scenario on pushes/PRs to master. Its [catalog evidence](examples/document-catalog/README.md#ci-evidence) includes both phase results, cleanup, input/archive hashes and platform provenance, retained for fourteen days. One [permanent baseline](portfolio-evidence/catalog-ci-f1ed996/README.md) preserves the original report and provenance for clean revision f1ed996; it stays bound to that historical run.

```bash
php tools/project.php verify:full
php tools/project.php http:check --rr=/path/to/rr3
php tools/project.php security:audit
php tools/project.php security:platform --rr=/path/to/rr3
php tools/project.php build local
```

The [verification guide](docs/development/infrastructure-and-testing.md) covers platform requirements and isolation. Separate [security checks](docs/development/security.md) retrieve advisory data; unresolved findings and service failures block release. CI uses a pinned [RoadRunner security rebuild](docs/development/security.md#pinned-security-rebuild-from-2026-11-13); the minimum version alone does not establish that a supplied binary is free of known findings. Historical checks do not establish the security of a different binary or a later deployment.

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
