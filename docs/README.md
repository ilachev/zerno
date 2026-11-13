# Zerno documentation

Type: `navigation`. Status: `current`. Reviewed: 2026-11-13.

Start with the portfolio to understand the engineering process, then follow its evidence into code, tests, and reports. Architecture describes implementation; accepted plans describe direction; historical reports apply to their recorded snapshots.

## Portfolio

- [Overview and contribution boundaries](portfolio/overview.md)
- [Working with an LLM](portfolio/workflow.md)
- [Scope and explicit composition](portfolio/scope-and-composition.md)
- [Defects beyond a passing suite](portfolio/contract-correctness.md)
- [Persistent runtime measurement](portfolio/runtime-measurement.md)

## Repository entry points

- [README](../README.md): purpose, platform, startup, verification.
- [AGENTS](../AGENTS.md): canonical agent instructions.
- [LLM onboarding](llm-onboarding.md): task-specific reading.
- [Documentation inventory](documentation-inventory.json): paths, kinds, statuses, and related sources.

## Purpose and technical direction

| Document | Responsibility |
| --- | --- |
| [Purpose and scope](project/vision.md) | Portfolio purpose, working system, application boundary |
| [Principles](project/principles.md) | Simplicity, useful scenarios, measured efficiency |
| [Roadmap](project/roadmap.md) | Priorities, implementation states, acceptance criteria |
| [Catalog example](project/document-catalog-example.md) | Application models, SQL, and files |

## Architecture and contracts

- [Architecture overview](architecture/overview.md): composition, entry points, HTTP and SQL.
- [Request lifecycle](architecture/request-lifecycle.md): middleware, bodies, server boundary, resources.
- [Component boundaries](reusable-blocks.md): core mechanisms and application policy.
- [Autonomous delivery](design/autonomous-core.md): bootstrap, distributions, manifests, packaging.
- [Correctness ADR](design/adr-2026-10-28-contract-correctness.md): review defects and implemented decisions.
- [Reproducible catalog ADR](design/adr-2026-11-13-reviewer-walkthrough.md): implemented source/archive walkthrough with scoped evidence; CI integration remains planned.
- [Protobuf generation](design/protobuf-contract-generation.md): the first limited Echo slice and accepted direction.
- [Transactions and migrations](design/transactions-and-migrations.md): atomicity, errors, recovery, metadata upgrade.
- [SQL-generation contract](design/sql-gen-product.md) and [tool guide](../tools/sql-gen/README.md).

## Development and examples

- [Coding guidelines](development/coding-guidelines.md)
- [Documentation maintenance](development/documentation.md)
- [Infrastructure and verification](development/infrastructure-and-testing.md)
- [HTTP checks and measurements](development/http-and-baseline.md)
- [Security monitoring](development/security.md)
- [Bundled sources](../third_party/README.md)
- [Manual JSON Echo](../examples/json-echo/README.md)
- [Generated Echo](../examples/contract-echo/README.md)
- [Document catalog](../examples/document-catalog/README.md)

## Historical evidence

Narrative reports are available in English. Raw historical JSON retains its original contents, including language, hashes, and recorded output.

| Report | Snapshot |
| --- | --- |
| [Runtime soak](history/2026-10-28-runtime-soak.md) | HTTP 205, BIGINT upgrade, 15-minute mixed workload; [data](history/2026-10-28-runtime-soak.json) |
| [RoadRunner only](history/2026-10-23-roadrunner-only.md) | Removal of SAPI/FPM paths |
| [One-row SQL](history/2026-10-23-query-one.md) | queryOne/fetchOne, native memory boundaries; [data](history/2026-10-23-query-one.json) |
| [RoadRunner 3](history/2026-10-20-roadrunner3.md) | Native transport and platform validation; [data](history/2026-10-20-roadrunner3.json) |
| [Native HTTP types](history/2026-10-20-native-http.md) | Removal of PSR/Nyholm; [data](history/2026-10-20-native-http.json) |
| [Explicit composition](history/2026-10-20-explicit-composition.md) | Container removal and lazy PDO; [data](history/2026-10-20-explicit-composition.json) |
| [Scope reduction](history/2026-10-20-core-scope.md) | Removed application subsystems; [data](history/2026-10-20-core-scope.json) |
| [File responses](history/2026-10-18-file-responses.md) | Complete transport checks, CPU/memory/I/O; [data](history/2026-10-18-file-responses.json) |
| [Public catalog](history/2026-10-17-public-catalog.md) | HTML/SQL/PDF example; [data](history/2026-10-17-public-catalog.json) |
| [Initial baseline](history/2026-10-17-core-http-baseline.md) | Early HTTP/PDO measurements; [data](history/2026-10-17-core-http-baseline.json) |
| [Principles review](history/2026-10-17-development-principles-review.md) | Earlier discrepancies and planning evidence |
| [Autonomy audit](history/2026-10-17-autonomy-audit.md) | Original delivery audit before reorganization |
| [Former dependency graph](history/dependency-inventory-2026-10-17.json) | Historical Composer inventory, not current distribution |

Historical links to removed source files point to a previous Git state. Results were not recalculated for later code. Current third-party composition is defined by distribution manifests, not historical inventories. The documentation inventory covers project-authored guides; upstream package README files belong to their distribution manifests.
