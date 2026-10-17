# Included third-party code and tools

Type: `operations`. Status: `current`. Reviewed: 2026-10-17.

This directory contains fixed, offline distributions. No dependency resolution or installation runs during application startup, generation, verification or packaging.

- `runtime/`: the application libraries, their original notices and a local autoload map.
- `codegen/sql/` and `codegen/protobuf/`: separate generator library graphs. They run in separate PHP processes because some package versions differ.
- `tools/`: standalone, versioned quality tools with their upstream notices and artifact verification records.
- `manifest.json`: the distribution index. Each distribution manifest records package versions, upstream references, local adaptations and SHA-256 hashes of all included files.

Run `php tools/check-third-party.php` to verify the index and all distribution files. Verification does not download anything. Hashes establish integrity against this checkout's recorded inventory; imported library files were copied from the existing installation and have not been independently authenticated against their upstream Git archives. Tool artifact verification is recorded separately in their provenance metadata.

Original upstream package metadata, including dependency declarations, is retained as source material. It is not used to install or load packages. Updates require reviewing the selected versions, licenses, resource files, autoload maps and adaptations, then updating the inventory and running the complete verification gate.

The runtime adaptation in `runtime/patches/roadrunner-worker-version.patch` replaces a query to an installed-package registry with the pinned RoadRunner compatibility metapackage version. That value describes PHP package compatibility; it does not identify the installed RoadRunner executable.

PHP, its extensions, PostgreSQL and optional server/compiler executables remain platform requirements. Their versions are independent of these PHP library inventories.

Delivery architecture: [autonomous core](../docs/design/autonomous-core.md). Repository documentation: [index](../docs/README.md).
