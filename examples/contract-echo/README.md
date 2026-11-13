# Echo from an API contract

Type: `tool-guide`. Status: `current`. Reviewed against code: 2026-10-23. English edition: 2026-11-13.

[Contract](../../contracts/echo.proto) → [generated PHP](../../gen/Generated/Echo) → [manual endpoint](EchoEndpoint.php) → [composition](config.php). Ordinary App handles requests without runtime descriptors/generation or a database. The [generation contract](../../docs/design/protobuf-contract-generation.md) defines subset, JSON, and costs.

```bash
php tools/project.php proto:gen
php tools/project.php verify
rr serve -w "$PWD" -c examples/contract-echo/rr.yaml
```

In another terminal:

```bash
curl -i http://127.0.0.1:8082/api/echo -H 'Content-Type: application/json' --data '{"message":"Hello"}'
curl -i http://127.0.0.1:8082/health
```

POST /api/echo returns JSON 200; /health supports GET/HEAD. Missing/null/empty message returns {}. Unknown fields/bad JSON return 400; input over 65,536 bytes returns 413; wrong media type returns 415. Endpoint failures use generic 500.

RR3 is the sole runtime. Its config sets APP_CONFIG and a 1 MiB ingress cap. Adapter 64 KiB bounds later reading/decoding. HTTP checks create their own RR fixture profile.

Checkout generation/full compilation requires installed protoc (26.1 checked). proto:check/verify use saved descriptors/hashes without a compiler. Runtime archives exclude contracts/tools: skip generation/verify there and run ready PHP with APP_CONFIG.

Local representation comparison: `php tools/proto-gen/measure.php`, with protoc or PROTOC set. HTTP comparison: `php tools/project.php http:check --measure-contract --rr=/path/to/rr3`. These are short comparisons, not load tests.
