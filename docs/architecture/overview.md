# Текущая архитектура

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-20.

Проект поставляет конкретный HTTP/PDO-контур, SQL-генератор и проверочный каталог. Унаследованный API и optional capabilities удалены; [границы](../project/vision.md) и [решение об очистке](../history/2026-10-20-core-scope.md) объясняют состав.

## Композиция и транспорт

[App](../../src/Platform/Runtime/App.php) создаёт DIContainer из PHP-конфига, получает RequestHandler и делегирует handle(). Server loop в App отсутствует. [Pipeline](../../src/Platform/Http/Middleware/Pipeline.php) выполняется в порядке массива:

```text
RequestContext → ErrorHandler → Routing → RouteHandlerResolver → handler
```

[config/core.php](../../config/core.php) регистрирует HTTP factories, logger, routing и ленивую работу с PostgreSQL. Явные [GET/HEAD /health](../../config/routes.php) возвращают {"status":"ok"}, без SQL/cookies. [config/core-roadrunner.php](../../config/core-roadrunner.php) добавляет PSR7Worker/logger. [public/http.php](../../public/http.php) использует SAPI/FPM, [public/index.php](../../public/index.php) — RR. Оба выбирают конфигурацию через APP_CONFIG; RR default — core-roadrunner.php и [.rr.yaml](../../.rr.yaml).

[Каталог](../../examples/document-catalog/README.md) использует тот же pipeline, собственные routes/миграцию/FileStore и HTML ErrorResponse. Его RR profile задаёт chunk 8192. HttpLogging добавляется явно там, где нужны metadata logs; отдельной session/API-композиции нет.

## HTTP и ресурсы

RequestContext хранит request ID; middleware добавляет X-Request-ID, X-Response-Time и Server-Timing. Неиспользуемые deadline/config timeout удалены. ErrorResponse создаёт routing 404/405 и exception 500: default JSON, HTML через binding. Routing сам добавляет Allow. Полный Throwable пишется в logger, публичный response содержит общую ошибку.

AttachmentResponse принимает готовый stream и проверенный размер, нормализует filename и создаёт headers без чтения. HttpLogging пишет status/body_size/body_seekable и сохраняет body/позицию. SAPI/RR владеют закрытием stream после отправки и ошибок, включая HEAD. Прямой App::handle ничего не отправляет/закрывает.

SAPI читает по 8192 bytes. RR default chunk 0 материализует тело; 8192 проверен для seekable regular files. Некоторые read failures bundled RR подавляет; общей гарантии произвольных потоков/прерывания клиента нет. Подробности — [lifecycle](request-lifecycle.md).

## База данных и SQL

StorageServiceProvider создаёт PostgreSQLStorage только при запросе Storage. [config/storage.php](../../config/storage.php) возвращает PostgreSqlStorageConfig из DB_*; выбора движка нет. PDO использует параметры, транзакции и кеш до 100 prepared statements. Query получает fetchAll; fetchOne выбирает первую строку этого массива. LIMIT должен присутствовать в SQL явно.

MigrationServiceProvider получает прикладной путь конструктором. Core указывает пустой src/Migrations; каталог — свою migration. HTTP ничего не мигрирует; [bin/migrate.php](../../bin/migrate.php) выполняется отдельно. При чтении migration repository создаётся служебная таблица migrations.

[sql/schema.sql](../../sql/schema.sql) и [sql/queries/catalog.sql](../../sql/queries/catalog.sql) — входы самостоятельного SQL-генератора на примере структуры каталога. Runner явно задаёт --runtime-namespace=App\Platform\Storage\Sql; артефакты находятся в gen/Generated/Sql. CatalogRepository сохраняет прямой SQL с литералом published для partial index и проверяет nullable metadata перед созданием Document; typed queries служат отдельным примером SqlExecutor. Schema input не заменяет DDL migration. Подробности — [SQL-контракт](../design/sql-gen-product.md).

## Время жизни и поставка

DI кеширует объекты/планы autowiring. При SAPI App создаётся на запрос, при RR один контейнер/PDO живёт через много handle(). Автоматического request scope/reset всех services нет. Request state передаётся attributes; сервисы не должны удерживать предыдущий request.

Bootstrap регистрирует собственные namespaces и [runtime map](../../third_party/runtime/autoload.php). SQL generator/quality PHARs изолированы от runtime. Нативные PHP/FPM/RR, PostgreSQL и инструменты стенда — требования платформы, а не скачиваемые PHP dependencies. [Поставка](../design/autonomous-core.md) описывает три distributions и транспортные зависимости RR.

[verify](../development/infrastructure-and-testing.md) не требует внешней БД; verify:full добавляет SQL checks и настоящие PostgreSQL integration tests. http:check проверяет реальные FPM/RR отдельно. Числовые бюджеты, основной runtime и длительная устойчивость памяти не устанавливаются короткой проверкой.
