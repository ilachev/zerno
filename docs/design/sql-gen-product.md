# Контракт и развитие `sql-gen`

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-18.

## Роль в «Зерне»

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
| `SELECT` | Колонки, aliases, `*`/`table.*`, `FROM`, joins, `WHERE`, `ORDER BY`, завершающий `LIMIT` |
| Условия | Сравнения колонок/placeholders через `=`, `<`, `>`, `<=`, `>=`; последовательности `AND`/`OR` |
| `INSERT` | Явные колонки и placeholders в `VALUES`; `ON CONFLICT (...) DO UPDATE SET column = EXCLUDED.column`; `RETURNING` |
| `UPDATE` | `SET column = :param`, необязательные `WHERE` и `RETURNING`; арифметические assignment expressions не поддерживаются |
| `DELETE` | `DELETE FROM table WHERE ...` |
| Projection expressions | Ограниченные `COUNT`, `MIN`, `MAX`, `COALESCE`, `CASE WHEN ... ELSE ... END`; совместимость типов проверяется resolver-ом |
| Schema | Ограниченные `CREATE TABLE`, column nullability, primary/unique/foreign keys |

Это не parser произвольного PostgreSQL. Например, CTE, subqueries, `GROUP BY`, `OFFSET`, произвольные literals и выражения не входят в текущую grammar. Само успешное чтение конструкции ещё не доказывает корректность type inference.

[PhpTypeFactory](../../tools/sql-gen/src/Type/PhpTypeFactory.php) сейчас отображает SQL-типы так:

| SQL | PHP |
| --- | --- |
| `TEXT`, `JSONB` | `string` |
| `INTEGER`, `BIGINT`, `BIGSERIAL`, `SERIAL` | `int` |
| `REAL`, `DOUBLE`, `NUMERIC`, `DECIMAL` | `float` |
| `BOOLEAN`, `BOOL` | `bool` |

`JSONB` остаётся строкой до ручного преобразования в repository/mapper. Текущее отображение `NUMERIC`/`DECIMAL` в float не даёт точной decimal arithmetic. SQL-типы с дополнительной PostgreSQL-семантикой нельзя считать поддержанными по этой таблице.

Canonical type model внутри generator — `Typhoon\Type`. Эта библиотека, Nette и Phplrt нужны generator-у; generated query execution их не загружает.

## Ограниченная SELECT-выборка

```sql
-- name: PageBefore :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT 21;
```

Для собственной schema с `catalog_documents` разработчик добавляет обычный named SQL и запускает генератор с этой schema. Дополнительные runtime services, profile options и pagination API не нужны. Приложение передаёт `status: 'published'`, положительный cursor `before`, показывает первые 20 строк и использует 21-ю как признак продолжения. Устойчивый порядок задаётся явным `ORDER BY id DESC` по уникальному ID; generator не добавляет сортировку и не доказывает её уникальность.

Поддержаны только `SELECT ... [WHERE ...] [ORDER BY ...] [LIMIT N | LIMIT :name] [;]` в пределах остального subset:

- `N` — десятичные цифры со значением от `0` до `PHP_INT_MAX`, включая ведущие нули; переполнение отвергается до integer cast с сообщением `LIMIT literal must be between 0 and PHP_INT_MAX.`.
- `:name`, включая `:limit`, получает non-null PHP `int` и PostgreSQL `BIGINT` без привязки к колонке schema. Если это имя также сравнивается с integer-колонками, параметры объединяются; даже nullable integer-колонка не делает LIMIT nullable. Сравнение того же параметра с `TEXT`/`JSONB`, bool или float отвергается resolver-ом с сообщением `LIMIT parameter ... must be compatible with an integer column ...`.
- Повторное использование LIMIT-параметра внутри projection expressions (`MIN`, `COALESCE`, `CASE` и остальных) отвергается с сообщением `LIMIT parameter ... cannot be reused in projection expressions ...`. Native PostgreSQL prepare может вывести тип аргумента projection до обработки LIMIT; checker с явным `PREPARE(bigint)` не воспроизводит этот вывод. Исправление общей модели expression typing и runtime casts в этот контракт не входят.
- Значение параметра `0` возвращает пустой список; отрицательный `int` отклоняет PostgreSQL при исполнении. Генератор не добавляет runtime validation: допустимый положительный максимум определяет вызывающее приложение. `null` не входит в generated signature, поскольку PostgreSQL трактует `LIMIT NULL` как отсутствие ограничения.
- `ORDER BY`, `WHERE` и сам `LIMIT` необязательны. Маркеры `:one` и `:many` выбирают runtime contract, не меняя SQL: `:one` без явного `LIMIT` по-прежнему может прочитать весь результат через `fetchAll()`.

