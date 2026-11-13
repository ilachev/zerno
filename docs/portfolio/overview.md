# Engineering portfolio overview

Type: `design`. Status: `current`. Reviewed: 2026-11-13.

Zerno is a working PHP HTTP/PostgreSQL system used to demonstrate engineering with LLM assistance. The portfolio follows decisions through implementation and verification, including defects and limits that a successful default check did not reveal.

The intended audience is **Tech Lead / Lead Engineer** reviewers. Start with one scope-correction episode, then inspect its attribution, implementation and verification through the route below.

## Short reading path

1. **Owner direction:** read the [owner's statement](api-generation-review-2026-11-13.md#owner-input-and-attribution). The intended constraint is .proto as the source of truth for endpoints; other possible artifacts remain unspecified.
2. **Agent work and correction:** follow the [decision trail](scope-and-composition.md#decision-trail), with diffs for removal (bde3c3d), recorded clarification (e6ae4f6), and the bounded Echo implementation (5fe818f). The case links the manual endpoint and explicit composition.
3. **Review and tradeoffs:** inspect the [delegated assessment](api-generation-review-2026-11-13.md#delegated-design-assessment) and its risk table. The owner supplied direction and delegated DTO/risk choices; the agent compared alternatives and recommended retaining the current narrow DTO design. Personal owner design/risk review remains open.
4. **Evidence and reproduction:** inspect the [dated generation/HTTP checks](api-generation-review-2026-11-13.md#verification-and-disposition) at 765cf2d and the [permanent catalog CI snapshot](../../portfolio-evidence/catalog-ci-f1ed996/README.md) at f1ed996. The latter checks catalog source/archive behavior, not Echo API semantics. The commands below check a current checkout.

The project began as an autonomous PHP foundation for websites and applications, with municipal and public-sector developers as a priority audience. Its current presentation focuses on the engineering process. This change does not establish adoption, production readiness, or a reason for every team to build a framework.

## Owner and agent contributions

The owner sets direction, supplies constraints, requests reviews, and authorizes implementation. In the review sequence documented here, the owner requested an assessment against project goals, asked for an ADR and implementation, questioned the load results, and subsequently chose to present the work as an LLM engineering case study.

LLM agents performed substantial investigation, implementation, test development, documentation, and measurement work. The [contract ADR](../design/adr-2026-10-28-contract-correctness.md) and [runtime report](../history/2026-10-28-runtime-soak.md) record outcomes. They do not establish who first conceived every earlier design decision. Earlier changes are described as project decisions unless their authorship is independently documented.

The portfolio makes no percentage claim about human-written code and no measured claim about time saved. The owner's direction and the agent's execution are both part of the account. The [workflow](workflow.md) explains responsibilities and checks.

In the generation follow-up, the owner confirmed .proto as the intended source of truth for endpoints and asked the agent to decide DTO representation and risk disposition. The record attributes those technical judgments to the agent.

## Cases to inspect

| Case | Engineering question | Evidence |
| --- | --- | --- |
| [Scope correction and composition](scope-and-composition.md) | How was an over-broad agent removal corrected while preserving the smaller scope? | Linked removal/correction/Echo commits, explicit attribution, composition and a permanent catalog CI snapshot |
| [Contract correctness](contract-correctness.md) | What can a passing default suite miss? | Failures, an ADR, regression tests, PostgreSQL, HTTP, and archive checks |
| [Runtime measurement](runtime-measurement.md) | What does a persistent-worker test establish? | Workload source, raw summaries, process samples, and limited conclusions |

A reviewer can challenge the choices: why normalize booleans, why reject outer joins, why stage output, and why a short run cannot certify deployment suitability.

## Inspect the result

From a checkout with the [required PHP platform/extensions](../development/infrastructure-and-testing.md), start with service-free `php tools/project.php verify`. `php tools/project.php proto:check` checks the generated Echo artifacts without protoc. Full recompilation and actual HTTP require the separate compiler/RoadRunner platform; use the [generation check commands](api-generation-review-2026-11-13.md#verification-and-disposition).

For catalog delivery, [prepare the separate PostgreSQL test database and RoadRunner](../../examples/document-catalog/README.md#reproduce-source-and-runtime-archive), then run `php tools/project.php catalog:check --rr=/absolute/path/to/rr3 --report=var/catalog-check.json` from the checkout root. Its live CI artifacts have fourteen-day retention. The permanent snapshot includes original report/console/platform provenance, source binding and payload checksums; its guide provides a PHP-only integrity check.

Historical reports identify their source state and platform. The 2026-10-28 report includes uncommitted changes and records source hashes. Results belong to their recorded snapshots; [current CI runs](https://github.com/ilachev/zerno/actions/workflows/ci.yml) and fresh commands provide evidence for later revisions.

## Remaining questions

The [technical-leadership ADR](../design/adr-2026-11-13-tech-lead-evidence.md) records the factual case/CI package and this reading path as implemented. The personal owner design/risk-review criterion and an independent engineer's read-through remain open; the dated record documents owner direction and delegated assessment.

The core has examples rather than validated independent adoption. The workload does not determine capacity or multi-day stability. License and public support commitments remain unresolved. Additional features should follow an actual application need.
