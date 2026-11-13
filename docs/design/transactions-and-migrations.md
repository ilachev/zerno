# Transactions and migrations

Type: `design`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

This owns Storage.transaction and Migration behavior. See [architecture](../architecture/overview.md) for composition and [verification](../development/infrastructure-and-testing.md) for TEST_DB_*. PostgreSQL is the only application backend.

## One operation, one transaction

```php
$result = $storage->transaction(function () use ($storage): int {
    $storage->execute('INSERT INTO records (id, value) VALUES (:id, :value)', [
        'id' => 42,
        'value' => 'example',
    ]);

    return 42;
});
```

transaction lazily obtains PDO, begins, calls the callback once, commits, and then returns its typed result. The operation owns BEGIN/COMMIT/ROLLBACK; callbacks must not issue manual transaction-control SQL. No container, retry policy, or manager is required.

Nested calls throw LogicException before invoking the inner callback; no savepoints exist. If that error is caught inside an otherwise healthy outer callback, the outer transaction can continue. Manual-savepoint recovery is outside the contract.

PDO failures in query/queryOne/execute/lastInsertId are retained during the callback. Even if caught inside it, the first StorageException is rethrown and the transaction rolled back. This prevents false PostgreSQL success when commit on an aborted transaction acts as rollback. Outside a transaction, storage forwards failures without retaining them. lastInsertId preserves the original PDOException as other operations do.

Ordinary callback exceptions retain identity. Begin/commit errors retain their PDOException/SQLSTATE. After failed commit, [inTransaction](https://www.php.net/manual/en/pdo.intransaction.php) is checked before rollback so secondary cleanup cannot erase the cause. [commit](https://www.php.net/manual/en/pdo.commit.php) does not replace outcome verification.

If rollback also fails, TransactionRollbackException contains the original in getPrevious() and cleanup failure in readonly rollbackFailure. Discard that Storage and construct a new one; reconnect/retry is not automatic. Connection loss during commit may leave an uncertain write requiring inspection before retry. Finally clears internal flags/error references; successful rollback permits reuse.

## Explicit SQL lists

```php
final readonly class Migration20300101001_Records extends AbstractMigration
{
    public function up(): array
    {
        return [
            'CREATE TABLE records (id BIGSERIAL PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE INDEX records_value_idx ON records (value)',
        ];
    }

    public function down(): array
    {
        return ['DROP TABLE records'];
    }
}
```

AbstractMigration is App\Platform\Storage\Migration\AbstractMigration, as in the [catalog migration](../../examples/document-catalog/migrations/Migration20261017001_CreateDocuments.php). Its version is the unqualified class name; custom implementations can choose a version.

up/down return list<string>, one full SQL statement per element. Empty lists change only history. Semicolons inside literals/comments/dollar quotes stay within a statement. MigrationService does not split SQL; separate commands need separate elements. These are trusted CLI-loaded files, not HTTP input.

This breaks the former string-returning API. Convert custom migrations to arrays; no fallback/parser/second execution mode exists. Previously applied versions are not rerun solely by an engine upgrade. Catalog DDL/version stayed the same.

## Per-version atomicity and recovery

Versions must be unique before any up/down call; duplicates throw LogicException. Migrate sorts lexicographically and skips registered versions. Rollback reverses registered versions.

Each version's statements and history INSERT/DELETE share one transaction. Failure stops the pass; earlier committed versions remain. Failed down restores that version's changes/history. Deferred commit constraints are also tested. The full series is not one transaction.

After fixing a migration, rerun explicitly:

```bash
php bin/migrate.php migrate examples/document-catalog/migrations
php bin/migrate.php rollback examples/document-catalog/migrations
```

There is no automatic retry. MigrationRepository initializes history separately, so the empty table may remain after a first failed migration. HTTP/services:start perform no DDL. Partial leftovers from the old engine require manual schema/history inspection; the new engine does not delete them.

All migrations are transactional. CREATE INDEX CONCURRENTLY and other forbidden transactional commands fail and roll back. No non-transactional switch exists. Operators run CLI migrations sequentially; no multi-process serialization/distributed lock is provided.

## BIGINT timestamps and explicit metadata upgrade

New migrations tables use executed_at BIGINT, including PHP_INT_MAX. CREATE TABLE IF NOT EXISTS does not alter an existing INTEGER column. Use the selected application's DB_* and DB_SCHEMA:

```bash
php bin/migrate.php upgrade-metadata
```

The checkout equivalent is `php tools/project.php migrate upgrade-metadata`. A directory argument is rejected before connection. The command loads neither application migrations nor HTTP config; it builds Storage/repository and transactionally runs ALTER TABLE migrations ALTER COLUMN executed_at TYPE BIGINT. Rows, timestamps, primary key, and versions remain. Repeating is allowed; no downcast/history deletion occurs.

Run sequentially with other migration commands. ALTER takes a metadata lock and may rewrite the table; duration depends on size/concurrency. Start a fresh CLI/PDO afterward because cached statements may retain INTEGER parameter types. HTTP never accesses migration history. Impossible conversion rolls back and exits with an error, preserving the source table. This is engine metadata maintenance, not an application migration version.

MigrationCliRecoveryTest checks BIGINT creation/PHP_INT_MAX, preserved INTEGER history, no implicit ALTER, repeatability, and failed conversion on PostgreSQL.

## Cost and verification

Developers use an ordinary callback or SQL list. Applications own policy, queries, and manual retry. Error tracking holds one reference for an active callback and adds no SQL probes. Each migration version adds BEGIN/COMMIT; catalog up has two DDL statements and one history write plus those boundaries. This describes calls rather than full prepare/protocol latency; no speedup is claimed.

Migration execution is outside HTTP. No new libraries, downloads, generators, or manifest work are introduced. Unused operations run no SQL; class-loading cost is not claimed zero.

```bash
php tools/project.php verify
php tools/project.php verify:integration
php tools/project.php verify:full
```

[Transaction tests](../../tests/Integration/Storage/TransactionRecoveryTest.php) cover caught SQL errors, nested calls, deferred commit, lost connection/rollback, causes, and the next callback. [Migration tests](../../tests/Integration/Storage/MigrationRecoveryTest.php) cover DDL/data/history, corrections/retry, literal/DO statements, duplicates, and forbidden DDL. [CLI tests](../../tests/Integration/Storage/MigrationCliRecoveryTest.php) edit actual files between processes. [Unit tests](../../tests/Unit/Platform/Storage/Migration/MigrationServiceTest.php) use real SQLite without replacing PostgreSQL checks.

[2026-10-23 data](../history/2026-10-23-transaction-recovery.json) preserves original failures and corrected probes. Old string signatures there are historical. That stage, before RR-only scope, passed 265 unit/167 SQL-tool/58 PostgreSQL tests, 68 packaged SAPI/RR3 HTTP cases with migrations, and five source baseline cases. These were correctness checks, not endurance tests.