Syntax errors от parser-а получают формы `LIMIT ALL`, `LIMIT NULL`, signed/decimal/exponent/quoted values, column references, expressions, casts, parentheses, повторный или неверно расположенный LIMIT, `OFFSET`/comma pagination и LIMIT у INSERT/UPDATE/DELETE. Числовой literal добавлен только в LIMIT; WHERE literals этим расширением не поддержаны.

Прямой SQL [каталога](../../examples/document-catalog/src/CatalogRepository.php) сохранён: его schema отсутствует в основном API profile, а `status = 'published'` требует string literal вне текущей grammar. [Fixture](../../tests/Fixtures/SqlLimit/queries/catalog.sql) проверяет те же поля, published-фильтр, cursor и порядок с `status = :status`; расширять schema/profile API и expression parser ради переноса примера не требуется.

LIMIT ограничивает число **возвращённых** БД строк, не число просмотренных кандидатов или bytes одной строки. Фильтры, размер полей и индексы остаются ответственностью приложения. Индекс `(status, id DESC)` даёт проверочному запросу упорядоченный доступ; без подходящего плана даже `LIMIT 21` может потребовать сортировку/чтение большого объёма.

Цена расширения: codegen читает один optional AST field, проверяет диапазон литерала/тип параметра и сохраняет LIMIT в PostgreSQL checker. Generated PHP сохраняет исходный SQL; дополнительных библиотек, разборов SQL или checks при запросе нет. Уже существующие queries без LIMIT генерируются без изменений. Включённый LIMIT уменьшает возвращаемый результат только при наличии большего числа совпадений; ускорение само по себе этим не доказано.

### Проверка границ и стоимости

[SqlLimitTest](../../tests/Integration/Storage/SqlLimitTest.php) запускает генератор отдельным процессом, загружает полученный PHP только в runtime-контур и выполняет его через настоящие PostgreSQLStorage/SqlExecutor. Проверяются пустой набор, 1/20/21/22 строки, последовательные страницы, published-фильтр, typed rows, числовые параметры `0`/`1`/`PHP_INT_MAX`, отказ отрицательного LIMIT и отсутствие автоматического ограничения у `:one`. Все схемы случайные, временные generated files удаляются.

После настройки отдельной PostgreSQL test database через `TEST_DB_*`:

```bash
php tools/project.php tools:sql:check
php tools/project.php verify
php tools/project.php verify:integration
php tools/project.php sql:check:pg
php tools/sql-gen/bin/sql-check-pg.php --input-dir=tests/Fixtures/SqlLimit/queries --schema=tests/Fixtures/SqlLimit/schema.sql --db-host="$TEST_DB_HOST" --db-port="$TEST_DB_PORT" --db-name="$TEST_DB_NAME" --db-user="$TEST_DB_USER" --db-password="$TEST_DB_PASSWORD"
php tests/Fixtures/SqlLimit/measure.php --rows=50000
```

