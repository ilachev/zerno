# Текущая архитектура

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-23.

Проект поставляет HTTP/PDO-контур, SQL/API-генерацию, проверочный каталог и Echo из контракта. [Границы](../project/vision.md), [правила кода](../development/coding-guidelines.md) и [история явной сборки](../history/2026-10-20-explicit-composition.md) объясняют принятый состав. Контейнер, autowiring и providers удалены.

## Композиция

[App](../../src/Platform/Runtime/App.php) получает готовый RequestHandler конструктором и делегирует handle(). Он не читает конфигурацию, не ищет сервисы и не владеет server loop. [Pipeline](../../src/Platform/Http/Middleware/Pipeline.php) исполняет переданные middleware:

```text
RequestContext → ErrorHandler → Routing → RouteHandlerResolver → готовый handler
```

[config/core.php](../../config/core.php) возвращает Closure(Logger): App и явно создаёт pipeline, router, errors и request context через new. [Маршруты](../../config/routes.php) содержат готовый HealthHandler для GET/HEAD /health. [Router](../../src/Platform/Routing/Router.php) получает list<RouteEntry> без providers/collectors и возвращает тот же объект обработчика; class-name lookup отсутствует. RouteHandlerResolver проверяет RequestHandler в attribute и вызывает его.

[Каталог](../../examples/document-catalog/application.php) — отдельная функция Closure(Logger, Storage, string): App. В одном месте создаются CatalogRepository, Html/Errors, FileStore и List/Detail/Download handlers, затем передаются [функции маршрутов](../../examples/document-catalog/routes.php). [config.php](../../examples/document-catalog/config.php) выбирает DB settings/private directory и вызывает эту сборку. Нет универсального service bag/get/resolve или неявного поиска зависимостей. Простая операция не требует обязательных domain/application/repository слоёв.

## Транспорт и ресурсы

[public/index.php](../../public/index.php) — единственная HTTP-точка входа. Она передаёт RoadRunnerLogger в APP_CONFIG функцию, явно создаёт native transport и запускает RoadRunnerRuntime. Default APP_CONFIG — config/core.php. CLI commands строят собственные графы без HTTP loop.

