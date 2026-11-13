# Reducing scope and making composition explicit

Type: `history`. Status: `historical`. Reviewed: 2026-11-13.

Zerno reduced a broad application skeleton to a small HTTP/PostgreSQL core and replaced container-based service resolution with ordinary constructors and composition functions. This case examines how removing machinery changed ownership and visibility.

## Problem and decision

The earlier tree included sessions, tracking, analytics, caches, hydration/mapping, an outgoing HTTP client, and a legacy API stack. Keeping them without current consumers increased maintenance scope. The [scope report](../history/2026-10-20-core-scope.md) records removals and the previous Git state.

The composition change removed the container, autowiring, service providers, and class-name resolution. Routes receive constructed handlers. [config/core.php](../../config/core.php) supplies a `Closure(Logger): App`; the catalog has its own [application function](../../examples/document-catalog/application.php). The [composition report](../history/2026-10-20-explicit-composition.md) records breaking contracts and measurements.

The composition report records the owner's explicit request to remove the container. The scope report also records a correction: the agent removed contract generation too broadly, and the owner clarified that it remained a desired direction. Other earlier choices are described as project decisions without assigning unsupported individual authorship.

## Execution and tradeoffs

The graph is visible in PHP. A developer can follow construction into routing and inspect when SQL begins. [PostgreSQLStorage](../../src/Platform/Storage/PostgreSQLStorage.php) opens PDO on its first SQL operation, transaction, or `lastInsertId`. `/health`, 404, and 405 do not need a connection.

Explicit composition requires maintaining construction functions as dependencies change. Bundled dependencies retain an update and provenance cost. Removing the resolver makes these responsibilities visible; it does not eliminate them or establish zero startup overhead.

The legacy protobuf stack was removed. A later, separately scoped [Echo slice](../design/protobuf-contract-generation.md) generates plain DTOs, an endpoint, an adapter, and routes. It is a bounded addition with a working consumer.

## Verification and limits

Historical reports link raw measurements and source fingerprints. Their CLI comparisons apply to measured probes and do not establish an HTTP throughput advantage. HTTP and PostgreSQL checks exercise the resulting boundaries.

The review technique is to ask the agent to show construction and I/O, remove unsupported capabilities, and measure the path being discussed. Independent adoption and a net reduction in maintenance time remain unvalidated.
