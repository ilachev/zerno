# Documentation maintenance

Type: `operations`. Status: `current`. Reviewed: 2026-11-13.

Keep documentation beside code and usable without chat history. Write project guides, case studies, and example UI in English. Conversation with the owner remains in Russian. Preserve upstream notices, multilingual protocol fixtures, and raw historical artifacts. Translating a narrative does not change the date or scope of its original technical verification.

## Canonical sources

| Information | Owner |
| --- | --- |
| Agent rules | AGENTS.md; CLAUDE.md is a compatibility entry point |
| Purpose and scope | docs/project/vision.md |
| Portfolio contributions and cases | docs/portfolio/overview.md and linked cases |
| LLM workflow | docs/portfolio/workflow.md |
| Quickstart and platform | README.md; tools/project.php help owns the full command interface |
| Decision criteria | docs/project/principles.md |
| Coding/review/resource rules | docs/development/coding-guidelines.md |
| Priorities and acceptance criteria | docs/project/roadmap.md |
| Module relationships | docs/architecture/overview.md |
| HTTP execution | docs/architecture/request-lifecycle.md |
| Core/application boundary | docs/reusable-blocks.md |
| Bootstrap and packaging | docs/design/autonomous-core.md |
| Component behavior | Relevant architecture/design document and tool README |
| Environments/checks | docs/development/infrastructure-and-testing.md |
| Bundled composition | third_party/manifest.json and distribution manifests |
| Past verification | Dated docs/history reports and raw artifacts |

Code/tests establish implementation. Resolve discrepancies by inspecting them and updating text. Plans establish no implemented behavior. User instructions take precedence over repository rules. Attribution must follow available evidence; do not invent personal authorship or productivity claims.

## Type and status

After the title, provide kind, status, and the date of substantive review. A date alone promises no freshness. Preserve machine enum values when translating.

Kinds: `instructions`, `navigation`, `architecture`, `operations`, `design`, `roadmap`, `tool-guide`, `history`, `reference`, `inventory`.

Statuses: `current` for active descriptions/instructions; `accepted-plan` for accepted direction, rather than implementation; `historical` for a past snapshot; `compatibility` for an entry point to the canonical document. Separate implemented contracts from future ideas. A current document does not make every proposal implemented.

Claims of readiness, safety, compliance, or speed require relevant evidence. A report records date/source state, environment, method, outcome, and scope. Smoke checks establish neither load behavior nor security/legal certification. Accessibility goals are technical targets, not jurisdictional certification.

## Inventory

`docs/documentation-inventory.json` contains schema_version, reviewed_at, scope, documents, artifacts, and exclusions. Each entry has a unique id/path, kind/status, summary, nonempty read_when, and existing source_paths.

Include root instructions/README, docs Markdown, project tool/example README files, and third_party/README.md. Upstream package documentation, PHAR contents, and generated PHP belong to supply manifests. Source paths are navigation, not an exhaustive dependency graph.

## Change procedure

1. Find the canonical owner and inspect relevant sources.
2. Apply all three principles to direction/contracts/architecture, including integration and runtime cost. Update behavior descriptions with code; mark a stage implemented only after checking its criteria.
3. Change review dates only after substantive review. Keep short context plus links instead of duplicate specifications.
4. Update inventory, index, and incoming links for added/moved documents. Preserve historical claims and their time boundary. Keep raw historical snapshots unchanged.
5. Run docs:check and the mandatory verify. Read examples and links for meaning; automated checks do not certify factual accuracy.

## Format and checks

Use ordinary Markdown and relative repository links. Do not publish local checkout paths, secrets, real credentials, or links to nonexistent future files. Write future paths as code literals. Commands run from the repository root unless stated otherwise. Link external specifications to primary sources; add no network/package manager for Markdown checks.

```bash
php tools/project.php docs:check
php tools/project.php verify
```

The local checker validates inventory shape, coverage, unique ids/paths, existing sources, link targets, and Markdown heading fragments. It does not fetch external URLs and is not a complete CommonMark parser. Generated PHP changes through inputs/generators; upstream notices are not project prose.

The 2026-10-17 reorganization unified agent instructions and separated architecture, direction, and history. The former design dependency inventory moved unchanged into docs/history; the delivery design took over the current contract. The 2026-11-13 editorial revision added the portfolio and English guides while preserving original measurement artifacts.
