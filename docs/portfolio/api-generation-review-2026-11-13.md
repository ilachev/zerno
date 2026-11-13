# API-generation direction and delegated technical review

Type: `history`. Status: `historical`. Reviewed: 2026-11-13.

Source revision: [765cf2dd28ed1ba2c364bf656b892464fd3c6046](https://github.com/ilachev/zerno/commit/765cf2dd28ed1ba2c364bf656b892464fd3c6046). This retrospective record follows the [scope-correction case](scope-and-composition.md). It records the owner's answers to an agent-prepared review brief, followed by the technical assessment the owner delegated to the agent. It does not backdate those answers to the original implementation.

## Owner input and attribution

The owner was asked about retaining generation versus handwritten PHP contracts, DTO representation, and accepted limits/risks. The directional answer, translated from Russian, was:

> I wanted proto files to be the source of truth for endpoints, and perhaps something else.

For the DTO choice and risk assessment, the owner asked the agent to decide. Those responses authorize the assessment below; they do not supply the owner's personal argument for a representation or acceptance of a particular risk. The English translation and technical analysis were written by the agent.

| Attribution | Recorded contribution |
| --- | --- |
| Owner | Confirmed .proto as the intended source of truth for endpoints; delegated representation and risk decisions |
| Agent, under delegation | Compared alternatives, retained the current DTO approach for Echo, assessed limits and defined reconsideration triggers |
| Open | Owner's own substantive comparison of a design alternative and acceptance of a specific risk; independent human review |

“Perhaps something else” leaves future artifacts unspecified. It establishes no additional generator module or application backlog. The current [generation contract](../design/protobuf-contract-generation.md) defines the implemented subset.

## Delegated design assessment

The recommendation is to retain the current string/unary POST JSON Echo design. No implementation change is justified by this review. Technical source links below are pinned to the reviewed revision.

| Decision | Alternative and assessment |
| --- | --- |
| Treat .proto as authoritative for supported request/response fields, RPC signatures and HTTP binding | Handwritten DTOs/routes/adapters could be simpler for a single Echo, but would duplicate the contract. Retaining generation follows the owner's stated direction. Compiler descriptors, input snapshots and PHP are derived artifacts; changes start with the contract/generator |
| Keep plain readonly PHP DTOs and the limited JSON codec | Standard Google protobuf messages remain an alternative; their runtime is already bundled for RoadRunner. The current DTOs expose ordinary signatures and builtin JSON for the supported subset. This choice does not eliminate codec maintenance or establish a new speed/size advantage |
| Generate mechanical transport code and compose a manual endpoint explicitly | Generating business services, SQL or application policy would widen ownership beyond the contract. The [composition](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/examples/contract-echo/config.php) supplies the endpoint and route without discovery or a registry |
| Keep generation in development and deliver checked-in PHP | Requiring protoc for every ordinary check would add a platform requirement to the offline verification path. Current runtime uses generated PHP; full recompilation is available separately. Core HTTP remains usable without an application contract |

The [generator](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/tools/proto-gen/Generator.php) and [DTO](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/gen/Generated/Echo/EchoRequest.php) support inspection of these choices. A real consumer needing broader presence/type semantics, binary protobuf or gRPC should prompt a fresh comparison with standard messages before expanding the custom codec. This is a reconsideration trigger, not a commitment to implement those features.

## Risks accepted by the delegated assessment

These are the agent's scoped judgments for the current Echo example. They are not attributed as personal owner conclusions or as deployment approval.

| Risk or limit | Current disposition and reason | Check or reconsideration trigger |
| --- | --- | --- |
| Only strings, unary RPC and literal POST JSON bindings | Accept for the complete Echo fixture; unsupported declarations fail generation rather than receiving partial support | [Generator tests](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/tests/Unit/Codegen/ProtoGeneratorTest.php) exercise rejection. A consumer needing numbers, lists, optional fields or another binding requires a complete supported scenario and wire checks |
| Absent/null/empty string share the default; unknown fields return 400 | Accept for the existing implicit-string contract. Clients cannot use these values to express distinct intentions; strict unknown-field handling also constrains client/server evolution | [Echo tests](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/tests/Unit/Codegen/ContractEchoTest.php) and actual HTTP pin behavior. Presence-sensitive policy or rolling clients with new fields requires explicit compatibility design before release |
| Compiler-free checks establish consistency with a saved descriptor, not recompilation provenance | Retain the service-free check, with a recommendation to run proto:compile:check when contract sources, bundled imports or compiler inputs change | Full compilation passed in this review. It remains a separate command; this documentation change adds no CI compiler provisioning or enforced gate |
| PHP, descriptor and input snapshot are not published as one atomic set; concurrent generation is unsupported | Accept for serialized developer generation with dedicated output ownership. Git and exact checks detect a mismatched set; do not use this as a concurrent runtime operation | [Publication code](https://github.com/ilachev/zerno/blob/765cf2dd28ed1ba2c364bf656b892464fd3c6046/tools/proto-gen/run.php) writes snapshots after PHP. After interruption, rerun proto:gen and verify. Concurrent automation or a requirement to preserve the entire previous set needs a separate publication/locking design |

The adapter's bounded body read does not bound earlier RoadRunner ingress buffering. The [HTTP contract](../design/protobuf-contract-generation.md#json-and-http) and server limits remain relevant. No capacity or sustained-operation conclusion follows from this review.

## Verification and disposition

Agent-executed checks on the clean source revision above, before this documentation edit:

- proto:check passed: all four generated PHP files match the saved descriptor and input snapshot.
- proto:compile:check passed with platform protoc 26.1: recompilation matched the descriptor/snapshot and all four generated files.
- http:check passed 144 cases with JSON errors and 144 with HTML errors, including generated Echo behavior, error recovery and the next request on the same worker. These counts cover the whole HTTP fixture suite, not 288 distinct Echo cases.

The local platform was PHP 8.5.12 and RoadRunner 3.0.1 built with Go 1.27.4 on macOS/arm64. Commands install/download nothing:

```bash
php tools/project.php proto:check
php tools/project.php proto:compile:check --protoc=/absolute/path/to/protoc
php tools/project.php http:check --rr=/absolute/path/to/rr3
```

The documentation delivery also requires docs:check and verify. No code defect requiring a change was established by this scoped assessment; this is not an exhaustive audit. Original historical JSON and the permanent CI payload remain unchanged.

At the time of this record, slice 2 was partial: owner direction and delegation were documented, while the original criterion of a personal owner design/risk review remained open. The employer reading path was a later slice; follow the [ADR](../design/adr-2026-11-13-tech-lead-evidence.md#delivery-slices-and-acceptance) for current delivery status.
