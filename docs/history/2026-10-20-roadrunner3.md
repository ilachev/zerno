# Migration to RoadRunner 3

Type: `history`. Status: `historical`. Verified: 2026-10-20. English edition: 2026-11-13.

Before: a0d0b12037dc804957144a469b81755ba1a06900, with local RR 2025.1.15. App/composition/handlers/SQL and then-supported SAPI remained. [Data](2026-10-20-roadrunner3.json) records refs/provenance/checks; [lifecycle](../architecture/request-lifecycle.md) and [delivery](../design/autonomous-core.md) own current behavior.

## Composition and provenance

| Component | Before | After |
| --- | --- | --- |
| Server | 2025.1.15 | 3.0.0 darwin/arm64, Go 1.27.2 |
| Worker | spiral/roadrunner-worker 3.6.2 | roadrunner/worker 3.8.0 |
| HTTP | spiral/roadrunner-http 4.1.0 | roadrunner/http 4.2.1 |
| Goridge | spiral/goridge 4.2.1 | roadrunner/goridge 4.6.0 |
| DTO | roadrunner-php/roadrunner-api-dto 1.14.1 | roadrunner/api-dto 2.1.0 |
| Metadata | spiral/roadrunner 2025.1.11 | spiral/roadrunner 3.0.0 |

Four PHP trees came from official GitHub tag/commit archives through withproxy, without a package manager. Manifest records archive SHA-256/refs/notices/file hashes. Complete trees increased all-three-distribution files from 1,614 to 2,098. This is static footprint, not per-request loading. Google protobuf 5.34.0/polyfill 1.32.0 were unchanged.

Tagged server metadata still called the metapackage spiral/roadrunner while newer transport referenced roadrunner/roadrunner. Declarations stayed as provenance; no resolution occurs. Namespaces remain upstream; paths use roadrunner/*. Core capabilities did not expand.

The separately downloaded 3.0.0 binary went into ignored var/rr3/bin/rr. Archive digest matched the official HTTPS release asset, without independent signature verification. It was neither packaged nor downloaded by project commands. Existing Homebrew RR was untouched; PATH/--rr selected RR 3.

## Adaptations and behavior

Both worker patches were rebased to 3.8.0 with original/final hashes. Version reads pinned metadata without Composer; it does not detect executable version. The unused eager logger was removed without frame changes. Native HttpWorker feeds core types; PSR/Nyholm/Composer were not restored.

HTTP 4.2 preserves numeric header names, including PHP integer keys. Core and SAPI/RR boundaries were adjusted. Actual requests/responses retain numeric/multiple headers and separate cookies.

YAML version: '3' is config format. No server http.middleware required reordering; PHP Pipeline order stayed. Root log ERROR and server INFO/production preserve PHP stderr; checks confirm time/INFO envelopes, metadata, and private errors in logs. PHP Logger format stayed. Upstream asynchronous output is not a reliable overloaded audit guarantee.

## Verification and limits

verify:full passed 231 unit/923 assertions, 167 SQL-tool/625, 32 PostgreSQL/272 with level-10 analysis/style/docs/integrity/sync. FPM/RR 3 passed 204 cases for both renderers, forms/uploads/numeric/multiple headers, state, bodyless responses, and large files under 32M. A real Worker/StreamRelay binary-frame test verified version without Composer/PSR logging.

Archive checks passed 43 HTTP cases with migrate/seed/rollback/reapply and twenty-one generated rows. Clean bootstrap created real Worker without Composer/PSR. Root run returned health without a database. Results are in JSON.

Users supplied RR 3 and reused composition. At this snapshot SAPI required neither RR 3 nor sockets. Costs included larger trees, explicit logging rules, and two maintained patches. Comparable CPU/RSS/latency/throughput were not measured. Memory smoke did not establish endurance/client-abort coverage/readiness.

Primary references: [RR 3 release](https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.0), [migration guide](https://github.com/roadrunner-server/docs/blob/release/v3/intro/v3-migration.md), [worker](https://github.com/roadrunner-php/worker/releases/tag/v3.8.0), [HTTP](https://github.com/roadrunner-php/http/releases/tag/v4.2.1). Upstream results do not substitute for local checks.
