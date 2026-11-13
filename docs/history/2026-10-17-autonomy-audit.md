# Autonomous delivery migration audit

Type: `history`. Status: `historical`. Snapshot: d60c4cc before documentation reorganization. English edition: 2026-11-13.

This records successive audits and migration stages, including the initial Composer installation and subsequent changes. Sections describe different moments. Commands/plans are historical, not current startup instructions. Counts apply only to the recorded runs. The [delivery contract](../design/autonomous-core.md), [architecture](../architecture/overview.md), and [roadmap](../project/roadmap.md) own present behavior. The original [dependency inventory](dependency-inventory-2026-10-17.json) remains unchanged.

## Goal and initial status

The initial audit was on 2026-10-17. By the recorded later stage, application/generators/checks/builds used included sources/tools, with project Composer manifests/locks removed and no Composer/Task requirement. Comparable performance measurement remained separate.

The goal was a small PHP HTTP/PDO foundation for websites including municipal applications, keeping CMS/editor policy in application code. Fixed third-party sources were allowed; startup/checks/builds needed no Composer/Task/npm/download. Platform PHP 8.5/extensions/PostgreSQL/server executables were separate. A PHP RR package was not a server binary. File/class/package size alone proved no speed improvement.

## Original dependency audit

The unchanged inventory records locally installed versions/refs/licenses/loading/resources/hashes. It establishes local integrity without retrospectively authenticating provenance. Sizes sum regular files including docs/resources, excluding vendor/composer/archives/executables and execution costs.

| Boundary | Direct packages | Full execution graph | Installed size MiB |
| --- | ---: | ---: | ---: |
| Application | 5 | 14 | 12.67 |
| SQL generator | 3 | 7 | 1.02 |
| Protobuf generator | 2 | 3 | 1.13 |

Root had 76 packages: 14 runtime/62 dev. SQL tooling had 69 and protobuf tooling 68. Copying whole vendor trees would duplicate development environments.

### HTTP and database

Nyholm 1.8.2/PSR factory1.1.0/message2.0 comprised three packages, 24 PHP files/105,999 source bytes: small candidates for bundling with notices, with any custom replacement requiring correctness/measurement separately.

spiral/roadrunner-http4.1.0's ten-package graph included HTTP/worker/Goridge/PSR/logging/DTO/protobuf/compatibility; Nyholm was additional. The spiral/roadrunner2025.1.11 metapackage had no files and supplied no executable. Transport JSON/protobuf requirements were independent of application API/KV removal. Large Temporal DTO trees were potential later reduction candidates requiring complete metadata/wire checks, not just class-name searches.

Storage/PDO/SqlExecutor/generated contracts needed no Nette/Phplrt/Typhoon at runtime. Explicit SQL/parameters/transactions/connections/bounded statements/migrations remained core. PostgreSQL was the first checked backend. Sessions/users/api_stats migrations belonged to the application and supplied a path.

SQL codegen used Nette Generator/Utils, Phplrt, Typhoon Type, Brick Math, and two Symfony compatibility packages. Eight bootstrap/function files, multiple namespace roots, and both pp2 grammars were needed; copying src PHP alone would break it.

### Then-retained application subsystems

RR KV had a six-package graph with Goridge/DTO/protobuf/simple-cache. Redis through RR could fall back to local storage without cross-worker cache guarantees. Even FallbackStorage implemented Spiral and was not an independent backend.

IP2Location added bcmath/curl and a separate BIN resource whose redistribution terms did not follow the PHP library license. Composition, not the generic HTTP core, selected automatic sessions/statistics. Application protobuf/endpoints/manifests were a separate integration from RR's transport protobuf.

## Hidden coupling identified

| Area | Boundary needed |
| --- | --- |
| RR provider/entry point | RR services selected by application configuration |
| Container/routing providers | Explicit bindings, generated bindings only in API composition |
| Router/generated manifest provider | Routing separate from directory discovery |
| Hydration/reflection cache | Ordinary DTOs separate from Google Message handling |
| Session/GeoIP | Session lifecycle separate from compulsory GeoIP/cache |
| Cache fallbacks/RR service | Generic contract separate from RR-specific interfaces |
| ProjectPath/endpoint resolver | Application paths separate from core sources |

Existing Pipeline/RequestHandler were sufficient; no new parallel kernel hierarchy was required.

## First completed separation

App implemented RequestHandler and obtained its handler from container configuration, without RR loop ownership. config/container.php retained middleware/sessions/statistics/API. RoadRunnerRuntime owned waitRequest→handle→respond; public/index.php connected it. A minimal composition ran App without RR services; integration used the then-existing API/PostgreSQL.

Actual RR 2025.1.15 exercised health/POST system/404/repeated GET, cookies/headers/four statistics rows in an isolated schema. That run still used Composer and fallback cache, establishing neither autonomy nor RR KV behavior. No performance gain was measured.

