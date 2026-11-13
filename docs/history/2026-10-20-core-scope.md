# Core scope reduction

Type: `history`. Status: `historical`. Date: 2026-10-20. English edition: 2026-11-13.

Before: f7b28ecad141a64bb7d0cfcb622256ccbde952c1. The user chose a concrete HTTP/PDO core and complete removal of unrelated subsystems. [Data](2026-10-20-core-scope.json) records removed paths/source sizes. [Current scope](../project/vision.md) and [architecture](../architecture/overview.md) supersede this snapshot.

## Scope and compatibility

Removed sessions/anonymous enrichment, GeoIP/fingerprints, ApiStats, Cache/KV, the old protobuf API/SDK/OpenAPI/generator, hydration/mapping, outgoing HTTP, and unused deadlines, together with configuration/migrations/commands/tests/load scenarios/dependencies. No optional remnants remained. Old API routes and APP_CONFIG=config/container.php became unsupported. The change executed no DDL and deleted no user data.

HTTP/PDO, then-current DI/routing, errors, attachments, request IDs/logging, transactions/migrations, and SQL generation remained. At this snapshot SAPI/FPM and RR shared a contract. PostgreSQL was the only application backend; SQLite PDO remained exclusively in unit fixtures and was excluded from delivery.

Three catalog-shaped generator queries replaced sessions/statistics examples. The actual catalog retained published-literal SQL and its partial index. A 50,000-row check showed parameterized status could select a generic primary-key scan with filtering after repeated native prepares; a composite index did not guarantee another plan. Rewriting application SQL/DDL solely for generation was rejected. WHERE literals remained separate work.

The empty project-profile wrapper was removed; root passed the runtime namespace directly. Generator parser/type/checker/runtime tests and PostgreSQL checks remained independent.

Pinned RR HTTP still required API DTO/Google protobuf, retained as complete upstream transport trees. This snapshot had no application .proto/SDK/API endpoints. GeoIP/simple-cache/KV and protobuf-codegen distribution were removed, with maps/notices/hashes updated.

## Costs

The developer path had one pipeline, then two adapters, and one PostgreSQL configuration without backend choice or mandatory analytics/sessions. This intentionally reduced compatibility and functional scope. Smaller sources/distributions reduced delivery footprint and maintenance scope; JSON counts PHP source/runtime-tree bytes including notices/manifests, not allocations. No speed/capacity/leak/readiness claim follows from file removal. SQL/fetchOne/streaming limits remained.

## Verification

verify:full passed 225 unit/869 assertions, 167 SQL-tool/625, 31 PostgreSQL/271; integrity covered three distributions/1,661 files, PHPStan 10. Actual FPM/RR checks passed 188 JSON/HTML cases. The archive passed 38 HTTP cases covering no-DB core, no startup DDL, explicit migrate/seed, catalog, private PDF GET/HEAD on both runtimes. Its manifest contained runtime only. Development/removed subsystems were absent. Earlier measurements were not rerun; removed-source links use the preceding Git state. Old API commands are not current instructions.

## Subsequent user correction

After bde3c3d, the user clarified that protobuf should remain a source for generating API DTOs/endpoints. Removing that mechanism with application legacy had been an overly broad agent decision. The historical removal/results remain recorded; the accepted [contract-generation direction](../design/protobuf-contract-generation.md) separately restores a bounded consumer. This correction did not restore sessions/GeoIP/statistics or the legacy API profile.
