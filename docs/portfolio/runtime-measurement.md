# Measuring a persistent HTTP runtime

Type: `history`. Status: `historical`. Reviewed: 2026-11-13.

A mixed workload exercised two persistent PHP workers to inspect correctness, request-state isolation, and resources. The owner requested an assessment of load; the agent built and ran the scenario. The [report](../history/2026-10-28-runtime-soak.md) and [raw JSON](../history/2026-10-28-runtime-soak.json) identify platform and source hashes.

## Method

The run used PHP 8.5.12, RoadRunner 3.0.1, and PostgreSQL 16.15 on Linux aarch64 in Docker Desktop. After a 60-second warmup, k6 ran for 900 seconds at 10 iterations/s. SQL batches contained four parallel requests; other iterations exercised JSON, manual/generated Echo, state resets, large file GET/HEAD, bodyless statuses, and expected application errors.

The [scenario](../../load-tests/soak/scenario.js) checks content, file hashes, request IDs, and worker telemetry. [Process sampling](../../load-tests/soak/sample.php) records identity, RSS, CPU, and file descriptors. The [guide](../development/http-and-baseline.md) describes setup and cleanup.

## Results

| Observation | Measured result |
| --- | --- |
| HTTP requests | 16,564, approximately 18.4 requests/s |
| Content/protocol checks | 99,384 passed; zero failed checks or dropped iterations |
| Unexpected HTTP/network failures | Zero; intentional application errors were checked separately |
| Mixed HTTP latency | p50 1.96 ms; p95 13.70 ms; p99 498.52 ms |
| Worker identity | Both workers remained the same processes during sampling |
| Worker RSS | Maximum sampled RSS about 31.8 MiB each; lower medians at the end |

The mix included 16 MiB responses. Its aggregate p99 cannot be treated as JSON or SQL p99. Receiving metrics suggest body transfer contributed to the tail; per-route quantiles were not collected.

## Interpretation

This was a modest correctness/resource baseline. It did not find saturation, compare PHP with Go, or establish a deployment SLO. Five-second process sampling can miss brief spikes; summing RSS can double-count shared pages. PostgreSQL and the host load generator are outside the resource table.

Fifteen measured minutes do not establish multi-day operation. Database outages, arbitrary streams, and client cancellation were outside the workload. Background verification during the first part limits comparisons. Security conclusions for another architecture's binary do not transfer to the measured executable.

The next measurement needs an application profile, target platform, per-route latency, and explicit budgets. The case demonstrates keeping conclusions within the workload actually run.
