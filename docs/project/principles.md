# Development principles

Type: `instructions`. Status: `current`. Reviewed: 2026-11-13.

Simplicity, useful functionality, and runtime efficiency are joint requirements for Zerno's technical work. Apply them during planning, implementation, and review. [Architecture](../architecture/overview.md) describes what exists; [roadmap](roadmap.md) prioritizes work. [Coding guidelines](../development/coding-guidelines.md) apply these principles to complete scenarios and resource ownership.

## Three goals

| Goal | Optimize for | Evidence |
| --- | --- | --- |
| Simplicity | Understandable use/maintenance, predictable behavior, easy changes | Checkout-to-response steps; route/SQL/form integration; necessary concepts and settings; explicit dependencies/lifetimes |
| Functionality | Complete useful scenarios built from small compatible parts | Working input/data/response flow, error behavior, resource bounds, reduced manual work |
| Efficiency | Useful work with minimal CPU, memory, I/O, and time | p50/p95/p99, throughput at specified load/errors, all-process CPU/RSS/growth, SQL/request and connections |

Class count, code volume, test count, or roadmap length alone proves none of these goals. The current [portfolio purpose](vision.md) prioritizes clear evidence over adding capabilities for display.

## Choose between alternatives

Correctness, data integrity, access protection, and preservation of promised behavior are prerequisites. Dropping a check or changing the response is not an optimization of the same scenario.

For comparable functionality, prefer the design that is easier to understand, connect, and maintain. Include setup, error handling, tests, and application upgrades in the cost. A shared mechanism should reduce total developer work.

Name the new scenario and the boundary/cost of enabling it. Explain tradeoffs, a simpler alternative, and practical limits when one goal worsens. Unmeasured costs stay unknown; do not announce a gain before measuring it.

## Developer path

- Design for ordinary PHP, explicit composition, visible SQL, and autonomous delivery. Other frameworks do not specify Zerno's architecture or backlog. Keep useful standards and components.
- Routes receive constructed handlers. Use constructors and typed composition functions; no container, autowiring, or service locator. Add domain/application services, repositories, and mappers only where policy, reuse, or a real boundary needs them.
- New interfaces, registries, DSLs, facades, and extension points need a consumer and a reduction in duplication or complexity.
- Settings need meaningful defaults. Side effects, especially retries, sessions, caches, and migrations, must be explicit to callers.
- A short example should complete an ordinary scenario and report useful errors. Consider maintenance after the first run.

## Capabilities and application boundaries

The technical scope includes HTTP, routing/composition, diagnostics, and database access with SQL parameters, transactions, and migrations. Typed generation runs during development. HTML/forms/validation/files/protected areas/API coverage grows through complete scenarios; this direction does not establish implemented capabilities.

The catalog verifies mechanisms; its editorial backlog does not automatically become the core backlog. Its Html/FileStore are local implementations, rather than public core APIs. Generalize necessary mechanics without Document, publication statuses, or PDF-only rules. See the [boundary map](../reusable-blocks.md).

Ship only the scope in [vision](vision.md). Remove unused subsystems instead of hiding them as optional. A public route does not connect to storage until needed. Check startup and request costs: bootstrap and autoload registration already have a cost, even with lazy I/O.

## Measure the complete scenario

Compare the same result with comparable data, warmup, and load. Record PHP/extensions, OPcache, server settings, worker count, database, and CPU/RSS method. Include RoadRunner and every PHP worker, plus other processes needed by the scenario. Choose budgets after a reproducible baseline.

Bound SQL lists in the database with stable ordering. `fetchOne` does not itself bound SQL scanning or native buffering. Check file delivery through middleware and the server adapter; a stream abstraction alone proves no memory bound.

Bound caches and mutable state; check sequential requests with different data and sustained operation. Introduce caching/precomputation only with known cost, invalidation, and failure behavior. Preserve verified semantics.

Begin with a small response and one parameterized SELECT, then a working HTML/SQL/file application. Building a universal benchmark framework is not a prerequisite for useful application work.

## Autonomous delivery

Runtime, development, generation, checks, and builds need no package installation/download. Fixed third-party sources/tools may be bundled with provenance, notices, and hashes. PHP, extensions, database, and executables are separate platform requirements.

Evaluate bundled updates and patches against the same goals. Replacing a component needs a working consumer and verified benefit; fewer vendor files alone proves neither simplicity nor speed. See [delivery architecture](../design/autonomous-core.md).

## Record significant changes

For a public contract, core scope, or hot path, state the scenario/problem, developer steps, behavior/errors/compatibility, enabled/unused costs, checks, and limits. Show minimal integration code and trace it to SQL or response I/O. A familiar framework feature is not sufficient justification.

A text edit does not need a new ADR, score system, or benchmark. Run appropriate checks and the mandatory [repository gate](../../AGENTS.md). Update the canonical fact and inventory without copying the specification everywhere.
