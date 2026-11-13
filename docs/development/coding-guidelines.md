# Coding and review guidelines

Type: `instructions`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

These rules apply the [three goals](../project/principles.md) to PHP changes. [AGENTS](../../AGENTS.md) defines commands/style/source boundaries; [architecture](../architecture/overview.md) describes implementation. These are requirements for changes, not a claim that all existing code already satisfies them.

## Deliver a complete scenario

The unit of work is a useful result with checked errors:

```text
HTTP input → validation → required actions/SQL → response → emission and resource cleanup
```

For generation: input contract → generated PHP → manual implementation → working request. Define input, response, side effects, bounds, and verification before editing. Deliver needed types, handlers, adapters, and tests together. Preparatory refactoring needs a concrete consumer.

Group files by reason to change. Do not require a directory per route or build all DTOs before all repositories/controllers. A handler with explicit dependencies/SQL may complete a scenario. Extract an application service for actual policy, reuse, or transport separation.

## Make execution visible

Handlers/services should read as actions. Helpers may hide parsing; writes, transactions, retries, access checks, and public errors need clear owners. Composition owns dependencies/lifetimes; applications own business decisions.

Use constructors and typed functions. Do not replace removed container/autowiring/locator/provider machinery with a generic dictionary/resolver. Routes receive constructed RequestHandler objects. Scenario code returns data/response/errors; the transport owns sending and its loop.

Use ordinary PHP. No reflection/attribute discovery for dependency assembly. Registries, event buses, and hierarchies need demonstrated benefit and must not restore hidden resolution. Mutable globals, include-time I/O, and hidden dependencies obstruct reuse and persistent-worker behavior.

## Keep boundaries small

- Start with concrete classes/values. Interfaces expose methods a consumer needs. One implementation can justify an interface when it isolates real I/O or protects dependency direction.
- Use core HTTP types and direct PDO. Do not restore Nyholm/PSR through full substitute interfaces or mirror every class method in an interface.
- Generalize shared semantics and reasons to change. Small duplication can be simpler than a generic platform. Access checks, ownership, and safe headers must stay consistent.
- Avoid Utils/Common/Base dumping grounds and generic repositories without demand. Resolve cycles by merging jointly changing code, sharing a type, or moving orchestration toward consumers.
- Describe fixed arrays with precise PHPStan types. Introduce identifier/state types when they prevent real errors, rather than a class for every intermediate array.

## Separate contract and policy

HTTP/JSON/protobuf/generated DTOs are transport contracts. Define requiredness, absence/null/default, units, and ranges. Internal refactoring must preserve wire behavior. PHPDoc supports analysis; external input and runtime invariants need real checks.

An endpoint may accept generated DTOs. Add separate domain models/mappers for actual contract/policy differences; do not duplicate every message mechanically. [Protobuf generation](../design/protobuf-contract-generation.md) supports a limited Echo slice and rejects unsupported declarations.

Generation owns mechanical contract work. Applications own access rules, SQL, transactions, and business decisions. Change generated files through inputs/generators. SQL stays readable/parameterized; generation does not justify unverified query-plan or semantic changes.

## Preserve error meaning

Distinguish expected input/missing-data errors from infrastructure failure and broken invariants. Missing fields, parsing failures, and database errors must not become successful empty results. Preserve exception causes and operation context; do not branch on message strings.

A lower layer reports outcomes; the scenario decides continuation; HTTP selects the public response. Avoid logging one exception at every layer. Do not expose SQL, traces, paths, or secrets. Choose safe log context deliberately.

Retry needs a specific transient cause, safe repeatability, an attempt limit, and a time bound. After an uncertain write, inspect state before repeating. Do not add a generic retry platform without such a scenario.

## Own resources and state

Every file, body, transaction, and child process needs an owner on every exit path. Express ownership transfer in contracts. Use finally for cleanup and account for write/completion errors before reporting success.

Follow [HTTP lifecycle](../architecture/request-lifecycle.md): App returns a response; RoadRunnerRuntime closes its body after emission. AttachmentResponse accepts stream ownership. Diagnostics never read or rewind payload.

Keep request state in the request. Do not retain requests, bodies, or user data in long-lived services/closures. Bound caches/queues and their lifetimes. A stream abstraction alone establishes no memory bound.

Use monotonic time for durations and explicit UTC/units for persisted timestamps. Supply a clock/value when time controls behavior. A generic request deadline requires an operation that actually observes it.

## Bound I/O where it happens

Check body/file/SQL/process-output size at the read/write boundary. Choose and document limits for current scenarios. LIMIT bounds result rows, not row width or scanning; fetchOne alone bounds neither.

Check access, canonical identifiers, and paths before effects. Migrations run explicitly outside HTTP startup. Transactions bound consistent changes. Network/generation/processes must not appear as hidden graph-construction work.

CLI tools receive argv arrays, cwd, and controlled environments. Never interpolate user data into shell source. Potentially hanging operations need termination, bounded output, and cleanup of their own processes/files. Define partial-write behavior and preserve prior usable output where needed.

## Verify useful behavior

Check caller-visible outcomes and meaningful failures rather than private-helper call sequences. Use real internal components. External process/protocol boundaries can use local fixtures without mocking internal services.

Default verify is autonomous. Check SQL/migrations on PostgreSQL, transport on RR3, and memory across the complete path/duration relevant to the claim. Protobuf requires input/output checks, generated PHP loading, and real HTTP. Successful generation alone does not prove a working endpoint.

Exercise invalid input, integrity failures, unavailable resources, and the next request after an error. Do not invent unit tests for prose edits. Mandatory gates remain in AGENTS.

## Keep changes reviewable

Dependencies enter only through fixed [bundled delivery](../design/autonomous-core.md). Explain what complexity they replace and how they update. Do not import devctl's Go provisioning/install behavior.

Optimize measured costs while preserving semantics and control flow. Report the result, developer steps, checks, and limits. A completed stage includes code, tests, and current contracts; unrelated refactors/format churn stay separate.

## Origin

Adapted from the owner's devctl/docs/coding-guidelines.md and devctl/docs/decisions/0003-unix-philosophy-and-vertical-slices.md, read on 2026-10-20 at revision 31d31532f682226d58389aae92b6a0663a3f8cf3. This is a standalone PHP adaptation of Unix composition and vertical slices. Go/macOS/daemon, VPN, installation, and devctl operational policies do not become Zerno requirements. Applying these rules needs no devctl access.
