# Purpose and scope

Type: `design`. Status: `current`. Reviewed: 2026-11-13.

Zerno is an engineering portfolio case study in LLM-assisted development. A working PHP HTTP/PostgreSQL core supplies the concrete system: HTTP → handler → explicit SQL or stream → response. The [portfolio](../portfolio/overview.md) explains contributions and evidence; the [workflow](../portfolio/workflow.md) explains task boundaries and verification.

The owner's current audience is **Tech Lead / Lead Engineer** reviewers. Prioritize inspectable technical direction, task boundaries, tradeoffs, review and delivery evidence. The [technical-leadership ADR](../design/adr-2026-11-13-tech-lead-evidence.md) tracks the implemented factual case/snapshot and pending owner review/reading path.

The earlier product direction targeted a small autonomous foundation for websites and applications, prioritizing municipal and public-sector developers. That motivation remains historical context. Independent adoption and the value of maintaining a separate framework have not been validated.

## Implemented system

| Area | Working capability |
| --- | --- |
| HTTP | Concrete Request/Response/Body/Uri/uploads, routes, middleware, errors, attachments |
| Composition | Constructors and functions; constructed handlers; App separate from the loop |
| Database | PostgreSQL PDO, parameters, transactions, explicit CLI migrations |
| Contract generation | First string/unary POST JSON slice: DTO, endpoint, adapter, route |
| SQL development | Typed queries/rows from readable SQL, LIMIT, PostgreSQL checker |
| Diagnostics | Request ID, duration, metadata logs without reading response bodies |
| Execution | RoadRunner 3.x only |
| Delivery | Fixed bundled runtime/codegen/quality sources; no package installation/download in ordinary commands |

This table is the current distribution boundary. [Architecture](../architecture/overview.md) describes implementation; [principles](principles.md) govern choices.

## Development model

Use ordinary PHP, explicit composition, visible SQL, and autonomous delivery. Containers, autowiring, service locators, facades, ORM, mandatory protobuf, SPA, frontend builds, and a compulsory hierarchy of wrappers are unnecessary. Add interfaces or layers only for a concrete consumer or a demonstrated reduction in total complexity.

Core HTTP types have no PSR-7/17 compatibility contract. Nyholm and PSR HTTP/factory/log dependencies were removed. Retain useful standards and components; a distinctive API must improve developer steps or measured costs.

Bundled third-party files retain provenance, notices, and hashes. PHP, extensions, PostgreSQL, RoadRunner, and measurement executables are external platform requirements. The pinned RR transport still needs bundled protobuf/DTO libraries; generated Echo uses plain readonly DTOs and builtin JSON.

## Accepted contract-generation direction

The accepted direction is `.proto → DTOs/endpoint contracts/HTTP adapters/explicit routes`, generated during development. Business policy, SQL, and transactions stay in application code. The [Echo slice](../design/protobuf-contract-generation.md) supports checked string messages and unary POST JSON. Further types and transcoding require a complete consumer scenario; expansion is not a portfolio requirement.

## Removed scope and application policy

Sessions, anonymous visitor tracking, GeoIP, fingerprints, analytics, caches, the legacy API/SDK/generator, hydration/mapping, and the outgoing HTTP client were removed with their configuration. See the [scope history](../history/2026-10-20-core-scope.md). Do not keep speculative subsystems as optional capabilities or restore compatibility shims.

Login, cookies/sessions, forms, CSRF, roles, editing, publishing workflows, integrations, and cache policy belong to applications. The [document catalog](../../examples/document-catalog/README.md) checks a bounded SQL list, HTML detail, and a private file; its Document model, statuses, PDF rule, schema, and templates remain local.

## Current priority

Make engineering decisions and results easy to inspect. Preserve a runnable example and reproducible checks. Further work follows a concrete application or an identified correctness issue. Deployment budgets, license, public versions, and support remain open in the [roadmap](roadmap.md).
