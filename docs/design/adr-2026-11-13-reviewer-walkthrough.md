# ADR on reproducible catalog verification

Type: `design`. Status: `current`. Reviewed: 2026-11-13.

Decision status: **proposed**. Implementation status: **not started**. This is a decision proposal for the next iteration; the proposed command below does not exist yet.

## Context

The [portfolio purpose](../project/vision.md) prioritizes inspectable engineering decisions, accurate contribution boundaries, reproducible checks, and a runnable example. The previous [correctness ADR](adr-2026-10-28-contract-correctness.md) addressed existing contracts. The next useful result is a reviewer reproducing one complete application scenario with the mechanisms already present.

The document catalog is that consumer: explicit CLI migration and seed, PostgreSQL reads, HTML pagination/detail, private PDF download, and RoadRunner execution. It requires no new core capability, editor, authentication model, generated API, or SQL rewrite.

## Findings from the current review

The reviewed tree is based on Git `22dfa6d` with existing uncommitted implementation and documentation changes. A Git revision alone does not identify this working tree. The following observations concern that local state.

| Boundary | Evidence in the repository | Remaining gap |
| --- | --- | --- |
| First request | [api.http](../../api.http) requests GET /api/v1/home; [core routes](../../config/routes.php) expose GET/HEAD /health | Direct execution of the core composition returned 404 for the saved request and 200 for /health. The request file still presents the removed API. |
| Database and application | [Catalog integration tests](../../tests/Integration/Examples/DocumentCatalog/CatalogApplicationTest.php) construct an App and call handle() against real PostgreSQL | These tests do not start the shipped RR configuration or run the demo seed CLI. |
| HTTP transport | [HTTP checker](../../tools/check-http.php) runs real RR with [wire fixtures](../../tools/http-fixtures/config.php), including the catalog's HTML error renderer | It does not exercise the catalog's repository, routes, seed, and PDF lookup together over HTTP. |
| Runtime archive | [RuntimeArchiveTest](../../tests/Unit/Platform/Runtime/RuntimeArchiveTest.php) builds/extracts, checks exclusions, calls the packaged core App directly, and rejects invalid migration arguments | This service-free check does not run the packaged catalog through RR and PostgreSQL. |
| CI | [Validation](../../.github/workflows/ci.yml) provides PostgreSQL; the separate [security workflow](../../.github/workflows/security.yml) provides RR and runs wire checks | No ordinary CI step joins the real catalog, CLI setup, PostgreSQL, RR, and extracted delivery in one scenario. |
| Historical evidence | The [soak report](../history/2026-10-28-runtime-soak.md) records a specific source/platform snapshot and a bounded mixed workload | Its source hashes and results remain historical. They do not replace an executable check of the current catalog checkout. |

Existing checks are useful and remain in place. The gap is their composition into a repeatable application walkthrough. The current review's `verify` passed 316 root tests and 1,448 assertions, documentation links, integrity, style, analysis, and generation synchronization. That result does not close the gap above.

## Proposed decision

Add a checkout-only `catalog:check` command, backed by a small concrete checker such as `tools/check-catalog.php`. It exercises the actual application rather than introducing another demonstration backend or a general scenario framework.

The intended interface after implementation is:

```bash
# Requires an explicitly configured, separately prepared TEST_DB_* database.
php tools/project.php catalog:check --rr=/absolute/path/to/rr3 --report=var/catalog-check.json
```

The checker supplies an isolated environment to the existing entry points. PostgreSQL, PHP/extensions, and RR are prepared externally. The command installs or downloads no packages or executables and starts no Docker service. It constructs child-process `DB_*` settings exclusively from explicit `TEST_DB_*` settings and its owned schema, rather than inheriting application connection settings.

The final command checks both the source tree and its freshly built runtime archive. Deliver the source phase first as an independently useful slice; its report must identify that limited coverage until the archive phase is implemented.

## Application scenario

For each phase, use a new random schema in the explicitly selected test database and an owned private temporary directory. Follow the existing [schema ownership convention](../../tests/Integration/PostgreSqlTestDatabase.php); creating a resource successfully is a prerequisite for claiming cleanup ownership.

1. Start the actual catalog configuration with one worker, an explicit working directory, and a temporary loopback listener. Before migration, GET/HEAD /health and routing 404/405 must work; the fresh schema must still contain no application or migration tables.
2. Run `bin/migrate.php migrate examples/document-catalog/migrations` as a separate CLI process, then run the existing `seed.php` twice. Confirm 26 rows with 24 published, one draft, and one withdrawn record; the second seed must not duplicate rows or alter the existing fixture files.
3. Check the English HTML list over HTTP: twenty visible rows followed by four, with correct navigation and no hidden records. Select a known published record from the owned fixture and check its detail page.
4. Download its PDF using GET and HEAD. Compare GET bytes and SHA-256 with the owned file, representation headers, empty HEAD body, no-store, and the absence of cookies.
5. Check invalid cursor, unsupported method/Allow, hidden and missing documents. Make one owned fixture file unavailable, check the public 404, restore it, and check a successful download and health response afterward. Do not modify unrelated database objects or files.
6. Stop owned RR/worker processes, close clients and files, drop only the successfully created schema, and remove owned fixtures. A cleanup failure makes the check fail.

The test selects IDs from its own fixture rather than relying on an existing application's IDs. It uses the existing published-literal SQL and partial index; connecting the SQL generator is outside this decision. Sequential requests establish the checked response behavior; do not claim persistent worker identity without measuring it.

