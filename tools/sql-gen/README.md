# SQL PHP Generator

Тип: `tool-guide`. Статус: `current`. Сверено с кодом: 2026-10-20.

`sql-gen` создаёт typed query и row classes из [sql/schema.sql](../../sql/schema.sql) и именованных запросов [sql/queries](../../sql/queries). Он запускается отдельно и не требуется при исполнении уже generated queries.

## Запуск

Из корня проекта «Зерно»:

```bash
php tools/project.php sql:gen
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php tools:sql:check
```

`sql:gen` обновляет `gen/Generated/Sql`. `sql:check` без БД проверяет отсутствующие, устаревшие и лишние generated PHP-файлы. `sql:check:pg` использует `TEST_DB_*` и отдельную случайную schema; проверяет запросы через PostgreSQL `PREPARE`, не выполняя их. `tools:sql:check` запускает style, PHPStan и tests инструмента.

## Входы и выходы

```sql
-- name: FindPublished :one
SELECT id, title
FROM catalog_documents
WHERE status = :status AND id = :id
LIMIT 1;
```

Поддержаны markers `:one`, `:many`, `:exec`. Результат — readonly `*Query` с typed constructor/`create()`, `sql()`, `params()` и, для result rows, `rowClass()`; row classes имеют типизированные поля. Отдельных `*Params` и `*Queries` facade сейчас нет.

Основные конструкции: ограниченные SELECT/JOIN/WHERE/ORDER BY/LIMIT, INSERT с placeholders/upsert/RETURNING, UPDATE с placeholder assignments/RETURNING и DELETE с WHERE. Полная grammar и известные ограничения, включая outer JOIN nullability, описаны в [контракте sql-gen](../../docs/design/sql-gen-product.md). Поддержка всего PostgreSQL не заявляется. Текущие project SQL inputs показывают структуру каталога с параметризованным status; приложение сохраняет прямой SQL с published literal для partial index. Runtime DDL находится в migration приложения.

Generated queries используют [runtime SQL contracts](../../src/Platform/Storage/Sql). Nette, Phplrt и Typhoon нужны инструменту, а не приложению при исполнении queries.

## SELECT с LIMIT

```sql
-- name: PageBefore :many
SELECT id, title FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT :limit;
```

При собственной schema с этими колонками generated `PageBeforeQuery::create(status: 'published', before: $before, limit: 21)` передаёт integer limit в PDO без interpolation. Для постоянного размера достаточно `LIMIT 21` без дополнительного параметра. Приложение показывает 20 строк и проверяет наличие 21-й; cursor и published-фильтр остаются явным SQL.

Поддержаны десятичный literal `0..PHP_INT_MAX` (ведущие нули разрешены) и именованный placeholder, включая `:limit`. LIMIT стоит после WHERE/ORDER BY и перед необязательным `;`. Placeholder получает обязательный non-null `int`/PostgreSQL `BIGINT`. При reuse с integer-колонками SQL-тип и диапазон сохраняются из первого сравнения JOIN/WHERE, как при native prepare; например, INTEGER не расширяется до BIGINT. Reuse с другим типом отвергается. Nullable integer-колонка не делает LIMIT nullable. Ноль возвращает пустой результат, отрицательный параметр отклоняет PostgreSQL. Вызывающий код задаёт свой максимальный размер страницы.

`ALL`, `NULL`, знаки/дроби/exponents, expressions/casts/parentheses, column LIMIT, повторные/переставленные clauses, OFFSET/comma pagination и LIMIT у DML отвергаются. Reuse LIMIT-параметра внутри projection expressions тоже отвергается: native PostgreSQL prepare может иначе вывести тип первого аргумента, чем checker с явными типами. Overflow literal даёт `LIMIT literal must be between 0 and PHP_INT_MAX.`. Существующий `:one` не добавляет ограничение SQL; порядок без ORDER BY не гарантируется.

Цена — обработка optional LIMIT в codegen; runtime сохраняет SQL и обычные typed params без новых зависимостей/guards. LIMIT ограничивает возвращённые строки, но не размер каждой строки и не объём сканирования БД. План и индекс нужно проверять на данных приложения. Подробности, границы применения к каталогу и воспроизводимые PostgreSQL проверки — в [дизайне](../../docs/design/sql-gen-product.md#ограниченная-select-выборка).

`php tools/project.php verify:integration` включает [generated LIMIT regression](../../tests/Integration/Storage/SqlLimitTest.php). При настроенных отдельных `TEST_DB_*` команда `php tests/Fixtures/SqlLimit/measure.php --rows=50000` печатает JSON с планами/буферами, количеством строк, bytes результата, PHP memory counters и временем полной fixture generation. Она удаляет свои случайные схемы и временный generated PHP. Протокол, команды PREPARE и границы измерений — в [проверке стоимости](../../docs/design/sql-gen-product.md#проверка-границ-и-стоимости). Установка пакетов и загрузка зависимостей не нужны.

## Прямой CLI и profiles

`bin/sql-gen.php` и `bin/sql-check.php` принимают:

| Option | Назначение |
| --- | --- |
| `--input-dir` | Каталог именованных SQL files |
| `--output-dir` | Каталог generated PHP |
| `--namespace` | Базовый generated namespace |
| `--schema` | Schema source для type resolution |
| `--runtime-namespace` | Runtime contracts namespace для default profile |
| `--bootstrap`, `--profile-class` | Подключение внешнего `SqlGenerationProfile` |

Первые четыре обязательны. Если выбран внешний profile, именно он определяет runtime contracts и naming. Корневой runner передаёт --runtime-namespace=App\Platform\Storage\Sql напрямую, без project-profile обёртки. Внешний profile нужен только потребителю с собственной политикой contracts/naming.

`bin/sql-check-pg.php` принимает `--input-dir`, `--schema`, `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password`. При прямом запуске fallback — `DB_*`; root runner отдельно преобразует `TEST_DB_*` в connection environment.

## Автономная поставка

[bootstrap.php](./bootstrap.php) подключает [bundled distribution](../../third_party/codegen/sql/autoload.php). Установка пакетов не нужна. [Manifest](../../third_party/codegen/sql/manifest.json) фиксирует версии, refs, лицензии и SHA256; полные upstream package files и notices сохранены в `packages`.

SQL generator запускается отдельным PHP-процессом. Не объединять его bootstrap с runtime в одном процессе; generator libraries в HTTP не загружаются.

Будущие component ideas описаны в [sql-gen-product.md](../../docs/design/sql-gen-product.md); приоритет задаёт [общий roadmap](../../docs/project/roadmap.md).
