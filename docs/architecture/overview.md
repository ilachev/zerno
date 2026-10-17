# Текущая архитектура

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-17.

Репозиторий содержит небольшое HTTP/PDO-ядро и существующее API-приложение, которое подключает дополнительные возможности. Этот документ описывает реализованное состояние; дальнейшие направления находятся в [roadmap](../project/roadmap.md), история перехода — в [датированном аудите](../history/2026-10-17-autonomy-audit.md). Подробный [поток запроса](request-lifecycle.md) и [среда/проверки](../development/infrastructure-and-testing.md) дополняют обзор.

## Границы и композиции

Общий контракт HTTP — [`RequestHandler`](../../src/Platform/Http/RequestHandler.php): PSR-7 request входит, PSR-7 response выходит. [`App`](../../src/Platform/Runtime/App.php) создаёт DI-контейнер из переданного PHP-конфига и делегирует настроенному обработчику. Он не содержит серверного цикла и не требует worker для прямого вызова `handle()`.

| Композиция | Что подключает | Где определяется |
|---|---|---|
| HTTP/PDO-ядро | PSR-7 factories, logger, request context, errors, router, PDO/SQL, миграции | [`config/core.php`](../../config/core.php) |
| Ядро через RoadRunner | То же плюс RR worker/logger | [`config/core-roadrunner.php`](../../config/core-roadrunner.php) |
| Существующее API | Generated protobuf endpoints/routes, session, API statistics, GeoIP, cache, HTTP client, hydration | [`config/container.php`](../../config/container.php) |

Минимальный набор маршрутов — явные GET/HEAD `/health` в [`config/routes.php`](../../config/routes.php). Ответ health не создаёт сессию, не пишет статистику и не открывает БД. API `/api/v1/health` относится к другой композиции и проходит её middleware.

`src/Platform` содержит переиспользуемые HTTP/runtime/DI/storage и дополнительные адаптеры. Принадлежность к этому каталогу сама по себе не делает адаптер обязательной частью `core.php`. `src/Capabilities` содержит прикладные модули Session и ApiStats с собственными domain/application/infrastructure/transport частями.

## Транспорт и обработка запроса

- [`public/http.php`](../../public/http.php) — SAPI/FPM front controller, по умолчанию `core.php`. [`SapiRequestFactory`](../../src/Platform/Runtime/SapiRequestFactory.php) переносит PHP globals в Nyholm PSR-7; [`SapiResponseEmitter`](../../src/Platform/Runtime/SapiResponseEmitter.php) выводит status/headers/body.
- [`public/index.php`](../../public/index.php) — RoadRunner front controller, по умолчанию API `container.php`. [`RoadRunnerRuntime`](../../src/Platform/Runtime/RoadRunnerRuntime.php) выполняет `waitRequest → handle → respond` до `null`.
- Оба entrypoints принимают `APP_CONFIG`. [`.rr.core.yaml`](../../.rr.core.yaml) выбирает core RR composition; [`.rr.yaml`](../../.rr.yaml) содержит API HTTP/RPC/KV/metrics/status configuration.

[`Pipeline`](../../src/Platform/Http/Middleware/Pipeline.php) собирается явно конфигурацией. Core: RequestContext → ErrorHandler → Routing → resolver. API: RequestContext → ErrorHandler → Session → ApiStats → Routing → HttpLogging → resolver. Состав middleware не зашит в `App`.

Для файловых ответов стоимость проверяется через весь путь: RR provider оставляет `PSR7Worker::chunkSize=0`, поэтому библиотека по умолчанию приводит body к строке; API HttpLogging читает остаток тела от текущей позиции до EOF. Наличие PSR-7 stream и SAPI emitter не устанавливает ограниченную память RR/API-композиции.

[`RequestContext`](../../src/Platform/Runtime/RequestContext.php) передаётся через request attributes и содержит request ID и deadline. Бюджет новый для каждого запроса, из [`config/http.php`](../../config/http.php); он используется downstream только при явной передаче и не останавливает произвольный handler автоматически. `ErrorHandlerMiddleware` преобразует исключения следующих слоёв в JSON 500; transport/bootstrap ошибки лежат вне его границ.

## DI и время жизни

[`DIContainer`](../../src/Platform/DI/DIContainer.php) поддерживает bindings, PHP factories и constructor autowiring. Он кеширует разрешённые instances и autowiring plans. Endpoint implementation discovery не является поведением DI: API provider регистрирует bindings явно из generated manifests.

При обычном SAPI `App` создаётся на запрос; при RR один контейнер обслуживает много запросов. Отдельного request scope/reset protocol для сервисов нет. Request/session/routing state хранится в request attributes; изменяемое состояние и кеши сервиса продолжают жить с контейнером. `RoadRunnerLogger` получает актуальный request ID в начале каждого вызова middleware.

## Маршруты и API-артефакты

Core использует `StaticRouteProvider` и явные `RouteEntry`. [`Router`](../../src/Platform/Routing/Router.php) строит таблицу при создании, выбирает маршрут по method/path; [`RoutingMiddleware`](../../src/Platform/Http/Middleware/RoutingMiddleware.php) передаёт handler/params в request. [`RouteHandlerResolver`](../../src/Platform/Http/RouteHandlerResolver.php) получает handler через container factory.

API [`RoutingServiceProvider`](../../src/Platform/DI/ServiceProviders/RoutingServiceProvider.php) читает `gen/Generated/OperationManifest`. `GeneratedOperationRouteProvider` строит HTTP bindings; implementation map связывает generated endpoint interfaces с handwritten endpoints. Каталог manifest сканируется и результат кешируется provider в текущем контейнере.