The checker needs bounded execution and diagnostics. Proposed initial bounds are ten seconds for listener readiness, five seconds per HTTP request, 1 MiB per response, 256 KiB retained diagnostics per child, and 120 seconds for a complete source-and-archive run, plus bounded termination/cleanup. These are test limits for the small seeded PDFs, not deployment SLOs. A limit failure must name the phase and failed operation. Forced process termination by an external SIGKILL remains outside guaranteed cleanup.

## Runtime archive phase

Build an owned archive with the existing `build` implementation, extract it into an owned temporary directory, and run the same scenario using the packaged `bin`, `public`, configuration, and example files. The checkout checker drives the test; it is not added to the runtime archive.

The two phases use independent schemas and file directories. The archive phase verifies that tools, tests, load scenarios, codegen distributions, and contract inputs remain excluded. It must not repair the extracted tree by adding excluded dependencies or load source-checkout application classes into its worker. Keep the existing offline RuntimeArchiveTest for its separate responsibility.

The checker builds only its own archive, rather than introducing an API for extracting arbitrary supplied archives. It removes its owned archive after recording the digest and outcome.

## Result and evidence

Write a short human summary and an optional JSON report containing:

- Report format version, UTC run time, executed phases, named assertions, outcomes, and cleanup outcome.
- The checkout's Git revision and dirty state, with SHA-256 fingerprints of the relevant application/checker inputs and runtime manifest. Enumerate the covered paths rather than implying a fingerprint of every repository file.
- Actual PHP/RR/PostgreSQL versions, OS/architecture, worker configuration, and the built archive's SHA-256 for the archive phase.

Check relevant source fingerprints before and after execution; a changed input makes the run fail rather than silently combining snapshots. Do not put database passwords, complete environments, user-specific absolute paths, or unfiltered private logs into the report. Expected HTTP errors are named assertions, while infrastructure, unexpected response, deadline, and cleanup failures return nonzero.

Reports describe the specific code and scenario tested. They are not security attestations, performance comparisons, proof of human authorship, or measurements of LLM productivity. Preserve existing raw historical artifacts unchanged. Local reports stay in ignored `var/`; publication of a curated result is a separate owner-authorized action.

A dirty-state fingerprint does not make local changes available to another reviewer. Sharing requires an owner-authorized revision or complete artifact that the reviewer can obtain. The owner's standing [commit and push instruction](../../AGENTS.md#code-and-commits) governs completed authorized stages; this ADR does not itself authorize implementation or a public release.

## Delivery slices and acceptance

| Slice | Observable result | Acceptance |
| --- | --- | --- |
| 1. Source walkthrough | One command exercises migration/seed/HTML/PDF/errors through the real catalog and RR | Repeated runs pass independently; invalid prerequisites fail clearly; a deliberately failing assertion still cleans owned resources. Replace the stale root api.http request with the actual default GET/HEAD /health contract. |
| 2. Packaged walkthrough | The same command repeats the scenario against an extracted runtime archive | Source and archive phases have separate fixtures; both appear in the report; missing packaged dependencies fail rather than being loaded from the checkout. |
| 3. Reviewer path and CI | README leads to the finite walkthrough and CI retains its result | A validation job explicitly provides both PostgreSQL and pinned RR, runs the check, and preserves bounded diagnostics/report on failure. Keep the independent security release gate. |

After each slice, update the canonical [example guide](../../examples/document-catalog/README.md), [verification guide](../development/infrastructure-and-testing.md), and [roadmap](../project/roadmap.md) to describe only implemented behavior. The proposed command is not added to current command tables until it exists.

Run the required checks, commit, and push each completed authorized slice before beginning the next, following the repository's standing delivery instruction. Record the revision and CI outcome in the handoff.

Required validation for implementation includes `verify`, real PostgreSQL integration for the exercised database paths, existing `http:check`, and the new source/archive checks on a prepared supported platform. Check ownership on a deliberate failure as well as success. Ordinary `verify` remains service-free; `verify:integration` retains its existing PostgreSQL boundary. No artificial unit tests are required for this ADR.

## Alternatives and tradeoffs

| Alternative | Assessment |
| --- | --- |
| Keep only manual setup and ad hoc probes | Already useful for exploration, but leaves a reviewer to reconstruct sequencing and maintain a separate failure/cleanup procedure. The proposed checker makes that existing work repeatable. |
| Add a database-backed generated API operation | Could be justified by a future consumer; it would change contracts and application policy without closing the current catalog verification gap first. |
| Build a generic demo runner, evidence registry, or environment manager | Adds concepts and lifecycle policy beyond the single consumer. Keep explicit functions and existing entry points; extract shared mechanics only when the implemented checks demonstrate a real need. |
| Run a longer benchmark next | Requires an application/deployment profile and budgets. It answers a different question from reproducing the shipped scenario. |

Simplicity improves through one documented entry and explicit ownership. Useful functionality is an executable review of an existing application and its delivery. Costs are extra test processes, temporary schema/files, archive work, and CI time. The HTTP hot path and runtime dependency graph gain no new work from this tooling; no runtime speedup is claimed.

Licensing, version/support policy, broader protobuf/SQL support, multi-day load, database outage handling, and client cancellation retain their separate roadmap decisions. This iteration adds evidence for a bounded existing scenario.
