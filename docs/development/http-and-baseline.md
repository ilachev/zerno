# HTTP checks and RoadRunner baseline

Type: `operations`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

RoadRunner 3.x is the sole HTTP runtime. App/handler separation supports composition/tests rather than promising other adapters. RR/sockets are platform requirements; PHP libraries/tools are bundled.

## Real HTTP checks

```bash
php tools/project.php http:check --rr=/path/to/rr3
```

The [checker](../../tools/check-http.php) owns temporary configs, a free loopback port, fixtures, and one worker with memory_limit=32M/chunk size 8192. It cleans up its own resources. No database, installation, or extra HTTP server is needed. Internal components are real; an external WorkerInterface double is used only in unit tests.

The matrix covers routing/405/Allow, HEAD/204/205/304, repeated/numeric headers, cookies/forms/query/nested uploads including name keys, and moveTo. Sequential requests check IDs/state. Files through 16 MiB+17 bytes have exact size/SHA-256 checks; repeated transfers check bounded warmed PHP allocation growth. Logging reads no payload; public errors omit private details.

This is limited HTTP/1.1 coverage, not complete HTTP/HTTP2/HTTP3/cancellation/endurance certification. 205 suppresses body without reading, advertises zero length, and still closes streams.

## Bounded JSON

[Manual](../../examples/json-echo/README.md) and [generated Echo](../../examples/contract-echo/README.md) share App/RR. Checks cover the 65,536-byte limit and neighbors, malformed JSON/UTF-8/types/media types, implementation/output failures, and a subsequent empty request. A temporary 1 MiB RR ingress cap rejects larger Content-Length and chunked bodies before PHP. Exactly 1 MiB reaches the handler and gets its 413. Server rejection format is outside application JSON contracts.

RR buffers incoming payload before the handler. readLimited bounds later reading/decoding rather than all transport memory. See [ownership](../architecture/request-lifecycle.md).

```bash
php tools/project.php http:check --rr=/path/to/rr3 --measure-contract
```

This compares 100 sequential manual/generated requests after twenty warmup pairs, alternating order, including socket/routing/logging. It is not a concurrency/capacity benchmark. Older [cross-server data](../history/2026-10-17-core-http-baseline.md) is historical; [RR-only scope](../history/2026-10-23-roadrunner-only.md) was a user decision.

## Baseline workload

[Composition](../../load-tests/baseline/config.php) creates Storage without PDO. /json returns {"id":42,"value":"record-42"} without SQL; /select/42 performs one parameterized lookup. [Database setup](../../load-tests/baseline/database.php) owns a random zerno_baseline_<24 hex> schema, 10,000 rows, ANALYZE, and EXPLAIN. SQL/request 0/1 counts application SELECTs, excluding connection/search_path/prepare. HTTP never runs migrations.

## Prepare an isolated schema

Use a precreated separate test database with schema-create permission. Set TEST_DB_* following [isolation rules](infrastructure-and-testing.md); do not use someone else's or an application database.

```bash
mkdir -p var
: "${TEST_DB_NAME:?Set a separate test database}"
php tools/project.php perf:prepare > var/baseline-fixture.json
export DB_HOST="$TEST_DB_HOST" DB_PORT="$TEST_DB_PORT"
export DB_NAME="$TEST_DB_NAME" DB_USER="$TEST_DB_USER" DB_PASSWORD="$TEST_DB_PASSWORD"
export DB_SCHEMA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["schema"];' var/baseline-fixture.json)"
rr serve -w "$PWD" -c load-tests/baseline/rr.yaml
```

Continue only after successful preparation. Do not overwrite active schema metadata. Store environment/credentials in ignored var with restricted permissions; the second terminal needs matching values. RR listens on loopback 19002 with one worker and CLI OPcache.

## Warmup and measurement

In a second terminal with the same environment:

```bash
BASE_URL=http://127.0.0.1:19002 CASE=json DURATION=5s \
SUMMARY_PATH=var/rr-json-warmup.json php tools/project.php perf:baseline
BASE_URL=http://127.0.0.1:19002 CASE=json DURATION=30s \
SUMMARY_PATH=var/rr-json.json php tools/project.php perf:baseline
```

Repeat with CASE=select and distinct summaries. Installed k6 is required; nothing downloads. Check exact results/status/errors/rate/quantiles; retain platform/settings/data and CPU/RSS for RR plus every worker. Account for database/k6 separately. RSS sums can double-count shared pages; snapshots are neither peaks nor proof against growth.

## Cleanup

Stop your RR with Ctrl+C, then remove only your fixture:

```bash
php tools/project.php perf:cleanup "$DB_SCHEMA"
```

Cleanup validates schema name, marker, and owner role. Do not remove unrelated schemas/databases/containers.