[`protos/proto`](../../protos/proto) содержит API contracts, [`protos/gen`](../../protos/gen) — protobuf SDK, [`gen/Generated`](../../gen/Generated) — SQL, handlers и operation manifests. Generated handler адаптирует protobuf JSON к handwritten endpoint; его прикладная логика остаётся в `src`. Обычному core handler protobuf не нужен.

## PDO и явный SQL

[`StorageServiceProvider`](../../src/Platform/DI/ServiceProviders/StorageServiceProvider.php) регистрирует `Storage` и [`SqlExecutor`](../../src/Platform/Storage/Sql/SqlExecutor.php). PDO открывается при создании storage после разрешения сервиса. Поэтому core `/health` работает без БД; API pipeline разрешает SQL-backed session/statistics dependencies уже при построении `App`.

[`Storage`](../../src/Platform/Storage/Storage.php) предоставляет parameterized queries и транзакции. `SqlExecutor` принимает typed query contracts и создаёт typed rows. PostgreSQL adapter ограничивает кеш prepared statements 100 записями; результат query читается целиком через `fetchAll()`, это не streaming row API.

PostgreSQL — основной и контрольный движок; SQLite adapter существует как опция, а не как замена PostgreSQL integration tests. Настройки — [`config/storage.php`](../../config/storage.php), `DB_*`, `DB_SCHEMA`, `STORAGE_ENGINE`. Schema — один quoted identifier.

Миграции запускаются отдельно через [`bin/migrate.php`](../../bin/migrate.php), с выбором той же композиции. Core указывает пустой прикладной путь `src/Migrations`; служебная таблица `migrations` создаётся при разрешении migration repository. API использует adapter migration paths и создаёт `sessions`, `api_stats`, `users`. Эти таблицы не являются обязательной схемой ядра.

SQL-генератор в [`tools/sql-gen`](../../tools/sql-gen) использует [`sql/queries`](../../sql/queries), [`sql/schema.sql`](../../sql/schema.sql) и проектный profile из [`codegen`](../../codegen). Runtime исполняет уже созданные PHP queries без загрузки генератора.

## Подключаемые возможности API

- [`SessionModule`](../../src/Capabilities/Session/SessionModule.php): SQL — источник session identity; bearer/cookie подтверждают ID, fingerprint не восстанавливает авторизованную сессию. Без действительного ID создаётся новая anonymous session. Refresh обновляет существующую активную строку, не вставляет отозванную заново.
- [`ApiStatsModule`](../../src/Capabilities/ApiStats/ApiStatsModule.php): пишет статистику после нормального возврата внутреннего pipeline. В текущем порядке поле route содержит URI path. Это не универсальный access log исключений.
- [`CacheServiceProvider`](../../src/Platform/DI/ServiceProviders/CacheServiceProvider.php): RR KV adapter с namespace/scope invalidation и ограниченным локальным fallback. Cache не участвует в чтении session identity; fallback не разделяется между workers. Старт `App` не очищает shared backend глобально.
- [`HttpClientServiceProvider`](../../src/Platform/DI/ServiceProviders/HttpClientServiceProvider.php): curl transport и resilient client с explicit request options/deadline. Core composition не регистрирует их автоматически.
- GeoIP, protobuf hydration и generated endpoints подключает API composition; они не требуются для явного core `/health`.

## Автономная загрузка и инструменты

[`bootstrap.php`](../../bootstrap.php) проверяет PHP 8.5/PDO, регистрирует собственные namespaces и карту [`third_party/runtime/autoload.php`](../../third_party/runtime/autoload.php). [`bootstrap/Autoloader.php`](../../bootstrap/Autoloader.php) поддерживает prefixes, classmap и function/bootstrap files. Composer ClassLoader и установка пакетов не участвуют.

Третьесторонние исходники включены фиксированными distributions: [`runtime`](../../third_party/runtime), [`codegen/sql`](../../third_party/codegen/sql), [`codegen/protobuf`](../../third_party/codegen/protobuf), [`tools`](../../third_party/tools). Версии, лицензии, адаптации и контрольные суммы находятся в manifests. Оригинальная metadata сохранена как часть источников, не как installer input.

Генераторы запускаются отдельными PHP-процессами со своими графами библиотек. Качество проверяется включёнными PHAR PHPUnit/PHPStan/PHP-CS-Fixer. Команды [`tools/project.php`](../../tools/project.php) не скачивают пакеты. Native `rr`, `protoc`, OpenAPI plugin, PHP extensions и сервер БД остаются требованиями соответствующей платформы.

[`tools/check-third-party.php`](../../tools/check-third-party.php) проверяет inventory и файлы без сети. `build` создаёт runtime-архив и OpenAPI sidecar; PHAR качества и generator distributions в runtime-архив не включаются.

## Что уже проверяется, а что пока не закреплено

`verify` проверяет integrity/documentation/style/PHPStan/SQL consistency/root unit tests без внешней БД. `verify:full` добавляет gates обоих генераторов и PostgreSQL SQL/integration profile. Точный состав и CI boundaries описаны в [справочнике проверок](../development/infrastructure-and-testing.md#автоматические-проверки).

Реализованы SAPI и RR adapters, core PDO composition и автономная поставка. Сравнительный benchmark FPM/RR, постоянный wire-contract suite для реальных серверов, автоматический reset request state всех сервисов и общее принудительное прерывание по deadline не реализованы. Ручные smoke-прогоны не заменяют эти гарантии. CMS, административный интерфейс и законченная user/auth product-модель не входят в текущую реализацию ядра.
