# JSON POST with a bounded body

Type: `operations`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

A small consumer of [Body::readLimited](../../src/Platform/Http/Body.php): one [EchoHandler](EchoHandler.php), explicit route, and [composition](config.php). It needs no database/container/DTO/general parser or validation framework. The runtime archive includes this manual example; it is separate from contract generation.

## Operation

POST /echo accepts a UTF-8 JSON object with exactly one string message field, including empty text, and returns 200 application/json with that field. Unknown fields, wrong root/field types, empty input, invalid JSON/UTF-8 fail. Builtin json_decode uses depth 32 and last-value duplicate-key semantics.

| Condition | Response |
| --- | --- |
| Invalid JSON/shape | 400 {"error":"Invalid JSON request"} |
| More than 65,536 bytes | 413 {"error":"Request body too large"} |
| No single application/json Content-Type or unsupported encoding | 415 {"error":"Unsupported media type"} |
| I/O failure | Public 500; private details in logger |

Media type comparison ignores case/parameters; JSON stays UTF-8. Content-Encoding must be absent or identity; no decompression occurs. Validation order is type → size → JSON/fields, so large text/plain returns 415. Actual read bytes determine the limit, not Content-Length. Input closes in finally, including early failure; transport closes output.

readLimited reads from the current position without rewind/close. Negative limits fail; zero accepts only an empty remainder. Overflow consumes at most limit+1 logical bytes and returns no partial result. I/O/no progress before EOF throws. Stream wrappers may prefetch. Reading is synchronous; transport owns timeout.

## Run

Use upstream PHP 8.5.12+, PDO, sockets, and installed RR 3.0.1+ in the 3.x line. [Security rules](../../docs/development/security.md) apply.

```bash
rr serve -w "$PWD" -c examples/json-echo/rr.yaml
```

In another terminal:

```bash
curl -i http://127.0.0.1:8082/echo \
  -H 'Content-Type: application/json' \
  --data-binary '{"message":"Hello"}'
```

[rr.yaml](rr.yaml) selects APP_CONFIG and a 1 MiB ingress cap. Larger input receives server 413 before PHP, without its JSON/request ID. Handler limit is separately 64 KiB. Native RR buffers input first; readLimited does not make reception streaming. /health needs no database.

## Verification and cost

```bash
php tools/project.php verify
php tools/project.php http:check --rr=/path/to/rr3
```

Checks cover 65,535/65,536/65,537 bytes, malformed input, 1 MiB and +1 with Content-Length/chunked, IDs, and the next request. See [HTTP guide](../../docs/development/http-and-baseline.md).

String/seekable/non-seekable checks establish the read bound. A 1 MiB file with 64 KiB limit stops at position 65,537; unbounded getContents would read the remainder. This is a resource bound, not a speedup: JSON allocates objects and RR already buffers. Unused reading adds no loop; loading/delivery costs are not zero.
