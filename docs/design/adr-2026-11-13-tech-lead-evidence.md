# ADR on evidence of technical leadership with LLM assistance

Type: `design`. Status: `current`. Reviewed: 2026-11-13.

Decision status: **proposed**. Implementation status: **not started**. The owner selected **Tech Lead / Lead Engineer** as the intended portfolio audience. This ADR proposes the next scope; the case changes, personal review record and durable snapshot below remain planned.

## Context and roadmap reconciliation

The [purpose](../project/vision.md) is an engineering portfolio backed by a working PHP system. The [catalog ADR](adr-2026-11-13-reviewer-walkthrough.md) is complete. Reopening that work or expanding the core is not the next portfolio requirement.

This review inspected clean revision `f1ed9967c89bb3c63339d0c1171ae58f3231460a`, the roadmap, portfolio cases, implementation/verification entry points, and [CI run 43423876614](https://github.com/ilachev/zerno/actions/runs/43423876614). The table records that baseline, before this documentation change.

| Roadmap area | Observed state | Implication |
| --- | --- | --- |
| English presentation and attribution | README, workflow and three cases exist; substantial agent participation is explicit | Presentation milestone is implemented; human decisions need a shorter, more traceable path |
| Reproducible application | catalog:check, independent source/archive scenarios, CI PostgreSQL/RR and artifact upload exist | All three catalog slices are implemented |
| Execution evidence | Downloaded format-2 report binds the clean revision above to two successful phases, 251 assertions and successful cleanup | This is evidence for that run; counts are not a leadership or productivity score |
| Human direction | [Scope history](../history/2026-10-20-core-scope.md#subsequent-user-correction) attributes a correction of an overly broad agent removal to the owner; [composition history](../history/2026-10-20-explicit-composition.md) records the container-removal request | A real candidate episode exists; distinguish historical attribution from a contemporaneous owner-authored review |
| Review trail | Current cases explain outcomes; no separate owner technical-review record was found for the recent catalog sequence. The final CI-wiring commit has no associated GitHub PR | A commit author or an instruction to continue does not establish a detailed personal code review |
| Evidence lifetime | The inspected catalog artifact is retained until 2026-11-27; no committed copy of that CI report is present | Preserve one curated baseline so the case remains inspectable after CI retention expires |
| Independent assessment | No independent reader assessment is recorded in the reviewed portfolio | Keep this roadmap item open until a real read-through occurs |
| Distribution and operations | Repository is public, no root own-code license or GitHub release was found; version/support decisions and deployment budgets remain open | Track these owner decisions separately from repository visibility and portfolio presentation |

The inspected [catalog artifact](https://github.com/ilachev/zerno/actions/runs/43423876614/artifacts/14037125141) contains a 364,336-byte report with SHA-256 `ac5a24681224142d608ddf51b480fa4114b626f15135ae9328fae5987858cc94`. This identifies the inspected raw report, not a snapshot already committed by this proposal. The historical soak remains a bounded past measurement; current CI does not establish current capacity or multi-day operation.

## Why this priority fits the target role

Public role descriptions provide a useful lens. [GitLab's Tech Lead responsibilities](https://handbook.gitlab.com/handbook/engineering/careers/ic-leadership/tech-lead/) include technical direction, task planning, guidance, progress reporting and risk management. [Google's review guidance](https://google.github.io/eng-practices/review/reviewer/looking-for.html) examines design, useful behavior, complexity and the validity of tests. These are examples from particular organizations, accessed 2026-11-13.

The inference for this portfolio is to make the owner's framing, boundary decisions, critical review and acceptance reasoning observable. Existing green checks establish useful technical outcomes. A linked account of who challenged which choice would let a reviewer assess technical leadership more directly. No employer assessment or hiring outcome has been obtained.

## Proposed decision

Deepen **one existing scope/composition case**, add one durable verification snapshot, and give a reviewer a short route through both. Use ordinary Markdown, Git links, the existing JSON report and documentation inventory. Keep runtime capabilities and the current verification commands unchanged.

The preferred episode is the distinction between removing an obsolete application API and retaining contract-first generation as a product direction:

1. [bde3c3d](https://github.com/ilachev/zerno/commit/bde3c3dc48d8f313ae79a2fc43b6fcbf57b046cb) removed the broad legacy scope.
2. [e6ae4f6](https://github.com/ilachev/zerno/commit/e6ae4f66b6940c850454af2c269b01a8965ff274) recorded the subsequent correction and scoped generation direction.
3. [5fe818f](https://github.com/ilachev/zerno/commit/5fe818f53f4084383c1f47a60fc55d33a6f2128c) implemented the limited Echo consumer.

The [existing case](../portfolio/scope-and-composition.md), historical report and [generation contract](protobuf-contract-generation.md) already provide starting evidence. The future case should identify the initial boundary, the agent's overreach as attributed by the report, the recorded clarification, the resulting narrower implementation and its checks. It must not assign every detail of the later design to the owner.

Current catalog CI can demonstrate that the present repository remains executable. It does not retroactively validate the old scope snapshot or independently prove who made the historical decision.

## Case and attribution contract

Keep the main case within roughly 1,000 words, with links to detail. It should answer:

- What outcome and constraints did the owner set? Which source supports that attribution?
- What did the agent propose or implement, and where did its interpretation exceed the intended scope?
- Which options were considered, what was retained/removed, and what costs or compatibility breaks followed?
- What did a real owner review inspect, challenge or accept, and why?
- Which diff and meaningful checks support the result? What remains unverified?

Label repository facts, historical-report attribution, owner-confirmed statements and agent interpretation distinctly. A new owner review of old work is dated as a **retrospective review**, with the exact source revision inspected. It is not backdated to implementation.

Owner review notes must come from actual owner input and cover at least one substantive design choice and one accepted risk or limitation. A reasoned agreement is valid; do not manufacture a disagreement, defect or rewrite for the story. An agent may prepare questions and an English draft from supplied notes. Unconfirmed personal reasoning stays explicitly pending.

Briefs and prompt excerpts use available source text or a labelled retrospective summary. Record tool/model/time/cost metadata only when a reliable record exists; otherwise mark it unrecorded or omit it. Publish concise proposals, observations and review comments rather than reconstructing private reasoning or an unavailable transcript. Git authorship, another agent's approval and test counts do not establish manual authorship or human review.

## Durable verification evidence

Preserve one inspected CI bundle under a proposed root path such as `portfolio-evidence/catalog-ci-<source-revision>/`. Keep it outside the existing runtime archive allowlist; verify that exclusion when implementing this slice. This static directory has one concrete case consumer and introduces no evidence registry, new command or dependency download in ordinary checks.

Retain the original report plus the small console/platform-provenance files, with a combined initial budget of 1 MiB. The case records run/artifact identifiers, capture time, source revision, original file hashes, executed phases, cleanup and limitations. Check the actual bundle size and contents before committing it. Keep raw bytes immutable; label any necessary redacted derivative explicitly and preserve its relationship to the original hash.

The import is a later documentation commit. The captured report remains bound to its original source revision and archive digest. Future successful runs do not replace it or make its results current. Live CI remains the route to checking a later revision.

Use the existing inventory for navigation. Snapshot validation should check JSON readability, known format, reported revision/dirty state, both phase outcomes, cleanup and recorded hashes. Publishing a snapshot does not turn it into a security, capacity or reproducible-build attestation.

## Delivery slices and acceptance

| Slice | Result | Acceptance |
| --- | --- | --- |
| 1. Factual review package | Condense the existing case around the linked scope-correction episode and preserve one CI baseline | Facts have sources; historical attribution is labelled; unknown owner reasoning remains pending; the raw snapshot matches its recorded hashes/revision, fits the size budget and stays excluded from runtime |
| 2. Owner technical review | Add a dated, substantive owner review of the specified revision | Notes explain a design choice, an alternative and a risk/limit; confirm their attribution. Resolve any genuine finding through an ordinary bounded change and relevant checks, or record reasoned acceptance |
| 3. Employer reading path | README/overview lead to the case, review and evidence in one short route | A reader can locate the owner constraint, agent work, correction/acceptance, relevant diff and reproduction command; keep the case compact and separate current checks from historical results |

The agent can prepare slice 1 using repository facts. Slice 2 requires the owner's actual technical input; an agent-authored approval cannot satisfy it. Technical documentation work and routine authorized delivery continue under the existing repository rules.

After the reading path exists, arrange a voluntary 15–20 minute read-through with a relevant engineer through the owner. Ask what decision they could attribute to the owner, what the agent did, what they would challenge, and whether the evidence/reproduction route was clear. Record actual feedback and resulting changes. Until then, independent validation remains open; a second agent is not an independent human reader.

Implementation follows the standing per-slice verify/commit/push workflow. Documentation changes require docs:check and verify. Snapshot/import or packaging changes also exercise the actual source/archive check; behavioral changes use the appropriate PostgreSQL/HTTP checks. Do not add tests merely for prose. The current task creates this proposal and reconciles the roadmap; it does not start these slices.

## Alternatives and tradeoffs

| Alternative | Assessment |
| --- | --- |
| Add core/CMS/API capabilities for display | No new consumer gap has been established; more code would dilute the leadership question |
| Add another polished agent-written narrative | Readability helps, but unsupported personal attribution would leave the same evidence gap |
| Publish a full chat export or build a prompt dashboard | Large reading surface and curation cost; one source-backed episode is sufficient for the next question |
| Claim productivity gains or compare AI with unaided development retrospectively | No comparable baseline or reliable cost/time record exists |
| Lead with a new benchmark or supported-product release | Requires its own workload/budgets or owner distribution decisions; those roadmap items remain separate |

Simplicity comes from improving an existing case and preserving one bounded bundle. Useful functionality is a reviewer being able to inspect the owner's decisions and reproduce the technical result. Costs are owner review time, curation and a small checkout-size increase; runtime evidence stays outside the package and no runtime speedup is claimed.

This project can supply evidence of technical direction and review in LLM-assisted work. Human-team mentoring, people management, organizational impact, independent adoption and production operation need their own genuine records. License/version/support decisions and a real deployment profile remain separate priorities.