Целевой server — RoadRunner 3.x (проверен 3.0.0), отдельный executable платформы. Обновлены native transport sources: worker 3.8.0, HTTP 4.2.1, Goridge 4.6.0, API DTO 2.1.0. PHP namespaces сохранены upstream, пути packages следуют roadrunner/*.

RoadRunnerRuntime::createWorker(transport, chunkSize) создаёт собственный [RoadRunnerHttpWorker](../../src/Platform/Runtime/RoadRunnerHttpWorker.php) поверх нативного RR HttpWorker, без PSR factories/adapters. RR_HTTP_CHUNK_SIZE задаётся в server env: default 0 материализует body, каталог/HTTP fixtures выбирают 8192. Чтение частями удерживает текущий и следующий chunk; seekable body перематывается, non-seekable читается с текущей позиции. Native transport завершает поток при ошибке генератора; адаптер сохраняет и повторно выбрасывает read failure. Полного охвата отмены клиента и произвольных потоков нет.

[Request](../../src/Platform/Http/Request.php), [Response](../../src/Platform/Http/Response.php), [Body](../../src/Platform/Http/Body.php), [Uri](../../src/Platform/Http/Uri.php) и [UploadedFile](../../src/Platform/Http/UploadedFile.php) — concrete types ядра. Handler принимает Request и возвращает Response; дополнительных interfaces/factories для сообщений нет. Копии metadata неизменяемы через with-методы; Body разделяет позицию/владение. Строковый Body не открывает php://temp; файловый владеет переданным PHP resource. Nyholm/PSR HTTP/factory/log packages удалены.

RequestContext содержит request ID; middleware добавляет X-Request-ID, X-Response-Time и Server-Timing. ErrorResponse передаётся непосредственно ErrorHandlerMiddleware/RoutingMiddleware: default JSON, в каталоге HTML. Приватный Throwable остаётся в logger. В RR configs общий logs.level=error, но channels.server.level=info сохраняет PHP stderr, включая ошибки приложения; production envelope теперь содержит time и uppercase level. Это внешний server envelope, PHP Logger не меняется. HttpLogging добавляется объектом middleware там, где нужен; payload не читает.

AttachmentResponse принимает stream/размер, создаёт безопасные headers и передаёт тот же body. RoadRunnerRuntime закрывает response streams после отправки/ошибки, включая HEAD. App::handle ничего не отправляет и не закрывает. Подробности — [lifecycle](request-lifecycle.md).

## PostgreSQL и CLI

[config/storage.php](../../config/storage.php) явно создаёт PostgreSQLStorage из DB_HOST/PORT/NAME/USER/PASSWORD/SCHEMA. Конструктор не открывает PDO: первая query/queryOne/execute/transaction/lastInsertId создаёт соединение и устанавливает quoted search_path. PDO публикуется в объекте после успешной инициализации и используется повторно. Ошибка подключения теперь возникает при первой операции. Кеш native prepared statements ограничен 100 SQL-запросами.

HTTP core вообще не создаёт Storage. Каталог создаёт его и handlers при сборке; /health/404/405 не выполняют SQL. Это имеет стоимость чтения config/создания PHP objects, но не подключения к БД. query получает fetchAll, queryOne читает первую строку через fetch и закрывает PDO cursor; SqlExecutor.fetchOne и lookup каталога используют queryOne. SQL не переписывается, prepared statements переиспользуются. Native buffer cached statement может сохраняться после closeCursor; ограничение возвращаемых БД строк задаётся явным LIMIT. [SQL-контракт](../design/sql-gen-product.md#runtime-и-применение) описывает семантику, ошибки и цену.

Storage.transaction владеет одной транзакцией, отклоняет вложенность и сохраняет пойманную внутри callback SQL-ошибку до rollback. Cleanup после неудачного commit не затирает исходный SQLSTATE; при сбое rollback TransactionRollbackException сохраняет обе причины. [Контракт транзакций и миграций](../design/transactions-and-migrations.md) описывает границы и потерю соединения.

[bin/migrate.php](../../bin/migrate.php) получает действие и каталог migrations, проверяет аргументы до соединения и явно создаёт Storage → MigrationRepository/Loader → MigrationService → MigrateCommand. Default directory — пустой src/Migrations; каталог передаёт examples/document-catalog/migrations. Loader подключает PHP-файлы выбранного каталога напрямую, без регистрации application namespace. Migration.up/down возвращают списки полных SQL statements; каждая версия вместе с записью в migrations применяется/откатывается в своей транзакции, дубли версий отклоняются. Только CLI создаёт таблицу migrations; HTTP startup DDL не выполняет.

[SQL inputs](../../sql/queries/catalog.sql)/[schema](../../sql/schema.sql) — проверочный пример генератора. Runner передаёт runtime namespace напрямую. Каталог сохраняет SQL constants с published literal для partial index. Generated query execution не загружает codegen libraries; [SQL-контракт](../design/sql-gen-product.md) сохраняет ограничения subset.

## Время жизни и проверки

Граф объектов принадлежит функции сборки: RoadRunner создаёт его один раз на worker. Готовые handlers могут разделять repository/storage; request state передаётся attributes. Автоматического service reset нет, requests/bodies не должны удерживаться между вызовами. Ленивость касается PDO, а не наличия графа PHP-объектов.

Runtime/codegen/quality distributions остаются [автономными](../design/autonomous-core.md). verify не требует внешних сервисов; verify:full добавляет SQL tool и реальный PostgreSQL. http:check отдельно проверяет RR3. Для изменения выполнены [проверки и ограниченное сравнение](../history/2026-10-20-explicit-composition.md); выбор RR теперь задан продуктом; production readiness и длительная устойчивость не установлены.

## Генерация API из контракта

[API из protobuf-контракта](../design/protobuf-contract-generation.md) реализован для proto3 string messages и unary POST JSON. tools/proto-gen читает сохранённый descriptor, генерирует readonly DTO, endpoint interface и HTTP adapter с route(); protoc нужен для изменения контракта/полной сверки. [Пример](../../examples/contract-echo/config.php) явно создаёт ручной endpoint и adapter. Runtime archive содержит готовый PHP без contracts/tools; /health не загружает DTO/codec и не открывает БД. Контейнер и сервисные registries не вводились.
