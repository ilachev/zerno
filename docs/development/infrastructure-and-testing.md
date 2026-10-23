# Инфраструктура и тестирование

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-23.

[PHP runner](../../tools/project.php) — единственный command interface; установка пакетов/Task не требуется. Runtime: PHP 8.5+, PDO; для БД — pdo_pgsql и PostgreSQL. Для unit/tool checks нужны ctype/dom/json/libxml/mbstring/tokenizer/xml/xmlwriter и pdo_sqlite для тестовой migration fixture. SQLite не является БД приложения и не входит в runtime archive.

Для HTTP check нужен установленный RoadRunner 3.x (проверен rr 3.0.0); RR требует sockets. Выбор executable — PATH либо --rr=/path/to/rr у http:check. Для нагрузок — k6, Docker используется только при выборе локального PostgreSQL service. PHP libraries и quality/codegen tools уже включены; executable downloads не являются частью checks.

## Команды

| Команда | Что выполняется |
| --- | --- |
| verify | Integrity → docs → style → PHPStan 10 → SQL/API synchronization → root unit tests |
| verify:integration | sql:check:pg → PostgreSQL integration tests |
| verify:full | verify → tools:sql:check → verify:integration |
| proto:gen / proto:compile:check | Компиляция/полная сверка Echo через platform protoc |
| proto:check | Хеши contract sources/descriptor и точная сверка generated PHP без compiler |
| tools:sql:check | Style, PHPStan и tests SQL generator |
| http:check | Настоящий RR3, JSON/HTML ошибки и файлы с metadata logging |
| build VERSION | Integrity и runtime tar.gz; не заменяет quality gate |
| services:start | Только PostgreSQL Docker service с --pull never, без миграций |
| services:stop / services:status | Остановка / статус compose-проекта |
| run | Только запуск RR core из .rr.yaml; не требует запуска БД для /health |
| migrate [action] [directory] | Явный CLI DDL; default src/Migrations |

Полный справочник — php tools/project.php help. proto:gen и proto:compile:check требуют установленный protoc (проверен 26.1); proto:check входит в verify и сверяет sources/descriptor/PHP без compiler. Нет cache/legacy load commands, Task aliases, Redis service или OpenAPI release sidecar. CI и release используют verify:full и build.

## База приложения

config/storage.php создаёт PostgreSQLStorage из DB_HOST/PORT/NAME/USER/PASSWORD/SCHEMA. Адаптер открывает PDO только при первой query/queryOne/execute/transaction/lastInsertId; конструктор не выполняет I/O. STORAGE_ENGINE и backend selector удалены. DB_SCHEMA — один quoted identifier, не список search_path.

Текущие миграции возвращают массив отдельных SQL в up/down. Каждая версия и её запись в migrations атомарны; после исправления сбоя CLI запускается явно повторно. [Полный контракт](../design/transactions-and-migrations.md) фиксирует nested calls, исходные ошибки, потерю соединения и ограничения DDL.

Core migration path — пустой src/Migrations; пример имеет собственную migration. Явный migrate создаёт служебную таблицу migrations при явном создании MigrationRepository в CLI. Запуск HTTP и services:start не запускают migration автоматически. Существующие пользовательские таблицы старого API не удаляются этой очисткой.

## Unit и integration profiles

[phpunit.xml](../../phpunit.xml) включает tests/Unit. Реальные внутренние реализации проверяются без внешней БД. tests/Fixtures/SQLiteStorage использует настоящий PDO SQLite только для migration lifecycle unit tests; production SQLite backend не поставляется. Тот же реальный PDO fixture проверяет закрытый HTML error path; контейнеры/mocks внутренних сервисов не нужны. RR unit tests используют double внешнего WorkerInterface и реальный RoadRunnerHttpWorker/HttpWorker. HTTP-типы и ресурсы проверяются реальными объектами; ObservedStream — PHP stream wrapper для наблюдения чтений/seek/close.

[phpunit.integration.xml](../../phpunit.integration.xml) включает tests/Integration. Его bootstrap загружает только test/runtime autoload; нет shared API App, автоматических миграций или глобальной схемы. Каждый тест явно владеет PostgreSqlTestDatabase, случайной schema phpunit_<24 hex> и очисткой. Каталог дополнительно владеет private temporary files; SQL LIMIT fixture генерируется отдельным процессом. SqlExecutor и generated row mapping проверяются на настоящем PostgreSQL; прежний InMemorySqlExecutorStorage удалён. QueryOneTest отдельно проверяет one-row семантику, SQLSTATE/reuse и отсутствие полного PHP-массива; этот memory counter не включает native libpq buffers/RSS.

Runner берёт TEST_DB_HOST/PORT/NAME/USER/PASSWORD независимо от DB_*. Для прямого PHPUnit TEST_DB_NAME обязателен. Роли нужны права создавать и удалять свои схемы. Значения ниже относятся к заранее подготовленной отдельной test database:

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:full
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php
php tools/project.php tools:sql:check
```

[PostgreSqlTestDatabase](../../tests/Integration/PostgreSqlTestDatabase.php) не выбирает runtime DB и удаляет только успешно созданную им схему. Tests проверяют сохранение public sentinel и соседних схем. sql:check:pg создаёт отдельную sql_gen_check_<random>. Очистка после принудительного убийства процесса не гарантируется; удалять чужие schemas/containers нельзя.

## HTTP, нагрузки и поставка

[HTTP guide](http-and-baseline.md) описывает реальные listeners, временные ports, контроль body/hash/HEAD и ограничения памяти. Ни наличие Body, ни один unit test не доказывают bounded memory всего pipeline. Короткие результаты не устанавливают runtime winner или длительную устойчивость.

SQL inputs генератора, автономная загрузка PHP migrations без application autoload, default HTTP/RR и распакованный runtime archive проверяются вместе при изменении состава. Исторические данные предыдущих композиций остаются [историей](../README.md#исторические-материалы); они не являются инструкцией запускать удалённый API.
