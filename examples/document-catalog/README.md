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

Login/editor permissions/CSRF/upload UI/HTTP editing/publishing are absent. [Application boundaries](../../docs/project/document-catalog-example.md) do not establish a CMS backlog. Html/FileStore/Errors remain local; core errors/attachments/metadata logging are shared. [File checks](../../docs/history/2026-10-18-file-responses.md) describe logging costs without changing the catalog default pipeline. [Migration contract](../../docs/design/transactions-and-migrations.md) defines SQL arrays, atomicity, and explicit retry after corrections.
