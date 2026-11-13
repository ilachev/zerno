# Native HTTP types without Nyholm or PSR packages

Type: `history`. Status: `historical`. Verified: 2026-10-20. English edition: 2026-11-13.

After 9c300d3, handlers/middleware/errors/consumers moved to concrete core Request/Response/Body/Uri/UploadedFile. [Lifecycle](../architecture/request-lifecycle.md) and [delivery](../design/autonomous-core.md) own current contracts. [Data](2026-10-20-native-http.json) records the previous state/probe/raw samples.

## Decision and maintenance cost

Developers accept Request, return Response(status, headers, string|Body), and pass streams to AttachmentResponse. Composition/routes retain constructed objects. No full PSR-7/17 surface or extra message factories/interfaces is reproduced. This breaks PHP compatibility; external PSR middleware needs integration not supplied here.

Removed nyholm/psr7 and PSR HTTP/factory/log. Seven retained runtime packages support RR/protobuf/DTO. Existing core Logger remained; a test LoggerTrait was replaced with explicit implementation. Unused upstream adapter/logger source remains provenance and unloaded. A recorded worker patch removed eager PSR logging without frame changes; notices/upstream/final hashes and both adaptations remain in manifests.

The project now owns header/URI/upload validation and body semantics. Fewer vendor files alone does not justify that maintenance cost. String Body avoids php://temp; file Body owns a resource. Metadata copies share it; close is idempotent. RR chunks retain current/next blocks, while default 0 buffers. Read failures are rethrown after native stream completion; transport errors propagate.

RR libraries load only when used, but bootstrap/maps still cost work. Unused file helpers open nothing. The following measures only a small PHP path.

## Verification

verify:full passed 229 unit/908 assertions, 167 SQL-tool/625, 32 PostgreSQL/272; PHPStan 10/style/docs/sync/integrity, three distributions/1,614 files. FPM/RR passed 51 cases × two renderers × two transports = 204, extending the previous 188 with forms/query/cookies/raw JSON/nested multipart/moveTo/subsequent empty request.

Tests exercised body/header/metadata/URI/status, upload failure/repeated move/SAPI validation, unread HEAD/1xx/204/304, closure on transport/logging/read failure, non-seekable buffering/chunking, and mid-stream errors using real internal types/ObservedStream. WorkerInterface was isolated at the external boundary. Archives separately passed 43 HTTP cases, migrations/rollback/reapply, and twenty-one generated rows; clean bootstrap loaded no legacy interfaces.

Files through 16 MiB+17 bytes retained length/hash under 32M and repeated GET/HEAD. This establishes regular-file/PHP-allocation behavior, not RSS peaks/endurance or a full upload/proxy/security audit.

## Bounded comparison

PHP 8.5.9 CLI/OPcache off; seven alternating process pairs after discarded warmups. Each measures bootstrap, then 10,000 fresh GET /health with content-type/ID/status/body checks and both bodies closed. No network/database/emission. Raw samples are in JSON.

| Median | Before 9c300d3 | After |
| --- | ---: | ---: |
| Bootstrap/composition ms | 1.038166 | 1.083583 |
| 10,000 checked requests ms | 73.214 | 60.24325 |
| PHP bytes after requests | 883,720 | 689,416 |
| Peak PHP bytes | 971,704 | 736,952 |
| Included files | 45 | 37 |

The measured loop was cheaper; startup did not improve. This does not establish site throughput, p95/p99, RSS, or endurance. Own runtime files rose from 49 to 56: package removal transferred responsibility into project code/tests.
