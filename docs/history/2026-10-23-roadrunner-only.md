# RoadRunner as the sole HTTP runtime

Type: `history`. Status: `historical`. Verified: 2026-10-23. English edition: 2026-11-13.

At Git 5fe818f the project had public/http.php for SAPI and public/index.php for native RR, so checks included FPM. The user clarified that only RR execution was required. This replaced earlier scope and did not claim a benchmark winner.

Removed SAPI entry/factory/emitter/tests, FPM/FastCGI/Caddy checker branches, and JSON/baseline configs. UploadedFile dropped sapiUpload and uses rename on native RR temporary files. Tests cover moving/closing/preservation on failure. Applications using the sixth argument must remove it.

App/RequestHandler remain loop-independent for composition/tests. CLI migration/seed/codegen remain ordinary processes. Libraries stayed unchanged; only manifest platform requirements changed. http:check accepts --rr/--measure-contract and rejects removed flags without aliases.

Docs/examples/onboarding/inventory were reconciled. Old SAPI reports describe historical support, whose sources remain at 5fe818f. Acceptance required offline verify, actual JSON/HTML RR checks, state/files/uploads/input cap, and a packaged archive without SAPI. HTTP 205 and HTTP2/3 were unresolved at this snapshot, not implied solved by runtime selection.

## Verification

- verify passed 257 tests/1,122 assertions, level-10 analysis/style/hashes/docs/SQL/API sync without services.
- Actual RR 3.0.0/PHP 8.5.9 passed 140 cases per renderer; contract measurements were diagnostic, without speed claims.
- build rr-only produced runtime-only distribution/platform requirements and excluded SAPI/FPM sources/configs.
- Extracted archive passed 74 assertions for core/manual/generated/catalog health, errors/limits/HEAD/405/Allow/IDs/next request. Catalog returned HTML 500 with unavailable database and kept /health working. Port 1 prevented real DB access; tools/contracts were absent.
- http:check --runtime=fpm failed as an unknown option rather than silently selecting RR.

Database/SQL/generation behavior did not change. PostgreSQL integration and long load tests were not run at this stage; unavailable-DB checks do not replace them. Earlier full-gate results remain separate evidence.
