# Included third-party code and tools

Type: `operations`. Status: `current`. Reviewed: 2026-10-20.

This directory contains three fixed offline distributions. Application startup, generation, verification and packaging do not resolve, install or download packages.

- `runtime/`: application and transport libraries, upstream notices and the local autoload map.
- `codegen/sql/`: SQL generator libraries, loaded only in its isolated PHP process.
- `tools/`: standalone versioned quality tools, upstream notices and artifact verification records.
- [manifest.json](manifest.json): the index; distribution manifests record package versions, references, adaptations and SHA-256 hashes of all included files.

HTTP messages and logging contracts belong to the core. Nyholm and PSR HTTP/factory/log packages and their autoload prefixes are removed. RoadRunner uses the native `HttpWorker` API; retained upstream PSR7 adapter/logger sources and dependency metadata are provenance and are never loaded. The pinned RoadRunner HTTP transport requires `roadrunner/api-dto` and `google/protobuf`. Their complete upstream sources remain in runtime; the application protobuf API, contracts, SDK and generator have been removed. GeoIP, PSR simple-cache and RoadRunner KV packages are removed entirely. The worker logger-removal [patch](runtime/patches/roadrunner-worker-remove-logger.patch) removes unused eager logger wiring without changing frame handling. The runtime adaptation in [roadrunner-worker-version.patch](runtime/patches/roadrunner-worker-version.patch) replaces an installed-package registry query with the pinned PHP compatibility metapackage version; this is not the executable version.

Run `php tools/project.php third-party:check` to verify all included files without network access. Hashes establish integrity against this checkout's inventory. RoadRunner transport packages were updated from pinned upstream GitHub tag/commit archives on 2026-10-20, with archive SHA-256 recorded. Retained protobuf/polyfill files came from the prior installation. No independent publisher-signature verification is claimed; tool artifact verification is recorded separately in provenance metadata.

Original upstream metadata, including dependency declarations, is retained as source material. It does not install or load packages. Updates require review of versions, licenses, resource files, autoload maps and adaptations, refreshed inventories and the full verification gate. Do not edit or selectively trim upstream classes incidentally.

The fixed transport targets RoadRunner 3.x (tested 3.0.0): roadrunner/worker 3.8.0, roadrunner/http 4.2.1, roadrunner/goridge 4.6.0 and roadrunner/api-dto 2.1.0. The server executable and sockets extension are separate platform inputs; commands never download them.

PHP, extensions, PostgreSQL and HTTP/development executables are separate platform requirements. Runtime archives include only the runtime distribution. See [delivery architecture](../docs/design/autonomous-core.md), [testing](../docs/development/infrastructure-and-testing.md) and the [documentation index](../docs/README.md).
