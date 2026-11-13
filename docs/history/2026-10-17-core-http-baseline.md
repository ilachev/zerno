# Initial HTTP and PDO baseline

Type: `history`. Status: `historical`. Verified: 2026-10-17. English edition: 2026-11-13.

First bounded iteration after 612d009: routing fixes, FPM/RR wire checks, short JSON/SELECT scenarios. [Data](2026-10-17-core-http-baseline.json) preserves source hashes, environment, EXPLAIN, and twelve runs. This does not select the main runtime or budgets.

## Changes and costs

Router escapes literal parameter-pattern parts and compiles at construction. Another method's static route no longer blocks a matching parameter route. 405 has a distinct error and combined Allow; numeric methods stay strings. Checks cover explicit HEAD/trailing slashes. RouteResult gained getAllowedMethods while getHandler stopped carrying methods; its first three constructor arguments stayed.

Developer registration stayed method/path/handler with explicit dependencies. Static success retains direct lookup; parameter dispatch scans that method's candidates; 405 checks others. Patterns consume router memory. FPM rebuilt the graph per request; RR built it per worker. No before/after router benchmark was done. Fixtures were checkout-only with separate installed tools.

## Platform and protocol

macOS 26.6.1 arm64, M3 Max fourteen cores/36 GiB; PHP CLI/FPM 8.5.9; OPcache on for FPM/RR, JIT off despite a 64M buffer setting. JSON retains separate settings and post-series php-fpm -i; ini stayed unchanged. RR 2025.1.15, Caddy 2.11.4, k6 2.2.0; one PHP worker/profile and ordinary providers/loggers.

PostgreSQL 16.15 used a cached postgres:16-alpine container/random schema, Docker VM fourteen CPUs/about 7.82 GiB. No pulls/installations. Clients/servers shared the host, without CPU isolation. Every run used two-second four-VU warmup then five measured seconds at four VUs, closed loop with exact response checks.

One Caddy served :18081 → FPM :19001 and :18082 → RR :19002. These were end-to-end profiles with different transport/lifetimes, not isolated interpreter comparisons. JSON returned {"id":42,"value":"record-42"} without Storage; SELECT looked up the same row among 10,000, confirmed Index Scan/one row. SQL/request 0/1 came from handler inspection, excluding connection/search_path/prepare. FPM recreated PDO/statements and logged storage creation per SELECT; RR reused them. Those costs were not isolated.

Three repetitions/case alternated runtime order FPM→RR, RR→FPM, FPM→RR; JSON preceded SELECT. Diagnostics/warmups were excluded. No project tests/analysis ran during the final series. A historical run looked like:

```bash
BASE_URL=http://127.0.0.1:18081 CASE=json VUS=4 DURATION=2s SUMMARY_PATH=var/warmup.json \
php tools/project.php perf:baseline --quiet
BASE_URL=http://127.0.0.1:18081 CASE=json VUS=4 DURATION=5s SUMMARY_PATH=var/fpm-json-1.json \
php tools/project.php perf:baseline --quiet
```

Change profile/port/case/output per run. Removed FPM configuration requires its historical source state. Only summary metrics, not per-request samples, were saved.

## Results

Values are medians of three run metrics, including medians of per-run quantiles rather than pooled percentiles. RPS is observed four-VU rate, not capacity.

| Profile | Case | RPS | p50 ms | p95 ms | p99 ms | Errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| FPM | JSON | 2703.83 | 1.419 | 1.674 | 1.905 | 0 |
| RR | JSON | 4512.06 | 0.851 | 1.004 | 1.162 | 0 |
| FPM | SELECT | 43.99 | 84.832 | 141.838 | 164.015 | 0 |
| RR | SELECT | 1552.39 | 2.685 | 3.318 | 3.901 | 0 |

All status/content-type/body/cookie checks passed. SELECT RPS ranged 42.1–59.0 for FPM and 1503.5–1586.2 for RR. Connection/prepare/logging/transport prevent attributing the difference solely to router or SELECT execution.

## Process resources

ps sampled parent/descendants every 250 ms. CPU is group-time delta around the client command, including client startup/finish interval; RSS is the maximum sampled group sum. Values again summarize three runs.

| Profile/case | Pool CPU s | Caddy CPU s | Pool RSS MiB | Caddy RSS MiB |
| --- | ---: | ---: | ---: | ---: |
| FPM/JSON | 3.92 | 4.57 | 54.31 | 52.44 |
| RR/JSON | 8.07 | 5.98 | 108.97 | 52.39 |
| FPM/SELECT | 0.98 | 0.18 | 54.31 | 52.44 |
| RR/SELECT | 3.04 | 2.07 | 109.20 | 52.44 |

Pool means FPM master/child or RR server/worker. Caddy is separate and counted once. Both runtimes stayed running but inactive costs were excluded. CPU is not normalized per request; counts differ substantially. k6/PostgreSQL/VM/unrelated processes are excluded, although database delay enters HTTP latency.

RSS can double-count shared pages; separate maxima may occur at different times. Sampling misses brief peaks and adds overhead. Short duration establishes no memory-growth result. Full process time series were not retained.

## Verification and next stage

Actual checks passed twenty-four cases each for FPM/RR: routing/methods/Allow/HEAD/204/304/multiple headers/cookies/path/query/state. FPM used direct FastCGI; RR used HTTP. This was not a full proxy/protocol matrix.

Root verify and PostgreSQL integration passed; prepare/lookup/cleanup used actual PostgreSQL. Owned servers/schema/container were cleaned. The next stage was catalog list/detail/download, followed by file memory, sustained workers/connections, and target-platform validation. No capacity or runtime-winner conclusion follows.
