# Application generation from protobuf contracts

Type: `design`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

The first complete slice is implemented: [echo.proto](../../contracts/echo.proto) defines DTOs, a unary RPC, and standard google.api.http binding. The [generator](../../tools/proto-gen/Generator.php) emits plain PHP DTOs, an endpoint interface, and a handler with explicit RouteEntry. A [manual endpoint](../../examples/contract-echo/EchoEndpoint.php) is supplied by the [composition root](../../examples/contract-echo/config.php).

```text
.proto → protoc → descriptor → DTO + endpoint + HTTP adapter/route
                                    ↑ manual implementation
                                App → RoadRunner 3.x
```

Applications retain SQL, access rules, transactions, and policy. No repositories/domain models/container/registry/providers are generated. Ordinary RequestHandler remains an independent route mechanism.

## Developer path

1. Change messages/RPC/POST binding in contracts/echo.proto.
2. Run proto:gen with installed protoc; 26.1 was checked. Nothing is downloaded.
3. Implement EchoSendEndpoint and pass it plus a body limit to EchoSendHttp; register adapter->route(). Fields/paths need no manual duplicate. Renaming an RPC requires updating implementation/composition.
4. Run verify and actual RR checks; PHP/PHPStan reject incompatible implementations.

```bash
php tools/project.php proto:gen
php tools/project.php proto:check
php tools/project.php proto:compile:check
php tools/project.php verify
php tools/project.php http:check --rr=/path/to/rr3
```

proto:check runs without a compiler: it checks source/import/[descriptor](../../contracts/echo.pb) hashes and compares the entire regenerated output, rejecting missing/extra/changed files. This alone does not establish descriptor provenance; proto:compile:check recompiles. [echo.inputs.json](../../contracts/echo.inputs.json) is generated, not a hand-maintained lockfile.

Compilation uses argv arrays, a ten-second timeout, 64 KiB diagnostics, and a 4 MiB descriptor bound. Model/PHP syntax are checked before replacing gen/Generated/Echo via a neighboring staging directory. Removed RPC artifacts disappear. Descriptor/snapshot are published after PHP; interruption can leave a mismatch detected by check and repaired by regeneration. Concurrent generation is unsupported. Put no arbitrary files in the owned directory.

The runner builds one concrete contract. Generator takes a descriptor separately; another consumer needs explicit input/output/namespace configuration, not runtime discovery or a plugin registry.

## Supported subset

| Declaration | Boundary |
| --- | --- |
| File | One proto3 file; only google/api/annotations.proto import; only php_namespace option under App\Generated |
| Message | Top-level singular strings, implicit presence; lower-case initial ASCII alphanumeric field name identical to JSON name |
| RPC | Unary input/output in the same file; handle(RequestDto): ResponseDto |
| HTTP | Literal POST path using letters/digits/`/_-`; body: "*" |
| Output | Final readonly DTOs with public string fields/fromJson/toJson; interface and handler with route() |

Numbers, bytes, enums, repeated/maps, optional/oneof, nesting, arbitrary imports/options, GET, path/query transcoding, response_body, additional bindings, and streaming fail generation. Full ProtoJSON, binary protobuf, and gRPC are not supported. Reserved PHP names and generated type collisions fail.

## JSON and HTTP

The subset follows [ProtoJSON](https://protobuf.dev/programming-guides/json/) implicit-default/null/unknown-field rules. DTOs do not implement Google Message or retain binary unknown fields.

| Input | Result |
| --- | --- |
| Nonempty string message, including Unicode | 200, same message |
| {}, null message, or empty string | 200, {}; default empty strings are omitted |
| Unknown field including null, or wrong field type | 400 Invalid JSON request |
| Empty/malformed JSON/UTF-8, array/scalar/null root | 400 Invalid JSON request |
| Duplicate key | Last value, following json_decode; aliases are rejected |
| More than 65,536 bytes in the example | 413 Request body too large |
| Missing/ambiguous/non-JSON Content-Type or non-identity Content-Encoding | 415 Unsupported media type |
| Other method | Routing 405/Allow: POST; direct adapter calls also validate method |
| I/O, endpoint exception, output encoding failure | Public 500 with private log details |

Content-Type comparison ignores case/parameters; decoding remains UTF-8 with depth 32. Only fromJson decode errors become 400; endpoint InvalidArgumentException remains a server failure. Adapter closes input in finally; transport closes output. Neither request nor DTO is retained.

Body::readLimited bounds actual bytes from the current cursor without trusting Content-Length. It bounds subsequent decoding, not earlier RR buffering. Server ingress limits are separate; see [manual JSON](../../examples/json-echo/README.md).

## Runtime and delivery

Only generated PHP is loaded at request time. Runtime archives exclude contracts/descriptors/tools/codegen sources and include the [example](../../examples/contract-echo/README.md). No new runtime library is added. Google protobuf was already required internally by RR; only the generator uses its descriptor reader. [Contract-source manifest](../../third_party/codegen/proto/manifest.json) records licenses/hashes.

Composition creates adapter/endpoint objects. Endpoint/interface declarations may load during registration; DTO/codec loads on API use. /health runs no generator, reads no contract, and opens no database. Core/router know no descriptors.

## DTO choice and measured costs

Plain readonly DTOs provide direct signatures, builtin JSON, and no API descriptor-pool work for this subset. Standard protobuf messages provide a broader contract/binary codec; future types need a fresh comparison before extending a custom codec.

[First-slice data](../history/2026-10-23-contract-generation.json), recorded before RR-only scope, contains five separate processes per variant, 20,000 operations each, PHP 8.5.9, CLI OPcache off, identical 254-byte nonempty JSON. Variants compare plain DTOs, protoc 26.1 messages with bundled Google runtime, manual/generated handlers. The protobuf comparison excludes services/HTTP annotations. Handler inputs stay in their common subset: the manual example requires message and emits empty strings explicitly, so behavior outside it differs.

Cold first operation, loaded classes, PHP memory, and steady operations are measured, not native RSS or leak freedom. http:check --measure-contract --rr=... compares 100 warmed sequential requests in alternating order, including socket/routing/logging. It establishes neither throughput superiority nor a runtime winner. Generation reduces duplicated contracts without inherently accelerating manual code.

## Verification and future work

Tests cover reproducibility, unsupported declarations, retired files, incompatible types, compiler timeout, JSON, and limits. HTTP checks cover both error renderers, 400/405/413/415/500, request IDs, and the next worker request. The extracted archive runs without generator inputs.

New types/presence/transcoding require complete consumer scenarios and wire-compatibility checks. Echo implies no SDK, general validation/mapping platform, or SQL generation. The old generator at f7b28ecad141a64bb7d0cfcb622256ccbde952c1 was inspected historically; its registry/profiles/handlers/model were not restored.
