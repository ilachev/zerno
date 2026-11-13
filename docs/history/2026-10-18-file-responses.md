# File responses and transparent diagnostics

Type: `history`. Status: `historical`. Verified: 2026-10-18. English edition: 2026-11-13.

Based on 31bd2b2: AttachmentResponse, metadata-only HttpLogging, explicit SAPI body cleanup, and catalog download as consumer. Current [contract](../architecture/request-lifecycle.md#attachments-and-ownership) and [guide](../development/http-and-baseline.md#file-path-cost) supersede old instructions. Bundled code/SQL/generated artifacts were unchanged.

## Result and cost

One helper replaced manual headers without registration/interfaces. Caller supplies a fresh open stream/verified size and transfers ownership. ASCII/UTF-8 safe names, GET/HEAD, cleanup, and non-consuming logging were checked. Document/publication/FileStore/PDF/no-store stayed local; no Range/upload/validation platform was added.

Logging removed payload/preview but retained query context. body_size meant PSR metadata, not transferred bytes; unknown could be null or socket metadata zero. Metadata may call fstat; it does not read/seek/rewind. Two records and metadata have costs. Default RR chunk 0 still buffered; 8192 bounded this tested file path. Unused helper/middleware adds no new I/O, without implying zero bootstrap cost.

## Verification

verify passed 361 unit/1,359 assertions, PHPStan 10/style/integrity (1,945 files)/docs/sync. Integration passed 38/361 plus PostgreSQL SQL checks, including catalog 12/174.

Tests covered filename/header injection/UTF-8/separators/controls/negative size/construction cleanup; logging at beginning/middle/EOF, sockets/unknown sizes/logger failure; SAPI non-seekable/bounded reads/unread HEAD/1xx/204/304/cleanup; real RR PSR7Worker chunks and exact 16,401 bytes plus transport failure after the first chunk.

Actual HTTP passed 188 cases: 47 each for FPM/RR × JSON/HTML. Twenty file cases per profile covered GET/HEAD sizes 0/8191/8192/8193/1 MiB/16 MiB+17 and four repeated large pairs, exact length/hash/headers/no session/same PID. Limit 32M; warmed retained PHP growth at most 2 MiB. Client buffering was separate.

Another catalog preflight passed 52 cases across both runtimes, logging enabled, sizes 512/8191/8192/8193/1048576/16777233, repeated requests and hidden/missing/truncated records. Exact hashes/headers stayed correct. PostgreSQL tests covered unavailable/mismatched files, attachment/HEAD/Range→200 using isolated schemas and a cached image without pulls.

## Measurement

The same no-SQL fixture opened a private regular file → AttachmentResponse → pipeline → FPM/SAPI or RR 8192 → Caddy → k6. Within each runtime only HttpLogging presence changed; native logger implementations remained. This measures new logging enabled/disabled, not speedup against unsafe payload logging.

macOS arm64/M3 Max; PHP 8.5.9, RR 2025.1.15, Caddy 2.11.4, k6 2.2.0; one worker/profile, 32M, OPcache on, JIT off. Files repeated bytes 0–255 with warmed page cache. Host/background tasks were uncontrolled, though own checks/load did not overlap.

Three repeats per combination: two-second warmup, five measured seconds, one VU, exact 1 MiB SHA-256 each response. Second repeat reversed order. Closed-loop rate is not input rate/capacity. ps sampled runtime parent/child and separate Caddy roughly every 100 ms; k6 excluded. CPU is group delta; RSS sampled sum can double-count pages/miss peaks.

| Profile | Logging | RPS | p50/p95/p99 ms | Runtime CPU ms/request | RSS MiB | Log bytes/request |
| --- | --- | ---: | --- | ---: | ---: | ---: |
| FPM | off | 149.27 | 5.913/9.264/10.038 | 2.530 | 38.97 | 0 |
| FPM | on | 148.40 | 6.046/7.390/10.208 | 2.642 | 39.00 | 203 |
| RR 8192 | off | 41.19 | 23.688/24.789/25.281 | 31.353 | 93.44 | 286 |
| RR 8192 | on | 41.31 | 23.635/24.596/24.912 | 31.401 | 93.34 | 627 |

Rate/latency/CPU are medians; RSS is maximum sampled sum across runs. RR included native info request logs; middleware added about 341 bytes/request, versus FPM 203. Log size followed metadata/envelope, not payload. Caddy separately consumed median 3.989 ms CPU/request for FPM and 6.377–6.425 for RR, sampled RSS up to 47.17/45.27 MiB.

Thirty-second logged 16 MiB+17 runs completed FPM 492/RR 81 exact GETs with no errors and a same-worker HEAD. PHP allocation before/after was FPM 2/2 MiB, RR 4/4. RSS start/end/max: FPM 38.86/38.86/38.86, RR 93.83/95.19/95.19 MiB. CPU: FPM 11.22+Caddy 29.03 seconds, RR 39.93+Caddy 8.14. Multithreaded Go CPU can exceed wall time. Small RR growth remains; thirty seconds establishes no leak freedom.

A separate ten-pair post-send probe measured PHP peak allocations of 2 MiB FPM/4 MiB RR: FPM shutdown after emission, RR before the next request. This excludes full materialization of the chosen file in PHP; Go RSS has separate cost. The wrapper/raw readings are saved and were absent from latency/CPU series.

All k6 checks passed, failures zero; bytes/log growth/configs are in [JSON](2026-10-18-file-responses.json). Different transports/lifetimes/loggers prevent a runtime-winner claim. Short logging differences establish no statistically significant gain.

## Reproduction and limits

Use historical front/server configs in JSON, replacing @ROOT@ with your checkout and selecting free ports. Removed FPM/config options need the historical code, not current commands. The source records APP_CONFIG/file/log/environment/storage settings. Never stop unrelated processes.

Use load-tests/files/scenario.js with owned BASE_URL, prepared hash, FILE_SIZE=1048576, DURATION2s/5s and distinct summaries. Restart for logging 0/1 and separate warmup. Longer series uses 16777233, logging 1, two-second warmup/thirty seconds measured. Check HEAD/PID/PHP memory and all runtime/front counters; keep logs in ignored var.

Logical payload bytes and log-file growth were measured, not physical disk/page-cache I/O. Wire HEAD checks no content; unit tests check unread bodies. Stable seekable files do not establish arbitrary-stream, abort, mutation, or endurance behavior. At this snapshot bundled RR could suppress some read failures; finally cleanup did not repair that upstream contract. Default0 remained buffered. Error/auth/PDF/storage policies were not expanded.
