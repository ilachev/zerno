# Public document catalog

Type: `operations`. Status: `current`. Reviewed: 2026-11-13.

A runnable example: published list → HTML detail → PDF download. Run commands from the checkout root. Required platform: upstream PHP 8.5.12+, pdo_pgsql/core extensions, PostgreSQL, sockets, and RR 3.0.1+ in the 3.x line. No package installation is needed. See [requirements](../../docs/development/infrastructure-and-testing.md) and [security](../../docs/development/security.md).

## Prepare and run

An administrator can create a separate example database/role in psql:

```sql
CREATE ROLE zerno_catalog LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password zerno_catalog
CREATE DATABASE zerno_catalog OWNER zerno_catalog;
```

Enter a password interactively, then set it in your terminal environment. Do not commit secrets.

```bash
export DB_HOST=127.0.0.1 DB_PORT=5432
export DB_NAME=zerno_catalog DB_USER=zerno_catalog DB_SCHEMA=public
: "${DB_PASSWORD:?Set the PostgreSQL role password in DB_PASSWORD}"
export DB_PASSWORD
export CATALOG_FILES_DIR="$PWD/var/document-catalog/files"
php tools/project.php migrate migrate examples/document-catalog/migrations
php examples/document-catalog/seed.php
rr serve -w "$PWD" -c examples/document-catalog/rr.yaml
```

CATALOG_FILES_DIR must be outside public; default is var/document-catalog/files. Seed creates the directory. PHP needs read access and seed needs write access. Operators control ownership, database/file consistency, and protection from unrelated writes.

The explicit migration creates catalog_documents and its partial published index, atomically with history. HTTP startup does no DDL. In an empty database, CLI seed creates 24 published, one draft, and one withdrawn document with small PDFs. Repeated seed preserves records with matching storage keys; existing records retain their saved text. New demo content and UI are English.

