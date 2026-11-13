# Review of the three development goals

Type: `history`. Status: `historical`. Date: 2026-10-17. Source state: 4c59899. English edition: 2026-11-13.

After renaming the project Zerno, review reconciled simplicity, functionality, and efficiency. This records findings and revised plans, not runtime repairs. Current rules are in [principles](../project/principles.md) and [roadmap](../project/roadmap.md).

## Method

Instructions, README, onboarding, vision, roadmap, catalog design, boundaries, architecture, and tool guides were compared with configuration/source for HTTP/SQL/retry/optional costs. Supply/history snapshots, generated OpenAPI, and upstream sources were preserved. This was source review, not a new benchmark, security audit, or real-server check.

## Findings and planning changes

| Finding at that state | Consequence | Recorded direction |
| --- | --- | --- |
| Goals scattered; layers/mappers prescribed without scenario complexity | Inconsistent choices and excess concepts | One canonical principles document; layers justified by actual complexity/reuse |
| Broad benchmark infrastructure before an application | Delayed utility and synthetic measurements | Begin with a small response/SELECT, then public application scenarios |
| Shared autoload and eager session/statistics resolution despite optional wording | Optional did not establish absent cost | Separate delivery/loading/registration/construction/execution and inspect actual I/O |
| Router literal lookup blocked parameter routes; literals unescaped; 405 lacked Allow | A small API still had incorrect behavior | Correct observable HTTP behavior before comparing speed |
| RR chunk 0 converted the whole body; logging consumed getContents without restoring position | Streaming at one layer did not bound the whole path | Check download through middleware/server and measure memory |
| fetchOne used fetchAll; no grammar LIMIT; wrong outer-join nullability | Typed convenience could read excess data or assert wrong types | Bounded stable SQL; correct inference or refusal before expansion |
| Outgoing options defaulted idempotent=true without method safety; downloads buffered | Repeated writes and excess memory | Validate retry policy before unsafe mutations; measure/bound downloads |
| Migration split on semicolons without per-version atomicity | Partial DDL complicated recovery | Check PostgreSQL failures/retry/recovery before editorial features |

Historical references: [RR provider](https://github.com/ilachev/zerno/blob/e6ae4f66b6940c850454af2c269b01a8965ff274/src/Platform/DI/ServiceProviders/RoadRunnerServiceProvider.php), [buffering adapter](https://github.com/ilachev/zerno/blob/a0d0b12037dc804957144a469b81755ba1a06900/third_party/runtime/packages/spiral/roadrunner-http/src/PSR7Worker.php), [HTTP options](https://github.com/ilachev/zerno/blob/31bd2b2e5ab210171ec8edbc588d6ab5efc1dffb/src/Platform/Http/Client/HttpRequestOptions.php), [outgoing client](https://github.com/ilachev/zerno/blob/31bd2b2e5ab210171ec8edbc588d6ab5efc1dffb/src/Platform/Http/Client/ResilientHttpClient.php).

## Result boundary

Rules and sequencing were updated across six then-planned areas: HTTP/runtime, HTML/forms/files, SQL/data, protected areas, publication/cache, and operations. The immediate goal became correct existing HTTP plus two reproducible measurements, followed by the public catalog. Client/outer-join/migration work retained separate dependencies rather than becoming mandatory for every route.

Later implementation changes belong in current documents. This report preserves the earlier review and does not restore removed capabilities.
