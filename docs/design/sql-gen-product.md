# Контракт и развитие `sql-gen`

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-17.

## Роль в ProtoPHPStack

`sql-gen` создаёт typed PHP queries и rows вокруг явного SQL. Он дополняет PDO storage ядра и не зависит от protobuf-профиля. Приложение может использовать PDO без запуска генератора во время обработки запросов.

Источники для генерации:

- [sql/schema.sql](../../sql/schema.sql) — типы колонок и ограничения для анализа;
- [sql/queries](../../sql/queries) — именованные SQL-запросы;
- [codegen/Sql/BaseApiTemplateSqlGenerationProfile.php](../../codegen/Sql/BaseApiTemplateSqlGenerationProfile.php) — внешний project profile.

`sql/schema.sql` не заменяет runtime migrations. При изменении persistence нужно отдельно согласовать schema source, миграции и фактическую PostgreSQL schema.

## Текущий input/output contract

Именованный запрос объявляется заголовком:

```sql
-- name: RefreshSession :one
UPDATE sessions
SET expires_at = :expires_at,
    updated_at = :updated_at
WHERE id = :id AND expires_at >= :now
RETURNING *;
```

Реализованы result kinds `:one`, `:many`, `:exec` и именованные placeholders `:param`.

[PhpQueryGenerator](../../tools/sql-gen/src/Generator/PhpQueryGenerator.php) создаёт:

- readonly `*Query` с типизированными параметрами конструктора/`create()`, методами `sql()` и `params()`;
- `*Row` для разрешённых result projections и `rowClass()` на query;
- общий row class для запросов одного SQL-файла с совпадающей структурой результата, когда это определено текущим naming algorithm.

Отдельные `*Params` и `*Queries` facade сейчас не генерируются. Это были варианты дизайна, а не действующий API.

Примеры результата: `gen/Generated/Sql/Session/RefreshSessionQuery.php`, `SessionRow.php` и `gen/Generated/Sql/ApiStats/InsertApiStatRow.php`. Generated-файлы не редактируют вручную.

## Реализованные parser и type model

Query и schema parsers построены на `phplrt` и внутреннем AST. Границы синтаксиса определены в [sql_subset.pp2](../../tools/sql-gen/src/Parser/grammar/sql_subset.pp2) и [schema_subset.pp2](../../tools/sql-gen/src/Schema/grammar/schema_subset.pp2), а семантика дополнительно ограничена resolvers.

| Конструкция | Действующая граница |
| --- | --- |
| `SELECT` | Колонки, aliases, `*`/`table.*`, `FROM`, joins, `WHERE`, `ORDER BY` |
| Условия | Сравнения колонок/placeholders через `=`, `<`, `>`, `<=`, `>=`; последовательности `AND`/`OR` |
| `INSERT` | Явные колонки и placeholders в `VALUES`; `ON CONFLICT (...) DO UPDATE SET column = EXCLUDED.column`; `RETURNING` |
| `UPDATE` | `SET column = :param`, необязательные `WHERE` и `RETURNING`; арифметические assignment expressions не поддерживаются |
| `DELETE` | `DELETE FROM table WHERE ...` |
| Projection expressions | Ограниченные `COUNT`, `MIN`, `MAX`, `COALESCE`, `CASE WHEN ... ELSE ... END`; совместимость типов проверяется resolver-ом |
| Schema | Ограниченные `CREATE TABLE`, column nullability, primary/unique/foreign keys |

Это не parser произвольного PostgreSQL. Например, CTE, subqueries, `GROUP BY`, `LIMIT`, произвольные literals и выражения не входят в текущую grammar. Само успешное чтение конструкции ещё не доказывает корректность type inference.

[PhpTypeFactory](../../tools/sql-gen/src/Type/PhpTypeFactory.php) сейчас отображает SQL-типы так:

| SQL | PHP |
| --- | --- |
| `TEXT`, `JSONB` | `string` |
| `INTEGER`, `BIGINT`, `BIGSERIAL`, `SERIAL` | `int` |
| `REAL`, `DOUBLE`, `NUMERIC`, `DECIMAL` | `float` |
| `BOOLEAN`, `BOOL` | `bool` |

`JSONB` остаётся строкой до ручного преобразования в repository/mapper. Текущее отображение `NUMERIC`/`DECIMAL` в float не даёт точной decimal arithmetic. SQL-типы с дополнительной PostgreSQL-семантикой нельзя считать поддержанными по этой таблице.

Canonical type model внутри generator — `Typhoon\Type`. Эта библиотека, Nette и Phplrt нужны generator-у; generated query execution их не загружает.

## Runtime и реальные потребители

[SqlExecutor](../../src/Platform/Storage/Sql/SqlExecutor.php) исполняет query через `Storage`, а typed rows создаются через `DatabaseRow::fromDatabaseRow`. `:one` выбирает первый результат либо возвращает null; сам по себе этот marker не доказывает уникальность строки в БД. Транзакции предоставляет `Storage::transaction`.

Runtime contract FQCN и naming вынесены в `SqlGenerationProfile`. CLI поддерживает `--runtime-namespace` для default profile либо `--bootstrap` + `--profile-class` для внешнего profile; явно выбранный profile определяет contracts сам.

Session и ApiStats уже используют generated SQL через ручные repositories. Для Session реализованы поиск, upsert, удаление и `RefreshSession` через `UPDATE ... RETURNING`. Это действующие потребители, а не первый запланированный MVP.

## Действующие проверки

Из корня:

```bash
php tools/project.php sql:gen
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php tools:sql:check
```

- `sql:check` сравнивает ожидаемые PHP-артефакты с каталогом output: отсутствующие, устаревшие и лишние PHP-файлы дают ошибку.
- `sql:check:pg` через runner использует `TEST_DB_*`, создаёт случайную схему `sql_gen_check_...`, выполняет trusted schema SQL и проверяет скомпилированные запросы через `PREPARE`. После проверки удаляет свою схему.
- Прямой `tools/sql-gen/bin/sql-check-pg.php` принимает `--db-*` и `DB_*`; это другая граница конфигурации, чем root runner.
- `tools:sql:check` запускает style, PHPStan и tests компонента. `verify` включает автономный `sql:check`; PostgreSQL входит в `verify:integration`/`verify:full`.

`PREPARE` проверяет SQL и разрешение типов в sandbox schema. Он не исполняет business mutations, не проверяет данные, результат row mapping, планы запросов или производительность. Эти свойства требуют интеграционных сценариев.

## Известные ограничения

Grammar принимает `LEFT`, `RIGHT`, `FULL JOIN`, но текущие `StatementTableMapResolver` и `StatementVisibleTable` не добавляют nullable-тип из-за отсутствующей строки на внешней стороне join. Такой query может получить неверный non-null row field. До исправления inference outer joins нельзя считать безопасным поддерживаемым путём.

Join relation checks используют schema metadata, но не доказывают корректность произвольных составных условий. Проверки ProjectSqlQueriesCompatibilityTest и UpdateQueriesCompatibilityTest подтверждают конкретные поддержанные сценарии; это не покрытие всего PostgreSQL.

## Принципы и предложения по развитию

SQL остаётся читаемым источником запросов; generator не должен скрывать его за ORM, Active Record или query builder. Runtime не должен заново интерпретировать SQL или загружать generator libraries.

Приоритет работ определяет [общий roadmap](../project/roadmap.md). Возможные component tasks: исправление nullability outer joins, более точное expression typing, составные relation checks и source-aware diagnostics. Расширение subset требует согласованных parser, semantic inference, generation и PostgreSQL checks. `*Params` и facade имеют смысл только при конкретном потребителе; обязательного следующего этапа для них нет.

Команды, CLI options и bundled distribution описаны в [README sql-gen](../../tools/sql-gen/README.md).
