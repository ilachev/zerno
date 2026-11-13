# Engineering portfolio overview

Type: `design`. Status: `current`. Reviewed: 2026-11-13.

Zerno is a working PHP HTTP/PostgreSQL system used to demonstrate engineering with LLM assistance. The portfolio follows decisions through implementation and verification, including defects and limits that a successful default check did not reveal.

The owner is targeting **Tech Lead / Lead Engineer** roles. The [next proposed ADR](../design/adr-2026-11-13-tech-lead-evidence.md) focuses on a source-backed owner decision/review trail and one durable CI baseline; these outputs are not implemented yet.

The project began as an autonomous PHP foundation for websites and applications, with municipal and public-sector developers as a priority audience. Its current presentation focuses on the engineering process. This change does not establish adoption, production readiness, or a reason for every team to build a framework.

## Owner and agent contributions

The owner sets direction, supplies constraints, requests reviews, and authorizes implementation. In the review sequence documented here, the owner requested an assessment against project goals, asked for an ADR and implementation, questioned the load results, and subsequently chose to present the work as an LLM engineering case study.

LLM agents performed substantial investigation, implementation, test development, documentation, and measurement work. The [contract ADR](../design/adr-2026-10-28-contract-correctness.md) and [runtime report](../history/2026-10-28-runtime-soak.md) record outcomes. They do not establish who first conceived every earlier design decision. Earlier changes are described as project decisions unless their authorship is independently documented.

The portfolio makes no percentage claim about human-written code and no measured claim about time saved. The owner's direction and the agent's execution are both part of the account. The [workflow](workflow.md) explains responsibilities and checks.

## Cases to inspect

| Case | Engineering question | Evidence |
| --- | --- | --- |
| [Scope and composition](scope-and-composition.md) | Can removing infrastructure make dependencies and costs easier to inspect? | Removal history, composition roots, lazy connections, bounded measurements |
| [Contract correctness](contract-correctness.md) | What can a passing default suite miss? | Failures, an ADR, regression tests, PostgreSQL, HTTP, and archive checks |
| [Runtime measurement](runtime-measurement.md) | What does a persistent-worker test establish? | Workload source, raw summaries, process samples, and limited conclusions |

A reviewer can challenge the choices: why normalize booleans, why reject outer joins, why stage output, and why a short run cannot certify deployment suitability.

## Inspect the result

Start with [README](../../README.md), then follow a case into its code and tests. Run `php tools/project.php verify` for service-free checks. PostgreSQL integration and real RoadRunner checks have separate [platform requirements](../development/infrastructure-and-testing.md).

The [catalog walkthrough](../../examples/document-catalog/README.md#reproduce-source-and-runtime-archive) now checks source and runtime delivery together in CI. Its live artifacts have fourteen-day retention. A permanent, curated copy is part of the next proposal.

Historical reports identify their source state and platform. The 2026-10-28 report includes uncommitted changes and records source hashes. Its results belong to that snapshot; later checkouts are not automatically covered.

## Remaining questions

The core has examples rather than validated independent adoption. The workload does not determine capacity or multi-day stability. License and public support commitments remain unresolved. Additional features should follow an actual application need.
