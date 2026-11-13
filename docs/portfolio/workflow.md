# Working with an LLM

Type: `operations`. Status: `current`. Reviewed: 2026-11-13.

Zerno uses an LLM agent to investigate and change a concrete codebase under repository constraints. Each task connects to observable behavior, with decisions, implementation, and verification available together.

## Establish context

[AGENTS.md](../../AGENTS.md) is the canonical instruction source. [Onboarding](../llm-onboarding.md) routes the agent to architecture, component contracts, and checks. The [inventory](../documentation-inventory.json) separates current instructions, accepted plans, and historical results.

Before editing, the agent reads relevant code and tests and checks the working tree. Historical audits describe earlier states; they do not override implementation or authorize restoring removed features.

## Define a bounded result

A task specifies a caller-visible outcome, important error paths, and a verification boundary. SQL generation includes usable generated PHP and a consumer. A transport correction includes real HTTP behavior. A database correction is exercised on PostgreSQL.

The owner controls direction and authorizes work. The agent proposes alternatives and implements within that scope. Significant choices record developer steps, supported behavior, costs, and limits; see the [correctness ADR](../design/adr-2026-10-28-contract-correctness.md).

## Challenge the result

In the documented review, the owner requested discrepancies between goals, documentation, and code. The agent found failures beyond the passing default suite, proposed a bounded plan, and implemented it after authorization. Follow-up questions about load prompted a distinction between a modest correctness/resource run and a capacity measurement.

An LLM review produces hypotheses. A proposed defect becomes evidence through reproduction, inspection, or a meaningful check. A second generated explanation alone does not validate the first.

## Verify the relevant boundary

| Boundary | Check |
| --- | --- |
| Routine changes | `verify`: integrity, documentation, style, PHPStan, generated-output synchronization, root unit tests |
| Database | PostgreSQL with isolated random schemas and cleanup |
| SQL generation | Tool checks and CLI regressions for collisions, invalid input, and output preservation |
| HTTP | Real PHP/RoadRunner wire checks and the next request after an error |
| Delivery | Extract a runtime archive and exercise packaged entry points |
| Persistent workers | Content checks, worker identity, memory/CPU/FD samples, and raw results |

Checks follow the changed behavior. Documentation checks establish link and inventory consistency, rather than certifying every sentence. Test counts describe a run's scope, rather than the project's economic value.

## Hand off the change

The handoff states what changed, why, how it was checked, and what remains unresolved. Source inputs and generated output stay separate. Ordinary checks do not install packages. Under the owner's standing instruction, each completed authorized stage passes its required checks, is committed, and is pushed to the configured upstream before the next stage starts. Preserve unrelated work, use normal fast-forward pushes, and report commit, push, and CI outcomes. Release creation and unrelated publication require their own authorized scope.

Project documentation is written in English; conversation with the owner remains in Russian. Third-party notices and raw historical artifacts retain their original contents. Unicode test fixtures deliberately include other languages.

## Attribution and productivity

The portfolio identifies substantial LLM participation in implementation, tests, and writing. Individual human decisions are attributed only where the record supports them. It does not reconstruct a private review history or claim manual authorship of agent-produced code.

No controlled comparison of elapsed time, token cost, or an unaided implementation was recorded for these cases. Productivity improvements remain unmeasured. The inspectable result is the system and the process used to evaluate it.