Последняя команда создаёт собственную случайную схему, 50 000 записей с описанием 1032 bytes и 10 000 published, индекс `(status, id DESC)`, выполняет `ANALYZE`. Разные PHP runtime-процессы читают одинаковую projection с LIMIT 21 и без LIMIT; JSON-отчёт содержит rows, размер сериализованного результата, PHP memory counters, codegen wall time и `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`. В конце схема и generated files удаляются. Прямой checker получает только явно переданные test connection settings; его fallback `DB_*` не используется.

Локальный снимок 2026-10-18: PHP/pdo_pgsql 8.5.9, CLI OPcache 0; PostgreSQL 16.15 aarch64 в собственном cached `postgres:16-alpine`, временный loopback-порт, без общего Compose. Один sample каждого режима, соседние задачи могли создавать нагрузку на host.

| Наблюдение | LIMIT 21 | Без LIMIT |
| --- | ---: | ---: |
| Возвращено строк | 21 | 10 000 |
| JSON результата, bytes | 25 075 | 11 933 347 |
| Живая PHP-память результата сверх baseline, bytes | 41 632 | 18 109 336 |
| Пик используемой PHP-памяти fetch сверх baseline, bytes | 42 008 | 18 109 712 |
| Shared hit blocks верхнего plan node | 19 | 12 930 |

Для LIMIT план — `Limit → Index Scan catalog_documents_status_id_idx`, actual rows 21/21, shared read blocks 0. Без LIMIT — `Gather Merge → Sort → Parallel Seq Scan`, 10 000 rows, temp read/write 565/566 blocks. Полная генерация **одинакового набора всех шести fixture queries** в subprocess заняла 132/135 ms; это не изолированная оценка добавочного времени разбора LIMIT. Generator bootstrap в runtime не загружен.

Пики сняты до JSON encoding/EXPLAIN и после загрузки классов; счётчики PHP не включают всю native память libpq, RSS процесса или память PostgreSQL. Это ограниченная проверка объёма чтения результата, а не доказательство ускорения или устойчивости долгоживущего worker. Большие поля всё ещё требуют прикладных ограничений. Без LIMIT исходный массив результата растёт вместе с количеством совпадений.

## Runtime и реальные потребители

[SqlExecutor](../../src/Platform/Storage/Sql/SqlExecutor.php) исполняет query через `Storage`, а typed rows создаются через `DatabaseRow::fromDatabaseRow`. `:one` выбирает первый результат либо возвращает null; сам по себе этот marker не доказывает уникальность строки в БД. Транзакции предоставляет `Storage::transaction`.

Сейчас даже `fetchOne` сначала получает весь result set: `Storage::query` PostgreSQL adapter вызывает `fetchAll`. `fetchAllRows` дополнительно создаёт массив typed rows. Поэтому `:one` не ограничивает работу БД или память PHP. Для растущих списков нужен явно ограниченный SQL с устойчивым порядком; `LIMIT` пока отсутствует в generator subset, хотя доступен в прямом параметризованном SQL через storage.

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

Каждое предложение оценивается по [трём целям проекта](../project/principles.md): максимальная простота, максимальная функциональность и максимальная производительность. Порядок работ задаёт [общий roadmap](../project/roadmap.md), а не список возможных возможностей generator-а.

До расширения expressions нужно закрыть ошибочную nullability внешних joins либо явно отвергать такие запросы. Ограниченный список покрыт явным LIMIT и отдельной PostgreSQL fixture. Оптимизация `fetchOne` или row iteration требует измерений и не должна скрытно менять существующий контракт массивов.

Более точное expression typing, составные relation checks и diagnostics добавляются под конкретные SQL-файлы. Расширение subset требует согласованных parser, semantic inference, generation и PostgreSQL checks. `*Params`, facade и новые AST-слои не являются самостоятельными целями: они допустимы только если упрощают подтверждённый сценарий без неоправданной runtime-стоимости.

Команды, CLI options и bundled distribution описаны в [README sql-gen](../../tools/sql-gen/README.md).
