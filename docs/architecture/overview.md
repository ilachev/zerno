# Текущая архитектура

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-20.

Проект поставляет HTTP/PDO-контур, SQL-генератор и проверочный каталог. [Границы](../project/vision.md), [правила кода](../development/coding-guidelines.md) и [история явной сборки](../history/2026-10-20-explicit-composition.md) объясняют принятый состав. Контейнер, autowiring и providers удалены.

## Композиция

[App](../../src/Platform/Runtime/App.php) получает готовый RequestHandler конструктором и делегирует handle(). Он не читает конфигурацию, не ищет сервисы и не владеет server loop. [Pipeline](../../src/Platform/Http/Middleware/Pipeline.php) исполняет переданные middleware:

```text
RequestContext → ErrorHandler → Routing → RouteHandlerResolver → готовый handler
```

[config/core.php](../../config/core.php) возвращает Closure(Logger): App и явно создаёт pipeline, router, errors и request context через new. [Маршруты](../../config/routes.php) содержат готовый HealthHandler для GET/HEAD /health. [Router](../../src/Platform/Routing/Router.php) получает list<RouteEntry> без providers/collectors и возвращает тот же объект обработчика; class-name lookup отсутствует. RouteHandlerResolver проверяет RequestHandler в attribute и вызывает его.

[Каталог](../../examples/document-catalog/application.php) — отдельная функция Closure(Logger, Storage, string): App. В одном месте создаются CatalogRepository, Html/Errors, FileStore и List/Detail/Download handlers, затем передаются [функции маршрутов](../../examples/document-catalog/routes.php). [config.php](../../examples/document-catalog/config.php) выбирает DB settings/private directory и вызывает эту сборку. Нет универсального service bag/get/resolve или неявного поиска зависимостей. Простая операция не требует обязательных domain/application/repository слоёв.

## Транспорт и ресурсы

[public/http.php](../../public/http.php) строит SAPI request, загружает выбранную APP_CONFIG функцию, передаёт ReadableOutputLogger и отправляет response. [public/index.php](../../public/index.php) передаёт RoadRunnerLogger, явно создаёт transport worker и запускает RoadRunnerRuntime с тем же приложением. Default APP_CONFIG для обоих — config/core.php.

RoadRunnerRuntime::createWorker(transport, chunkSize) создаёт PSR17/PSR7 adapter и проверяет неотрицательный chunkSize. RR_HTTP_CHUNK_SIZE задаётся в конфигурации server env: default 0 материализует body, каталог/HTTP fixtures выбирают 8192. Проверен seekable regular-file путь; произвольные потоки и некоторые upstream read failures остаются ограничениями. SAPI читает по 8192 bytes.

RequestContext содержит request ID; middleware добавляет X-Request-ID, X-Response-Time и Server-Timing. ErrorResponse передаётся непосредственно ErrorHandlerMiddleware/RoutingMiddleware: default JSON, в каталоге HTML. Приватный Throwable остаётся в logger. HttpLogging добавляется объектом middleware там, где нужен; payload не читает.

AttachmentResponse принимает stream/размер, создаёт безопасные headers и передаёт тот же body. SAPI/RR закрывают response streams после отправки/ошибки, включая HEAD. App::handle ничего не отправляет и не закрывает. Подробности — [lifecycle](request-lifecycle.md).

## PostgreSQL и CLI

[config/storage.php](../../config/storage.php) явно создаёт PostgreSQLStorage из DB_HOST/PORT/NAME/USER/PASSWORD/SCHEMA. Конструктор не открывает PDO: первая query/execute/transaction/lastInsertId создаёт соединение и устанавливает quoted search_path. PDO публикуется в объекте после успешной инициализации и используется повторно. Ошибка подключения теперь возникает при первой операции. Кеш native prepared statements ограничен 100 SQL-запросами.

HTTP core вообще не создаёт Storage. Каталог создаёт его и handlers при сборке; /health/404/405 не выполняют SQL. Это имеет стоимость чтения config/создания PHP objects, но не подключения к БД. Query получает fetchAll, SqlExecutor.fetchOne выбирает первую строку массива; ограничение задаётся явным LIMIT.

[bin/migrate.php](../../bin/migrate.php) получает действие и каталог migrations, проверяет аргументы до соединения и явно создаёт Storage → MigrationRepository/Loader → MigrationService → MigrateCommand. Default directory — пустой src/Migrations; каталог передаёт examples/document-catalog/migrations. Loader подключает PHP-файлы выбранного каталога напрямую, без регистрации application namespace. Только CLI создаёт таблицу migrations; HTTP startup DDL не выполняет.

[SQL inputs](../../sql/queries/catalog.sql)/[schema](../../sql/schema.sql) — проверочный пример генератора. Runner передаёт runtime namespace напрямую. Каталог сохраняет SQL constants с published literal для partial index. Generated query execution не загружает codegen libraries; [SQL-контракт](../design/sql-gen-product.md) сохраняет ограничения subset.

## Время жизни и проверки

Граф объектов принадлежит функции сборки: SAPI создаёт его на запрос, RR — на worker. Готовые handlers могут разделять repository/storage; request state передаётся attributes. Автоматического service reset нет, requests/bodies не должны удерживаться между вызовами. Ленивость касается PDO, а не наличия графа PHP-объектов.

Runtime/codegen/quality distributions остаются [автономными](../design/autonomous-core.md). verify не требует внешних сервисов; verify:full добавляет SQL tool и реальный PostgreSQL. http:check отдельно проверяет FPM/RR. Для изменения выполнены [проверки и ограниченное сравнение](../history/2026-10-20-explicit-composition.md); основной runtime, production readiness и длительная устойчивость не установлены.

## Принятое расширение

[API из protobuf-контракта](../design/protobuf-contract-generation.md) — DTO, endpoint contracts, HTTP adapters и явные routes. Генератора пока нет. Его output должен принимать ручные реализации и зависимости конструкторами, не возвращая контейнер, autowiring или сервисные registries.