## Mixed sustained run

load-tests/soak/config.php explicitly composes baseline and HTTP fixtures. Two workers handle SQL batches of four concurrent requests, small JSON, manual/generated Echo, sequential context checks, 1 MiB/16 MiB+17-byte GET/HEAD, 204/205, and expected 400/404/405/500. The wrapper adds bounded PID/count/PHP allocation telemetry and retains no requests/bodies. This is a test graph, not minimal production composition.

Prepare PHP 8.5.12+/pdo_pgsql/sockets/OPcache, RR3, PostgreSQL, and k6 externally. Prepare the baseline schema as above, map test settings to DB_*, and create owned files/hashes:

```bash
mkdir -p var/soak/files
php -r 'require "tools/http-fixtures/AttachmentFiles.php"; echo json_encode(Project\HttpFixtures\AttachmentFiles::prepare($argv[1]), JSON_THROW_ON_ERROR);' var/soak/files > var/soak/hashes.json
export WIRE_FILE_DIRECTORY="$PWD/var/soak/files"
export FILE_HASHES="$(cat var/soak/hashes.json)"
rr serve -w "$PWD" -c load-tests/soak/rr.yaml
```

Configuration uses loopback 19003, two workers without max_jobs recycling, 64M limit, and chunks 8192. For container sampling, RR must be PID 1. Publishing a container port needs `-o http.address=0.0.0.0:19003`. Use an existing platform binary/image. After restarting a container with a dynamic host port, read docker port again.

In another terminal with matching FILE_HASHES and actual BASE_URL:

```bash
BASE_URL=http://127.0.0.1:19003 RATE=10 DURATION=60s SUMMARY_PATH=var/soak/warmup.json php tools/project.php perf:soak --quiet
BASE_URL=http://127.0.0.1:19003 RATE=10 DURATION=15m SUMMARY_PATH=var/soak/measure.json php tools/project.php perf:soak --quiet
```

RATE means iterations/s, not requests/s; batches/context pairs add requests. Checks validate SQL/JSON/hash/ID/state/no cookies/telemetry/errors. k6 represents 204 bodies as null and 205 as empty text; raw wire checks also verify no actual content. Thresholds require all checks, no unexpected/network failures, and no dropped iterations. Expected 500 status/privacy is checked separately.

Linux [sampling](../../load-tests/soak/sample.php) reads /proc RSS/peak/CPU/start-time/FD for PID-1 server and workers, excluding itself. A bounded series of 194 samples every five seconds covers warmup/measurement:

```bash
for sample in $(seq 1 194); do
    docker exec "$SOAK_CONTAINER" php load-tests/soak/sample.php >> var/soak/processes.jsonl
    sleep 5
done
```

SOAK_CONTAINER must be your server. Obtain CLK_TCK with getconf inside it; CPU seconds are delta ticks/CLK_TCK. Match pid and start_ticks to detect recycling/reuse. Zend allocation, kernel RSS/peak, and VM memory differ; shared pages affect sums. Track PostgreSQL/k6 separately. Compare first/last sixty measured seconds, extrema/FD, and activity of each worker. Save source/platform hashes, flags, workload/duration. Stop RR, run owned-schema cleanup, and remove owned fixtures.

The [2026-10-28 report](../history/2026-10-28-runtime-soak.md) preserves the final run. Fifteen minutes establishes that workload's observations, not multi-day stability, all-stream behavior, database-loss/client-cancellation handling, or production SLO. Compare only equivalent results/platform/load.

## File-path cost

The [file scenario](../../load-tests/files/scenario.js) checks sequential attachment GET/HEAD and exact SHA-256.

```bash
mkdir -m 700 -p var/file-benchmark
php -r 'require "tools/http-fixtures/AttachmentFiles.php"; echo json_encode(Project\HttpFixtures\AttachmentFiles::prepare($argv[1]), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);' var/file-benchmark > var/file-hashes.json
rr serve -w "$PWD" -c .rr.yaml \
  -o server.env.APP_CONFIG=tools/http-fixtures/config.php \
  -o server.env.WIRE_FILE_DIRECTORY=var/file-benchmark \
  -o server.env.RR_HTTP_CHUNK_SIZE=8192
```

WIRE_HTTP_LOGGING=0 supplies a comparison without middleware; default enables it. These are fixture settings. Supply BASE_URL, FILE_SIZE, EXPECTED_SHA256, DURATION, SUMMARY_PATH to k6 run load-tests/files/scenario.js. METHOD=HEAD expects no body with the same Content-Length. k6 materializes client bodies; its memory is separate. [Earlier file results](../history/2026-10-18-file-responses.md) describe a previous configuration.
