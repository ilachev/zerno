# Public catalog implementation and measurements

Type: `history`. Status: `historical`. Verified: 2026-10-17. English edition: 2026-11-13.

The public slice worked: HTML list → detail → published PDF, without JavaScript/sessions/installation. It was a separate application example. [Current startup](../../examples/document-catalog/README.md) and [boundaries](../project/document-catalog-example.md) supersede historical plans/configuration.

Measured after 30866ad plus this iteration's changes. [JSON](2026-10-17-public-catalog.json) preserves source hashes/platform/server configs/plans/twelve k6 summaries/file checks. Earlier JSON/SELECT results were not rewritten; different work prevents direct speed comparisons.

## Result and costs

Separate config/routes, three handlers, one explicit-SQL repository, and PHP templates completed GET/HEAD list/detail/PDF, HTML 400/404/405/500, keyset navigation, hidden-state checks, and download metadata. The operator supplied database/private directory and explicit migration/seed, then selected the then-supported SAPI or RR. No ORM/DSL/frontend build was needed. Editing/forms/upload/login/CSRF/audit were absent.

Each useful request had one SELECT; list SQL used LIMIT 21 to show twenty rows without COUNT/per-row queries. The application migration owned a partial published index. Generator support was not expanded merely for this application. FPM recreated connections/statements while RR reused them; runtime/budgets remained undecided.

Only example bootstrap registered its namespace. An unselected example ran no SQL/files/middleware, while shared bootstrap costs remained. Runtime packaging included application source but excluded development tools/tests/load scenarios.

At this snapshot RoadRunnerServiceProvider(chunkSize:8192) selected upstream streaming; default 0 buffered and negative sizes failed. It required seekable regular bodies and lacked informational streaming support. Runtime suppressed unread HEAD/1xx/204/304, stripped lengths for 1xx/204, and closed bodies in finally. Direct App returned an unsent/unclosed response. No before/after adapter speed comparison was performed; bundled code was unchanged.

## Functional checks

Root verify: 329 unit/1,158 assertions, level10/style/integrity/docs/SQL. Integration:38/361, including catalog12/174. Fixtures checked forty-five published/two hidden rows, pages20/20/5, SQL bound21, one SELECT, escaping/cursors/IDs/headers/files, repeat migration, and metadata constraints. Unpublishing prevented new requests immediately but did not revoke completed or authorized in-flight downloads. Missing/truncated files returned404.

Then-current composition checked health/routing without storage resolution; constructing a document handler could still resolve Storage even before SELECT. Both runtimes passed twenty-four common HTTP cases plus thirty catalog preflight cases through Caddy. RR separately passed 16 MiB+17 hash with GET/HEAD/204/304/GET in one worker; actual PSR7Worker unit checks bounded chunks8192.

Archive extraction excluded tools/tests/load/codegen and passed migration CLI, twenty-row list, exact PDF, and no-storage health. Browser review covered title/main/h1, pagination, visible keyboard focus, skip link/focus transfer, and keyboard detail navigation. Download text disclosed title/type/size. Screen readers, full WCAG AA, and PDF accessibility were not assessed.

## Platform and HTML protocol

macOS26.6.1 arm64/M3 Max14 cores/36 GiB; PHP 8.5.9/RR 2025.1.15/Caddy 2.11.4/k6 2.2.0; PostgreSQL 16.15 in a temporary Docker VM 14 CPU/about 7.82 GiB. OPcache on, JIT off; settings/pool overrides saved, ini unchanged. Host load was not isolated.

One worker/profile; Caddy :18281→FPM:19201 and :18282→RR:19202. Seed had twenty-four published/draft/withdrawn plus two published PDFs of1/64 MiB: twenty-eight rows, twenty-six published. Small data did not establish large-catalog costs.

For strict reproduction use the snapshot's configs/fixture, including extra PDFs. native_configs in JSON can be extracted into var/catalog-benchmark, with APP_CONFIG/ZERNO_ROOT and matching DB_*/CATALOG_FILES_DIR. Historical FPM/Caddy commands require historical code. Each run used four VUs, two-second warmup/five measured seconds, separate summaries; three repeats reversed runtime order in repeat2, list before detail. No browser/project checks ran during final measurement. The recorded default title was Russian; current English seed/load defaults differ.

## HTML results

Medians are per-run metrics, not pooled percentiles. Rate is observed closed-loop throughput rather than capacity.

| Profile/case | RPS | p50 ms | p95 ms | p99 ms | Errors |
| --- | ---: | ---: | ---: | ---: | ---: |
| FPM/list | 81.85 | 48.547 | 56.556 | 61.727 | 0 |
| FPM/detail | 84.93 | 46.092 | 55.225 | 61.842 | 0 |
| RR/list | 1120.01 | 3.406 | 4.819 | 5.770 | 0 |
| RR/detail | 1224.66 | 3.161 | 4.346 | 5.303 | 0 |

All eight response checks passed: status/type/title/h1/content/cache/nosniff/no cookies. SQL/request1 was supported by handler/integration checks, not an external tracer, excluding connection/search_path/prepare. Connection/lifetime/logging/transport prevent attributing differences solely to HTML or SELECT.

| Profile/case | Pool CPU s | Caddy CPU s | Median sampled peak pool RSS MiB |
| --- | ---: | ---: | ---: |
| FPM/list | 1.48 | 0.28 | 54.64 |
| FPM/detail | 1.52 | 0.27 | 54.66 |
| RR/list | 2.88 | 1.86 | 103.12 |
| RR/detail | 2.88 | 1.76 | 105.17 |

ps sampled parents/descendants every250 ms. CPU is client-command interval delta, not per-request normalization. Caddy is separate; inactive runtime/k6/PostgreSQL/VM are excluded. Shared pages affect RSS sums and brief peaks may be missed.

## Download resources

A streaming client read at most64 KiB and hashed without a full file buffer:608 bytes→1 MiB→64 MiB→1 MiB→608, HEAD after each, then100 small GETs in the same worker. All sizes/hashes matched; HEAD sent zero with correct length. Large reads deliberately slept2 ms/block to aid sampling, so duration is not throughput evidence.

| Profile | Pool RSS before/peak/after KiB | PHP worker RSS before/peak/after KiB |
| --- | --- | --- |
| FPM | 55968/55968/55968 | 27824/27824/27824 |
| RR | 107792/111392/111392 | 43792/43792/43792 |

Caddy peak was51824 KiB. About3.5 MiB pool growth was in Go while PHP stayed unchanged, consistent with streamed delivery across a63 MiB size increase. This establishes neither absolute memory stability nor leak freedom. PHP heap/FD/full series were not saved; JSON contains aggregates/sample counts.

The permanent k6 scenario covered HTML only; file checking was a separate probe with recorded metadata. Reproduction needs equivalent files/block sizing/pacing, without conflating client k6 response buffering with server memory.

## Scope and cleanup

Editing would require identity, permissions/CSRF, migration recovery, and database/file consistency. Deployment still needed sustained-worker/target-platform checks. This was not a security/full-accessibility audit or runtime selection. Owned servers/schema/container/fixtures were cleaned; report data was copied from ignored local logs/summaries into JSON.
