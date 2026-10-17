# SQL PHP Generator

Тип: `tool-guide`. Статус: `current`. Сверено с кодом: 2026-10-17.

`sql-gen` создаёт typed query и row classes из [sql/schema.sql](../../sql/schema.sql) и именованных запросов [sql/queries](../../sql/queries). Он независим от protobuf и не требуется при исполнении уже generated queries.

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
-- name: FindSessionById :one
SELECT id, user_id, payload, expires_at, created_at, updated_at
FROM sessions
WHERE id = :id;
```

Поддержаны markers `:one`, `:many`, `:exec`. Результат — readonly `*Query` с typed constructor/`create()`, `sql()`, `params()` и, для result rows, `rowClass()`; row classes имеют типизированные поля. Отдельных `*Params` и `*Queries` facade сейчас нет.

Основные конструкции: ограниченные SELECT/JOIN/WHERE/ORDER BY, INSERT с placeholders/upsert/RETURNING, UPDATE с placeholder assignments/RETURNING и DELETE с WHERE. Полная grammar и известные ограничения, включая outer JOIN nullability, описаны в [контракте sql-gen](../../docs/design/sql-gen-product.md). Поддержка всего PostgreSQL не заявляется.

Generated queries используют [runtime SQL contracts](../../src/Platform/Storage/Sql). Nette, Phplrt и Typhoon нужны инструменту, а не приложению при исполнении queries.

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

Первые четыре обязательны. Если выбран внешний profile, именно он определяет runtime contracts и naming. Project profile находится в [codegen/Sql](../../codegen/Sql/BaseApiTemplateSqlGenerationProfile.php); имя `BaseApiTemplate...` сохранено как существующий PHP API.

`bin/sql-check-pg.php` принимает `--input-dir`, `--schema`, `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password`. При прямом запуске fallback — `DB_*`; root runner отдельно преобразует `TEST_DB_*` в connection environment.

## Автономная поставка

[bootstrap.php](./bootstrap.php) подключает [bundled distribution](../../third_party/codegen/sql/autoload.php). Установка пакетов не нужна. [Manifest](../../third_party/codegen/sql/manifest.json) фиксирует версии, refs, лицензии и SHA256; полные upstream package files и notices сохранены в `packages`.

SQL и protobuf generators запускаются отдельными PHP-процессами: их dependency versions закреплены независимо. Не подключать оба generator bootstrap или root runtime bootstrap в один процесс.

Будущие component ideas описаны в [sql-gen-product.md](../../docs/design/sql-gen-product.md); приоритет задаёт [общий roadmap](../../docs/project/roadmap.md).
