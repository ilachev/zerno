# SQL PHP Generator

`sql-gen` generates typed PHP query and row classes from `sql/schema.sql` and named queries in `sql/queries/*.sql`.

Run these commands from the project root:

```bash
php tools/project.php sql:gen
php tools/project.php sql:check
php tools/project.php tools:sql:check
```

`sql:check:pg` additionally validates queries against PostgreSQL in an isolated schema. Connection settings come from `TEST_DB_*`.

The generator loads `bootstrap.php` and the bundled dependency configuration in `third_party/codegen/sql/autoload.php`. No package installation is required. The distribution manifest records the pinned package versions, source refs, licenses and file hashes; complete upstream package files and notices are kept under `third_party/codegen/sql/packages`.

The SQL and protobuf generators run in separate PHP processes because their dependency versions are pinned independently. Do not load both generator bootstraps in one process.

Generated query and row classes use the application's SQL runtime contracts. Nette, Phplrt and Typhoon remain dependencies of this development tool and are not required to execute the generated queries.
