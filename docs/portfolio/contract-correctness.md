# Finding defects beyond a passing verification suite

Type: `history`. Status: `historical`. Reviewed: 2026-11-13.

A review found concrete failures while Zerno's existing default verification passed. The owner requested the review and authorized an ADR followed by implementation; the agent investigated and implemented corrections. The [ADR](../design/adr-2026-10-28-contract-correctness.md) preserves alternatives, costs, and checks.

## Failures and corrections

| Failure | Correction | Evidence |
| --- | --- | --- |
| PDO passed false as an empty string, rejected by PostgreSQL | Normalize bool to `true`/`false` before full-array execution | [Storage](../../src/Platform/Storage/PostgreSQLStorage.php), [PostgreSQL tests](../../tests/Integration/Storage/QueryOneTest.php) |
| Multipart `files[name]` was mistaken for an upload leaf | Distinguish groups by the shape of `name`, then validate leaf fields | [Adapter](../../src/Platform/Runtime/RoadRunnerHttpWorker.php), [tests](../../tests/Unit/Platform/Runtime/RoadRunnerHttpWorkerTest.php) |
| Duplicate SQL names could overwrite generated queries | Reject query, module, and final-path collisions, including case differences | [Parser](../../tools/sql-gen/src/Parser/SqlFileParser.php), [CLI tests](../../tests/Unit/Codegen/SqlGenerationCommandTest.php) |
| Failed generation could remove usable output | Stage the complete result before replacing the dedicated output directory | [Writer](../../tools/sql-gen/src/Generator/GeneratedOutputWriter.php), [tests](../../tools/sql-gen/tests/Unit/Generator/GeneratedOutputWriterTest.php) |
| Archive instructions invoked an excluded checkout tool | Separate commands and check the extracted package | [README](../../README.md), [archive test](../../tests/Unit/Platform/Runtime/RuntimeArchiveTest.php) |

Outer joins are rejected until nullability inference can support them correctly, preventing a false non-null contract.

## Why these choices

Boolean normalization preserves full-array `execute` semantics. Persistent `bindValue` calls would also need to prevent omitted parameters from reusing old bindings on cached statements. Integration tests cover boolean round trips and missing parameters.

Staging preserves previous artifacts on ordinary generation/publication failures and removes retired files on success. It requires temporary space on the same filesystem. Concurrent writers and process termination between renames remain outside the guarantee.

The corrections extend existing boundaries without introducing a generic publication framework or restoring a broad legacy API.

## Verification and follow-up

Checks cover root verification, SQL tools, PostgreSQL, RoadRunner HTTP, and an extracted archive. A later step addressed HTTP 205 and explicit migration metadata upgrades; its [report](../history/2026-10-28-runtime-soak.md) records 316 root, 170 SQL-tool, and 62 PostgreSQL tests plus 288 HTTP wire cases for that snapshot.

Agent findings become useful through caller-visible failures and regression checks. A green suite describes the cases exercised; boundary review can reveal missing cases.
