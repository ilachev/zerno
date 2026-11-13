# Correcting an over-broad API-generation removal

Type: `history`. Status: `historical`. Reviewed: 2026-11-13.

An LLM agent narrowed Zerno to an HTTP/PostgreSQL core and removed the old application API, including its generator. The owner subsequently clarified that protobuf contract generation remained a desired capability. The result was a separately scoped Echo implementation with explicit PHP composition. This case follows that correction through repository evidence.

## Brief and attribution

**Historical-report attribution:** the [scope report](../history/2026-10-20-core-scope.md) records the owner's choice of a concrete HTTP/PDO core and removal of unrelated subsystems. Its [subsequent correction](../history/2026-10-20-core-scope.md#subsequent-user-correction) attributes the overly broad generator removal to the agent and the clarification to the owner.

**Repository facts:** the commits below show what was removed, what clarification was recorded, and what later shipped. This is an agent-authored reconstruction from those sources. In the [2026-11-13 review record](api-generation-review-2026-11-13.md), the owner confirms .proto as the intended source of truth for endpoints and delegates DTO/risk assessment to the agent. Personal owner reasoning about those tradeoffs remains open under [ADR slice 2](../design/adr-2026-11-13-tech-lead-evidence.md#delivery-slices-and-acceptance).

## Decision trail

| Change | Inspectable result | Attribution boundary |
| --- | --- | --- |
| [bde3c3d: reduce scope](https://github.com/ilachev/zerno/commit/bde3c3dc48d8f313ae79a2fc43b6fcbf57b046cb) | Deletes application contracts/generated API, tools/protoc-php-gen and its bundled distribution alongside sessions, tracking, caches and other unused scope | The diff establishes deletion; the historical report supplies the owner/agent account |
| [e6ae4f6: record the correction](https://github.com/ilachev/zerno/commit/e6ae4f66b6940c850454af2c269b01a8965ff274) | Adds the scoped generation plan and the report's correction: .proto should still generate API DTOs/endpoints | This contemporary repository text records the owner's clarification; it does not prove authorship of every later design detail |
| [5fe818f: implement Echo](https://github.com/ilachev/zerno/commit/5fe818f53f4084383c1f47a60fc55d33a6f2128c) | Adds contracts/echo.proto, a small generator, generated PHP, a manual endpoint, tests and first-slice measurements | A later implementation of the bounded direction, rather than restoration of the entire old API stack |

The correction distinguished an obsolete application stack from a useful development mechanism. It retained removal of sessions, GeoIP/statistics and the legacy API profile. Restoring generation did not require restoring those consumers.

## Alternatives and costs

The following is **retrospective agent analysis**, not a claim that a recorded meeting compared these options:

- Keeping the full legacy API/generator would retain its profiles, registry, generated surface and maintenance scope. That conflicts with the recorded reduction goal.
- Removing all application generation minimizes that surface but loses the contract-first developer path explicitly retained by the owner's correction.
- Implementing one bounded consumer preserves that path while making its limits visible. It adds generator maintenance and a developer compiler requirement; supported types and HTTP bindings must be checked rather than silently approximated.

The [current contract](../design/protobuf-contract-generation.md) defines the resulting boundary: string messages and unary POST JSON generate readonly DTOs, an endpoint interface and an adapter with an explicit route. Unsupported declarations fail generation. Business policy, SQL and transactions stay in application code. Binary protobuf, gRPC and a general SDK are outside this slice.

## Developer path and composition

Change [echo.proto](../../contracts/echo.proto), run proto:gen with the separately supplied protoc, implement the generated interface in [EchoEndpoint](../../examples/contract-echo/EchoEndpoint.php), and construct the adapter in [config.php](../../examples/contract-echo/config.php). The route uses the common HTTP pipeline. Runtime loads ready PHP without reading descriptors or invoking the generator.

The separate [composition history](../history/2026-10-20-explicit-composition.md) records the owner's container-removal request. Current construction uses constructors/functions: [core composition](../../config/core.php) and the [catalog application](../../examples/document-catalog/application.php) expose dependencies. This requires maintaining construction code as dependencies change; it does not establish zero startup cost or a measured maintenance-time saving.

## Verification and limits

The scope report records checks of the removal snapshot. The [first Echo data](../history/2026-10-23-contract-generation.json) records its own historical checks and bounded measurements. Those results belong to their recorded states. Current [generator](../../tests/Unit/Codegen/ProtoGeneratorTest.php), [compiler](../../tests/Unit/Codegen/ProtoCompilerTest.php) and [Echo](../../tests/Unit/Codegen/ContractEchoTest.php) tests cover their respective behavior.

The permanent [catalog CI snapshot](../../portfolio-evidence/catalog-ci-f1ed996/README.md) preserves a later clean revision, f1ed996, with source/archive execution, 251 successful assertions and cleanup. It demonstrates that integrated catalog scenario for that revision. It does not validate historical commits, test Echo's API semantics, or independently establish human authorship.

For a current checkout, run verify and the actual HTTP check under the [documented platform requirements](../development/infrastructure-and-testing.md):

```bash
php tools/project.php verify
php tools/project.php http:check --rr=/absolute/path/to/rr3
```

The snapshot guide links the separate PostgreSQL/RoadRunner catalog reproduction command. No comparative productivity baseline was recorded. The dated review record distinguishes the owner's stated direction from delegated technical judgment; the original personal design/risk-review criterion remains open.
