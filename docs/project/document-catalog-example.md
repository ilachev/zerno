# Public document catalog example

Type: `architecture`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

The catalog consumes HTTP/PDO mechanisms. application.php composes Logger/Storage/private directory with ordinary constructors; routes.php receives ready handlers. [Startup and seed](../../examples/document-catalog/README.md) belong to the example. Editing, roles, publishing forms, and CMS workflows are absent.

## Implemented flow

GET/HEAD /documents serves a list; /documents/{id} a detail page; /documents/{id}/download a PDF attachment. /health uses the common handler. Unsupported methods return HTML 405 with Allow; unknown and hidden documents share 404. Infrastructure errors return generic 500 without private details.

Document, draft/published/withdrawn, PDF-only files, private FileStore, templates, and no-store are application policy. Core errors, attachments, and metadata logging do not know that policy.

[CatalogRepository](../../examples/document-catalog/src/CatalogRepository.php) uses direct SQL with a fixed published predicate, ORDER BY id DESC and LIMIT 21 to display 20 rows. before is a positive canonical ID. Lookup filters status/id. The repository validates published metadata because draft columns may be null.

The [migration](../../examples/document-catalog/migrations/Migration20261017001_CreateDocuments.php) contains the actual DDL and partial published/id index. The generator does not support string literals in WHERE. Replacing the fixed predicate with a parameter merely to use generation would affect partial-index availability in generic prepared plans. [Generator inputs](../../sql/queries/catalog.sql) are a separate typed-SQL example.

## Files and ownership

A database record selects an opaque storage key, never a user-supplied path. FileStore checks containment, type, size, and opened-file metadata. DownloadHandler transfers the stream to AttachmentResponse. HEAD closes the file and returns an empty body with representation headers. Range is ignored, returning a full 200.

RoadRunnerRuntime closes bodies after sending. The catalog selects 8192-byte chunks for seekable regular files; default 0 buffers. Logging does not read payload. [Full-path checks](../development/http-and-baseline.md) establish the measured memory behavior; file changes during transmission, aborts, and long-term operation are not established.

## Verification and limits

[PostgreSQL integration](../../tests/Integration/Examples/DocumentCatalog/CatalogApplicationTest.php) uses isolated schemas/files to check publication, cursors, empty results, LIMIT, one SELECT per useful request, invalid files, HEAD, and headers. /health/404/405 do not resolve storage. HTML unit tests check escaping and semantic structure.

The pages require no JavaScript and are an application example. The [historical report](../history/2026-10-17-public-catalog.md) describes an earlier snapshot; current tests check current SQL/pages/files. Login, sessions, upload UI, editing, HTTP publishing, antivirus, and action auditing are absent.
