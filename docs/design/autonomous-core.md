# Autonomous delivery

Type: `architecture`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

Runtime, generation, checks, and builds install/download no dependencies. Only separate [security checks](../development/security.md) retrieve advisory data. PHP/extensions, PostgreSQL, RR 3.x, and measurement tools are external platform requirements. [Scope](../project/vision.md) excludes speculative legacy subsystems.

## Distributions

| Boundary | Sources | Use |
| --- | --- | --- |
| Runtime | third_party/runtime | Application bootstrap |
| SQL generation | third_party/codegen/sql | Separate sql-gen/sql-check processes |
| API source contracts | third_party/codegen/proto | Echo compilation; bundled annotations/http/descriptor.proto |
| Quality/security | third_party/tools | PHPStan, PHPUnit, PHP-CS-Fixer PHAR launchers, govulncheck binaries |

The [root manifest](../../third_party/manifest.json) hashes distribution manifests; each lists files/SHA-256. The [checker](../../tools/check-third-party.php) rejects unlisted files/symlinks offline. Runtime loads no generator/quality libraries.

Pinned transport: roadrunner/worker 3.8.0, http 4.2.1, goridge 4.6.0, api-dto 2.1.0. Spiral/RoadRunner/GPBMetadata namespaces remain upstream. The spiral/roadrunner compatibility metapackage is v3.0.0; upstream metadata retains its former name while newer packages reference roadrunner/roadrunner. These declarations record provenance, not package resolution. CI provisions RR 3.0.1 from a fixed release URL/hash before checks; project commands do not download it. sockets is separate.

Trees retain version/ref/license/notices. Retained protobuf/polyfill provenance identifies the earlier installed snapshot. Transport archives came from upstream tags/commits over HTTPS with recorded archive hashes. Local hashes establish integrity, not independent publisher-signature authentication. Upstream composer metadata remains provenance rather than installer input.

## Runtime consumers and adaptations

Core owns HTTP types and Logger. Nyholm/psr7, PSR HTTP/factory/log, GeoIP, simple-cache, and RR KV were removed with prefixes/trees. Native RR retains Goridge, worker/HTTP, polyfill, DTO, and Google protobuf. Echo uses plain readonly DTOs; the generator alone reads descriptors using bundled Google runtime. Contract sources live in codegen/proto.

Retained upstream trees include unused PSR7Worker/Logger code and metadata. Those classes are not loaded; absent PSR namespaces are not registered. They establish no compatibility API. Quality PHAR contents are isolated from application runtime.

Two deliberate RR worker adaptations are recorded: Version.php reads the pinned metapackage without an installed registry; Worker.php removes eager PSR logger property/constructor/factory/getter work. Frame/protocol handling is unchanged. Patches, original hashes, and final hashes live in the [runtime manifest](../../third_party/runtime/manifest.json). Reassess both on upstream updates; do not edit bundled files incidentally.

Autoload registration still costs work. See [RR3 history](../history/2026-10-20-roadrunner3.md) and [native HTTP history](../history/2026-10-20-native-http.md) for bounded historical checks, not current universal performance claims.

## Generation and packaging

SQL inputs are sql/schema.sql and sql/queries/catalog.sql. The runner passes the runtime namespace directly; tools/sql-gen owns generation. Nette/Phplrt/Typhoon stay in separate codegen processes. Never edit generated PHP manually.

contracts/echo.proto and its saved descriptor form the API generation boundary. proto:gen/proto:compile:check need platform protoc (26.1 was checked); proto:check/verify compare source/descriptor/PHP without it. No SDK or OpenAPI sidecar ships.

```bash
php tools/project.php third-party:check
php tools/project.php verify:full
php tools/project.php build local
```

Build creates dist/zerno-VERSION.tar.gz with bootstrap, src/gen/config/public/bin, runtime distribution, documentation/README, RR configuration, and examples with migrations/seed/configuration. Ready SQL/API PHP is included. Generators, input contracts/descriptors/SQL, codegen sources, quality/security tools, tests, and load scripts are excluded. Release manifest references runtime only.

Run `rr serve -c .rr.yaml` from the extracted root. Documentation links to tools/tests/contracts/sql refer to the checkout. RuntimeArchiveTest builds/extracts and checks exclusions, packaged /health composition, and CLI entry points; actual RR smoke runs separately on a supported platform.

Packaged migrations run with `php bin/migrate.php migrate [directory]`; default is src/Migrations, catalog uses examples/document-catalog/migrations. CLI requires directory files directly and builds no HTTP graph. It creates no database; HTTP starts with no DDL.

CI/release use verify:full and build, plus an independent online security gate required before packaging/upload. Checks install no PHP packages. HTTP/load checks are explicit. Removing old API migrations does not drop tables in existing user databases.