## Minimal HTTP and PDO stage

The then-current config/core.php lazily registered SQL/migrations, explicit routes, GET/HEAD health, and no session/statistics/GeoIP/discovery/cache. Health opened no DB. Historical commands were:

```bash
php -S 127.0.0.1:8080 -t public public/http.php
rr serve -c .rr.core.yaml
php bin/migrate.php migrate config/core.php
php bin/migrate.php rollback config/core.php
```

These entry points/config-directory semantics were later removed. At that time servers used the same port sequentially. FPM forwarded original URI to public/http.php and exposed public only; forwarded headers required an explicit trust policy.

SAPI globals/body/uploads became PSR7 requests. Emission preserved status/separate headers, read chunks, and suppressed bodyless methods/statuses. Invalid requests returned private-free400; middleware errors were JSON. core-roadrunner.php added a provider while the default RR entry still chose API/container composition. Absolute APP_CONFIG selected a shared graph. Storage used DB_*/STORAGE_ENGINE and factories; HTTP did no DDL. Migrations used App\Migrations and the selected configuration; an empty directory created only history.

Real development-server/FPM/RR checks covered GET/HEAD/404 with unavailable storage. FPM also covered repeated headers/cookies, Location status, streaming/204, JSON/form/nested multipart, and invalid-host400 on owned ports. Both entries moved to local bootstrap/Autoloader without Composer ClassLoader or quality tools at runtime.

## Fixed distributions

```text
bootstrap.php                    application loading
bootstrap/Autoloader.php          prefixes, classmap, function files
third_party/runtime/             runtime sources and autoload map
third_party/codegen/sql/          isolated SQL graph
third_party/codegen/protobuf/     then-retained protobuf graph
third_party/tools/                standalone quality tools
third_party/manifest.json         distribution index
tools/project.php                local command interface
```

Full selected installed trees/notices/resources were retained. Each manifest recorded versions/refs/local-snapshot provenance/adaptations/hashes. Prior-installed refs did not independently authenticate sources. PHAR provenance recorded official sources/published hashes/signature checks.

Offline integrity checked all files, undeclared files, and symlinks. gitattributes preserved exact third-party/PHAR bytes. Upstream namespaces/dependency metadata stayed without installation/loading behavior. At this snapshot the sole runtime adaptation made Version::current return pinned2025.1.11 metadata without an installed registry; it did not detect binary version.

Quality tools were unmodified PHPStan2.1.42/PHPUnit11.5.55/PHP-CS-Fixer3.94.2 PHARs with verified official SHA-256. PHPStan's OpenPGP signature was also checked; other saved signatures were not claimed cryptographically verified. Details stayed in provenance JSON.

Generators ran isolated versions/processes; dev bootstrap added only PHPUnit types rather than mixing Google versions. Own root LICENSE was absent; both own generators said proprietary. Distribution policy remained open, while upstream licenses including protos/proto were inventoried.

## Verification without Composer

A clean no-vendor copy passed verify:full with only PHP/platform executables in PATH, no Composer/Task/npm, and an OS network profile allowing loopback but blocking external network. Integrity/style/PHPStan/SQL/unit/both-generator/PostgreSQL checks passed. SQL and legacy protobuf SDK/endpoints/OpenAPI generation, repeated app tests, and archive build also ran under those restrictions.

Extracted /health used local bootstrap without ClassLoader/PHPUnit/vendor. Only runtime distribution/inventory shipped. Real FPM 8.5.9/RR 2025.1.15 repeated minimal GET/HEAD/404; RR/PostgreSQL also checked legacy API/POST/session/statistics with fallback rather than real KV.

SQL/endpoint/registry output was byte-identical. protoc26.1 aggregate metadata varied independent-import order even on repeated identical commands, while six individual descriptors matched byte-for-byte. Regenerated SDK passed app tests. This was not a performance result.

## Recorded transition sequence

1. Establish compatibility and comparable small-response/HTML/SELECT/list/write measurements, including quantiles/CPU/all-process RSS/SQL/connections/errors and sustained operation.
2. Separate RequestHandler/Pipeline construction from RR, preserving a behavioral control and another entry for comparison.
3. Extract explicit HTTP/PDO composition without mandatory discovery/GeoIP/sessions/statistics.
4. Bundle selected HTTP/autoload/manifests, then isolated tooling; replace compulsory Task/Composer commands while retaining checks.
5. Package/check RR separately and evaluate DTO/worker adaptations against platform requirements.
6. Check offline no-manager startup/generation/analysis/tests/build/PostgreSQL, then compare costs and decide runtime scope.

Initial imports retained whole trees. Later trimming/custom HTTP needed a working autonomous baseline and verified benefit. These historical stages do not authorize restoring removed subsystems.