RR listens on loopback 8081 with one worker, CLI OPcache, and chunks 8192. Open the [catalog](http://127.0.0.1:8081/documents); Ctrl+C stops it.

| GET/HEAD | Result |
| --- | --- |
| /health | Core health without a connection |
| /documents | Up to twenty published rows, newest added first |
| /documents?before=24 | Cursor page without a total COUNT |
| /documents/{id} | Title, description, UTC date, PDF metadata |
| /documents/{id}/download | Published PDF attachment |

## Behavior and boundaries

[Repository](src/CatalogRepository.php) uses a fixed published predicate so generic prepared plans can use the partial index. LIMIT 21 detects continuation while showing twenty rows. before is a positive canonical decimal ID through PHP_INT_MAX, without signs/spaces/leading zeros. Invalid cursors get HTML 400. Invalid/hidden/missing IDs share 404; unsupported methods return 405/Allow; private failures produce generic 500.

[application.php](application.php) composes Html/Errors/repository/files/handlers from Logger/Storage/private directory; [config](config.php) chooses inputs. [Errors](src/Errors.php) serves shared routing/error middleware directly, without intermediate JSON. Completed handler responses remain unchanged; Throwable details go to the logger. See [lifecycle](../../docs/architecture/request-lifecycle.md).

No JavaScript/sessions/Set-Cookie are required. HTML/files use no-store. HEAD preserves representation headers without body; download checks/closes the file unread.

A database record selects an opaque storage key. FileStore checks containment, regular-file type, and fstat size. Unavailable files return the same public 404 without a path. [AttachmentResponse](../../src/Platform/Http/AttachmentResponse.php) supplies application/pdf, disposition, ASCII/UTF-8 names, and nosniff. Access checks stay local. Range receives full 200. Files/metadata must remain immutable during delivery. Downloads do not hash or malware-scan files; PDF preparation/accessibility/storage protection belong to application/operator.

[Bootstrap](bootstrap.php) registers example sources explicitly. Core does not register the example or run its SQL. Construction creates objects without connecting/reading application data. Runtime archives include migration/seed/application, but no development tools/tests/load scripts.

## Verification

In a runtime archive use `php bin/migrate.php migrate examples/document-catalog/migrations`; seed/RR commands are unchanged. Checkout checks:

```bash
php tools/project.php verify
php tools/project.php verify:integration
```

Integration uses separate TEST_DB_* independent of application DB_*. Unit tests need no database service. Actual HTTP/memory claims require the full server path.

### Reproduce source and runtime archive

The checkout-only command below runs the real catalog configuration through RoadRunner, the migration CLI, the seed CLI and PostgreSQL, first from source and then from a freshly built runtime archive. Prepare a separate test database whose role may create/drop schemas, and provide a supported, separately audited RR binary. The checker requires Git, CLI PCNTL/POSIX, DOM, Phar and zlib in addition to the HTTP/PostgreSQL extensions. It starts no service and downloads nothing.

```bash
export TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432
export TEST_DB_NAME=app_test TEST_DB_USER=app
: "${TEST_DB_PASSWORD:?Set the separate test role password}"
export TEST_DB_PASSWORD
php tools/project.php catalog:check --rr=/absolute/path/to/rr3 --report=var/catalog-check.json
```

All five TEST_DB_* values must be explicit and nonempty; host/database must be single DSN values without whitespace or semicolons. The platform temporary directory must be outside the source checkout. Application DB_* and CATALOG_FILES_DIR are not used. The report parent directory must already exist. Omit --report for a console-only check; --help needs no services.

Each phase owns a fresh catalog_check_<random> schema, private files, and a loopback listener with one worker. It checks health/404/405 before migration without creating tables, then runs the shipped migration and seed twice. It verifies the 26 records (24 published, one draft, one withdrawn), unchanged second-seed records/files, English pagination (20+4), detail fields, PDF bytes/hash/headers and HEAD, no-store/no cookies, invalid cursor, hidden/missing records, uniform public 404s, and a successful download/health after restoring an unavailable fixture.

The checker builds into its private workspace using the existing build command's --output-dir option, extracts only that owned archive, and checks that tools/tests/load-tests/sql/contracts and codegen/quality distributions are absent. The packaged inventory must contain runtime only. The archive phase uses the packaged entry points/configuration and a separate PHP process graph. Its worker and migration/seed CLI use PHP open_basedir limited to the extracted application and their private fixture directory, so ordinary PHP loading from the source checkout fails. No missing dependency is copied into the package. Extracted files are fingerprinted before/after the scenario; the owned archive and extracted tree are removed afterward.

Limits are 120 seconds for both phases and packaging together, ten seconds for readiness, five seconds/1 MiB per HTTP response, fifteen seconds per ordinary CLI child, sixty seconds for the build, thirty seconds for extraction, 256 KiB retained diagnostics per child, and five seconds per PostgreSQL connection/statement/lock wait. Extraction accepts at most 64 MiB compressed, 128 MiB of file contents and 10,000 entries, with a 128 MiB PHP memory limit. Fixture reads accept at most 26 regular PDFs of at most 1 MiB each with canonical storage keys; symlinks are rejected. Cleanup has bounded process termination and database waits in addition to the run deadline. Cleanup failures return nonzero. External SIGKILL cannot guarantee cleanup.

JSON report format 2 identifies **source-and-archive** scope. Each attempted phase has named assertions/outcomes, fixture identifiers, PostgreSQL version and cleanup; top-level assertions cover packaging/input checks and top-level cleanup covers checker processes/archive/workspace. The report also records Git revision/dirty state, actual PHP/RR/Go/OS/architecture, RR hash and archive digest/size/file count. It enumerates SHA-256 for the selected build/application/runtime inputs, documentation/notices and checker sources, checked again after execution. This is a scoped snapshot, not a fingerprint of every repository file. Reports contain no database credentials, user-specific absolute paths or private child logs. Failures name the operation; detailed child output is withheld. Keep reports in ignored var/. A dirty revision alone cannot be reproduced by another reviewer; an archive digest does not promise identical bytes from another build.

All three [ADR slices](../../docs/design/adr-2026-11-13-reviewer-walkthrough.md) are implemented. Ordinary verify and verify:integration retain their existing service boundaries. The check establishes response/setup/cleanup behavior, not worker identity, security, throughput or sustained-load suitability.

### CI evidence

On pushes/PRs to master, [Validate and Test](../../.github/workflows/ci.yml) supplies PostgreSQL and explicit TEST_DB_* settings, runs verify:full, prepares the pinned RR platform, runs catalog:check and builds the ordinary runtime distribution. The [shared platform script](../../.github/platform/build-roadrunner.sh) uses the same source/toolchain/dependency pins as the independent Security job. Each job prepares its own platform so security-service failures do not suppress application validation; this adds a second platform build to CI.

Open the run's **catalog-check-<run_id>-<attempt>** artifact. It retains report.json (format 2, including both attempted phases), console.txt, Go build information, and platform input/binary hashes for fourteen days. Upload runs after success or failure once platform preparation succeeds, unless the run is cancelled. A checker or diagnostic-limit failure keeps the job red. Console capture retains at most 256 KiB, drains additional output so checker cleanup can finish, and fails on overflow. A successful check must produce a nonempty JSON report. Project commands still install/download nothing; only the explicit external platform step retrieves Go/source/modules.

Login/editor permissions/CSRF/upload UI/HTTP editing/publishing are absent. [Application boundaries](../../docs/project/document-catalog-example.md) do not establish a CMS backlog. Html/FileStore/Errors remain local; core errors/attachments/metadata logging are shared. [File checks](../../docs/history/2026-10-18-file-responses.md) describe logging costs without changing the catalog default pipeline. [Migration contract](../../docs/design/transactions-and-migrations.md) defines SQL arrays, atomicity, and explicit retry after corrections.
