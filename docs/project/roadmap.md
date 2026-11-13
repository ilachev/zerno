# Portfolio and maintenance roadmap

Type: `roadmap`. Status: `accepted-plan`. Reviewed: 2026-11-13.

The current purpose is an [engineering portfolio](../portfolio/overview.md) backed by the working [HTTP/PDO system](vision.md). [Simplicity, useful functionality, and measured efficiency](principles.md) still govern technical changes. A plan does not establish that a capability exists.

The owner selected **Tech Lead / Lead Engineer** as the portfolio audience. Reconciliation against clean revision `f1ed996` and [CI run 43423876614](https://github.com/ilachev/zerno/actions/runs/43423876614) confirmed the completed catalog milestone: source/archive execution, 251 successful assertions, cleanup and retained evidence. The next gap is a concise, source-backed account of owner decisions and technical review, plus evidence that survives CI retention.

## Portfolio priorities

| Status | Work | Completion evidence |
| --- | --- | --- |
| Implemented presentation | English entry points, contribution boundaries, workflow, and three case studies | README links to decisions, code, tests, and raw measurements |
| Implemented | [Reproducible catalog walkthrough](../design/adr-2026-11-13-reviewer-walkthrough.md) | catalog:check repeats migration/seed/HTML/PDF/errors against source and a fresh runtime archive; CI supplies PostgreSQL/RR and retains report/diagnostics/platform provenance for fourteen days |
| Proposed; not implemented | [Technical-leadership evidence](../design/adr-2026-11-13-tech-lead-evidence.md) | One existing case with linked decisions/agent work, a real dated owner review, one durable CI snapshot and a short employer reading path |
| Ongoing | Keep the working example and verification reproducible | Checkout instructions, service-free verify, separately documented PostgreSQL/RR requirements |
| Open | Exercise the portfolio with a reviewer | A reviewer can follow a case into code, reproduce a check, and discuss the tradeoff |
| Awaiting consumer | Extend an application or the core | A concrete scenario and a gap in existing primitives; no feature expansion solely for presentation |

The portfolio attributes owner direction and agent execution explicitly. It makes no measured productivity claim or unsupported statement of personal authorship.

The walkthrough ADR's three delivery slices are implemented: source, extracted runtime archive and CI evidence. The command uses bounded execution, independent resources, PHP file-loading separation and a scoped report. CI preserves its separate security gate and ordinary service-free verify boundary. This uses the current catalog and introduces no new application capability. Exercising the portfolio with an independent reviewer remains open until that review occurs.

## Established technical baseline

- [RoadRunner only](../history/2026-10-23-roadrunner-only.md): SAPI/FPM paths removed; App and CLI remain separate from the loop.
- [Contract Echo](../design/protobuf-contract-generation.md): string/unary POST JSON, readonly DTOs, endpoint, adapter/routes, snapshots, and real HTTP checks.
- [RoadRunner 3](../history/2026-10-20-roadrunner3.md): native transport and pinned upstream sources.
- [Transaction recovery](../design/transactions-and-migrations.md): per-version atomicity, explicit SQL lists, and PostgreSQL/CLI checks.
- [One-row SQL](../history/2026-10-23-query-one.md): queryOne/fetchOne, visible SQL, PostgreSQL checks, and PHP/native memory boundaries.
- [Bounded JSON](../../examples/json-echo/README.md): 400/413/415, server cap, and chunked-input checks.
- [Explicit composition](../history/2026-10-20-explicit-composition.md): constructors, ready handlers, and lazy PDO.
- Autonomous runtime/codegen/quality delivery; attachments and metadata diagnostics; catalog list/detail/private PDF consumer.
- [Scope reduction](../history/2026-10-20-core-scope.md): speculative subsystems and legacy API removed.

Early [JSON/SELECT](../history/2026-10-17-core-http-baseline.md) and [catalog](../history/2026-10-17-public-catalog.md) measurements describe earlier states before the single-runtime decision.

## Correctness and operational work

The [correctness ADR](../design/adr-2026-10-28-contract-correctness.md) records the implemented sequence.

| Status | Work | Result or acceptance condition |
| --- | --- | --- |
| Implemented | Review defects and unsafe outer-join subset | Correct booleans/uploads, rejected collisions, preserved output, usable archive instructions; SQL/HTTP/archive checks |
| Implemented | HTTP/DDL follow-up | Bodyless HTTP 205, BIGINT executed_at, explicit upgrade of existing metadata |
| Measured, limited | [15-minute mixed soak](../history/2026-10-28-runtime-soak.md) after warmup | 16,564 requests; successful checks; server/worker RSS/CPU/FD; no deployment SLO established |
| Awaiting deployment profile | Longer operational checks | Target platform, workload/data, latency/resource budgets, longer duration and failure scenarios |
| Continuous release gate | Security evidence for the selected binary | Current advisory/binary/evidence hashes and bounded assessment validity; unknown/expired results block release |
| Awaiting consumer | Next API/SQL operation | Manual implementation, errors, wire checks, comparable costs; new protobuf features only as required |
| Owner decision pending | Own-code license, versions, and support | Record the owner's distribution, versioning and support choices; public repository visibility and published releases are distinct states |

RoadRunner is selected by scope, without a benchmark-victory claim. Multi-day behavior requires relevant measurements. Sessions, editing, an auth framework, and generic caches do not follow automatically from this plan.

The new ADR is a proposal. Its review record and durable snapshot are planned outputs. Historical reports already attribute owner interventions, including correction of over-broad protobuf removal; their sources and dates must remain explicit. Independent reader feedback, deployment readiness, productivity gains and human-team leadership are not established by the existing CI result.
