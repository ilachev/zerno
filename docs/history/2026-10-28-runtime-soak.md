# HTTP 205 metadata upgrades and sustained runtime validation

Type: `history`. Status: `historical`. Verified: 2026-10-28. English edition: 2026-11-13.

## State and result

The next [ADR stage](../design/adr-2026-10-28-contract-correctness.md) implemented bodyless HTTP 205, BIGINT migration timestamps, and explicit upgrade-metadata. The [migration contract](../design/transactions-and-migrations.md#bigint-timestamps-and-explicit-metadata-upgrade) owns maintenance rules; [lifecycle](../architecture/request-lifecycle.md) owns HTTP. These are correctness changes without a claimed speedup.

Actual RR passed 144 JSON+144 HTML wire cases. PostgreSQL checked preserved INTEGER history, no implicit ALTER, repeated upgrade, PHP_INT_MAX, and failed-conversion rollback. The extracted archive also preserved an old row and accepted PHP_INT_MAX. verify:full passed 316 root/170 SQL-tool/62 PostgreSQL tests.

## Platform and method

Code was based on Git 22dfa6d with uncommitted ADR changes. [Raw JSON](2026-10-28-runtime-soak.json) retains source/runtime-manifest/RR hashes, full k6 summaries, and process samples. Recorded runtime/fixture sources stayed unchanged during measurement. This English narrative does not modify that raw snapshot.

Linux aarch64 in Docker Desktop: PHP 8.5.12, pdo_pgsql/sockets, libpq 17.11, PostgreSQL 16.15; RR 3.0.1, Go 1.27.3; k6 v2.2.0 commit/devel with go1.26.5 darwin/arm64. The external PHP platform was prepared from a cached image with libpq-dev/pdo_pgsql, outside project checks/build and without bundled changes. VM: fourteen CPUs/8 GiB. Workers: CLI OPcache on, 64M limit, chunks 8192, pool two, max_jobs zero. The graph includes baseline/wire Apps and metadata logging; its cost is not that of a minimal core.

Final run: sixty-second warmup and 900-second measurement at ten iterations/s, four-request SQL batches, 10,000 records. Other operations: JSON/manual/generated Echo/context resets, 1 MiB and 16 MiB+17-byte GET/HEAD with SHA-256, 204/205 and expected 400/404/405/500. A sequential pilot mostly selected one worker, so the final version used short SQL bursts. The [guide](../development/http-and-baseline.md#mixed-sustained-run) describes reproduction.

## Observations

k6 completed **16,564 HTTP requests**, **99,384 passed checks**, zero failed checks, unexpected/network HTTP failures, or dropped iterations. Expected errors have explicit status/privacy checks. http_req_duration includes sending/waiting/receiving; client SHA-256 checks occur afterward in iteration_duration ([k6 definitions](https://grafana.com/docs/k6/latest/using-k6/metrics/reference/)).

Mixed HTTP latency: p50 **1.96 ms**, p95 **13.70 ms**, p99 **498.52 ms**, max **767.58 ms**. The workload includes large files and establishes neither capacity nor a comparison between implementations.

| Process | First 60s median RSS MiB | Last 60s median RSS MiB | Max sampled RSS MiB | CPU seconds | FD range |
| --- | ---: | ---: | ---: | ---: | ---: |
| RR server | 41.27 | 23.88 | 53.89 | 41.33 | 15–31 |
| PHP worker 16 | 31.58 | 17.29 | 31.77 | 94.89 | 6–7 |
| PHP worker 17 | 31.57 | 17.27 | 31.76 | 94.22 | 6–7 |

PID/start_ticks stayed constant for all three processes; both workers consumed CPU. PHP allocation/peak telemetry is separate from RSS. JSON includes lifetime kernel RSS peaks. Five-second sampling misses brief spikes. CPU is utime+stime delta with CLK_TCK=100. RSS sums may double-count shared pages; PostgreSQL/host k6 are excluded.

## Scope and limits

Correctness, identities, and observed finite memory/FD ranges apply only to this run. Latency/resource values are a local baseline, not a universal SLO. Explicit bounds were 64M Zend limit per worker, two workers, files through 16 MiB+17, ten-second client timeout, maxVUs twelve. RSS differs from Zend allocation. verify:full and archive CLI smoke ran on the host during the first measurement part, potentially affecting timing/residency.

Fifteen minutes does not establish multi-day leak freedom, arbitrary streams, client cancellation, database loss, or deployment readiness. Online security audit was not repeated; the scoped Linux amd64 assessment does not cover the used arm64 executable. Future operational checks need a real application profile, duration, budgets, and target platform. Owned processes/schema/files were cleaned separately from retained results.
