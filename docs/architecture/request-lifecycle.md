# Поток HTTP-запроса

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-18.

Документ описывает существующие композиции приложения. Общая карта компонентов находится в [обзоре архитектуры](overview.md), команды запуска и проверки — в [инфраструктуре и тестировании](../development/infrastructure-and-testing.md).

## Композиция и точка входа

| Композиция | Конфигурация | Маршруты | Pipeline до конечного обработчика |
|---|---|---|---|
| Минимальное HTTP/PDO-ядро | [`config/core.php`](../../config/core.php) | Явные [`config/routes.php`](../../config/routes.php) | RequestContext → ErrorHandler → Routing → RouteHandlerResolver |
| То же ядро в RoadRunner | [`config/core-roadrunner.php`](../../config/core-roadrunner.php) | Те же явные маршруты | Тот же pipeline; добавлена регистрация RR worker и logger |
| Существующее protobuf API | [`config/container.php`](../../config/container.php) | Generated operation manifests | RequestContext → ErrorHandler → Session → ApiStats → Routing → HttpLogging → RouteHandlerResolver |
| Публичный каталог | [`examples/document-catalog/config.php`](../../examples/document-catalog/config.php), `roadrunner.php` | Собственные GET/HEAD | Общий core pipeline; HTML-представление ошибок |

Минимальное ядро обрабатывает `GET /health` и явно зарегистрированный `HEAD /health`; тело ответа обработчика — `{"status":"ok"}`. Оно не подключает capability middleware, protobuf endpoints, GeoIP и RR KV. `Storage` и миграции зарегистрированы, но `/health` не открывает соединение с БД.

Обе точки входа загружают [`bootstrap.php`](../../bootstrap.php) и выбирают конфигурацию через `APP_CONFIG`; абсолютный путь удобнее для независимости от рабочего каталога:

- [`public/http.php`](../../public/http.php): обычный PHP SAPI, включая PHP-FPM; по умолчанию `config/core.php`.
- [`public/index.php`](../../public/index.php): RoadRunner; по умолчанию `config/container.php`. Для минимального ядра [`.rr.core.yaml`](../../.rr.core.yaml) задаёт `config/core-roadrunner.php`.

Выбор транспорта не меняет контракт приложения: [`App`](../../src/Platform/Runtime/App.php) реализует `RequestHandler`. Его конструктор загружает переданный конфигурационный файл, создаёт `DIContainer`, выполняет регистрацию и получает настроенный `RequestHandler`. `handle()` вызывает его; `handleRequest()` оставлен как совместимый вызов того же метода. Конструктор `App` не запускает серверный цикл.

## Преобразование запроса и отправка ответа

Обычный PHP-путь:

```text
PHP SAPI → SapiRequestFactory::fromGlobals()
         → App::handle(PSR-7 request)
         → SapiResponseEmitter::emit(response, request method)
```

[`SapiRequestFactory`](../../src/Platform/Runtime/SapiRequestFactory.php) переносит server params, заголовки, query, cookies, `php://input` и вложенные uploads в объекты Nyholm PSR-7. `parsedBody` заполняется из `$_POST` только для POST form-urlencoded/multipart; JSON остаётся в body stream. Scheme и authority берутся из SAPI, без автоматического доверия `Forwarded`/`X-Forwarded-*`. Некорректные host/request-target отклоняются; `public/http.php` преобразует `InvalidArgumentException` при создании запроса в общий `400 Bad Request`.

[`SapiResponseEmitter`](../../src/Platform/Runtime/SapiResponseEmitter.php) проверяет `headers_sent()`, сохраняет отдельные значения заголовков и явный status, читает body блоками по 8192 байта. Seekable stream перематывается перед отправкой. Для HEAD, 1xx, 204 и 304 тело не читается и не выводится. Удаляется `Content-Length` для 1xx и 204. Ошибки построения `App` и отправки ответа находятся вне middleware error handler.

RoadRunner-путь:

```text
RoadRunner HTTP → PSR7Worker::waitRequest()
               → App::handle(PSR-7 request)
               → PSR7Worker::respond(response)
               → следующий waitRequest()
```

[`RoadRunnerRuntime`](../../src/Platform/Runtime/RoadRunnerRuntime.php) завершает цикл при `null` из `waitRequest()`. Ошибки worker не перехватываются этим адаптером. Перед `respond()` runtime заменяет body пустым для HEAD/1xx/204/304, не читая исходный поток; для 1xx/204 удаляет Content-Length. После отправки исходный и временный response streams закрываются в `finally`, включая transport error. Runtime владеет их завершением: handler должен возвращать новый stream для каждого запроса. Прямой `App::handle()` ничего не отправляет и не закрывает.

Преобразование HTTP и отправка делегированы включённому `PSR7Worker`; SAPI emitter здесь не участвует. `RoadRunnerServiceProvider(chunkSize: 8192)` явно включает чтение частями; default `0` сохраняет прежнюю материализацию body строкой. Каталог включает chunk 8192 для seekable regular files, остальные конфигурации остаются с default. Bundled streaming path требует seekable body и не поддерживает informational 1xx; общей поддержки произвольных потоков он не обещает. Проверенные размеры и память описаны в [отчёте каталога](../history/2026-10-17-public-catalog.md).

## Общие middleware

[`Pipeline`](../../src/Platform/Http/Middleware/Pipeline.php) вызывает middleware в порядке массива конфигурации. Каждый следующий слой получает переданную ему копию PSR-7 request; response возвращается через вызвавшие слои в обратном порядке. Изменение attributes во внутреннем слое не меняет request, сохранённый внешним слоем.

[`RequestContextMiddleware`](../../src/Platform/Http/Middleware/RequestContextMiddleware.php) выполняется первым во всех перечисленных композициях:

- сохраняет непустой `X-Request-ID` клиента или генерирует новый;
- создаёт новый `Deadline` по [`config/http.php`](../../config/http.php), сейчас 15 секунд;
- записывает `requestContext` и `requestId` в attributes через `RequestContextAttributes`;
- для `RoadRunnerLogger` обновляет его mutable `requestId`;
- добавляет к возвращённому response `X-Request-ID`, `X-Response-Time`, `Server-Timing`.

Deadline — бюджет для явно подключённого downstream-кода, а не принудительное прерывание запроса. [`RequestContextAttributes::inheritDeadline()`](../../src/Platform/Http/RequestContextAttributes.php) переносит его в options исходящего HTTP-вызова, если у options ещё нет своего deadline. Общий входящий deadline из клиентских заголовков не наследуется.

[`ErrorHandlerMiddleware`](../../src/Platform/Http/Middleware/ErrorHandlerMiddleware.php) оборачивает следующие слои: логирует полный `Throwable` строкой и создаёт общий `500` через выбранный `ErrorResponse`. Он не оборачивает предшествующий код `RequestContextMiddleware`, создание приложения, преобразование входящего запроса или transport emission. Детали исключения остаются в логах; доступ и хранение логов определяет эксплуатационный профиль.

## Представление HTTP-ошибок

[`ErrorResponse`](../../src/Platform/Http/ErrorResponse.php) — один метод `error(Error $error, int $status): ResponseInterface`. Он создаёт ответ с переданным status, заголовками и телом. Core передаёт только публичную ошибку из [`Error`](../../src/Platform/Error/Error.php), без `Throwable`, trace или других внутренних деталей. `RoutingMiddleware` использует его для 404/405 и сам добавляет `Allow` для 405; `ErrorHandlerMiddleware` — для 500. Готовые ответы handlers, включая ошибки, не преобразуются.

По умолчанию [`CoreServiceProvider`](../../src/Platform/DI/ServiceProviders/CoreServiceProvider.php) связывает контракт с [`JsonResponse`](../../src/Platform/Http/JsonResponse.php). Точные JSON bodies остаются прежними. Для другого формата:

1. Реализовать `ErrorResponse` в приложении, возвращая новый response/body для каждого вызова и сохраняя status. Шаблоны, язык сообщений и cache policy выбирает приложение.
2. После регистрации core и до разрешения `RequestHandler` указать `$container->bind(ErrorResponse::class, MyErrors::class)`. Зависимости renderer задаются обычным конструктором; настройка не требует замены pipeline.
3. Без контейнера передать тот же объект вторым аргументом `RoutingMiddleware($router, $errors)` и первым аргументом `ErrorHandlerMiddleware($errors, $logger)`.

Полный потребитель — [`конфигурация каталога`](../../examples/document-catalog/config.php) и его [`Errors`](../../examples/document-catalog/src/Errors.php). Каталог использует общий core pipeline и непосредственно строит HTML, без промежуточного JSON и распознавания `Content-Type`. `Html` и тексты страниц остаются локальными.

Выбор представления выполняется композицией один раз при построении pipeline. В default-профиле разрешается прежний `JsonResponse`; добавлены interface и binding. HTML-профиль разрешает свой renderer и `Html`, шаблоны читаются только при создании страницы. Успешный ответ handler не вызывает `error()`. Формат не выбирается по `Accept`, и дополнительных БД/сетевых обращений механизм не добавляет. Числовая стоимость и ускорение не измерены; сокращение промежуточной работы не объявляется доказанным выигрышем latency.

HEAD обрабатывают transport adapters: прямой `App::handle()` возвращает обычный response с телом, emitter/runtime его не читает и не отправляет. Ошибки SAPI request conversion и bootstrap находятся вне этого контракта. Ошибка логирования или создания самого ответа 500 выходит наружу; автоматического запасного renderer нет. Renderer не должен хранить состояние текущего запроса между вызовами.

Unit tests проверяют generic 500 и логирование, сохранение готового ответа, HTML без загрузки `JsonResponse` и последовательность ошибок/health. `http:check` повторяет wire-матрицу для JSON и HTML на FPM/RR, включая 404/405/500, `Allow`, HEAD и продолжение работы worker после исключения.

## Routing и конечный handler

[`Router`](../../src/Platform/Routing/Router.php) строит таблицы маршрутов и компилирует parameter patterns при создании. Literal-части пути экранируются для regex. Dispatch сначала ищет статический путь для запрошенного метода, затем параметризованные пути этого метода в порядке регистрации. Статический путь другого метода не блокирует подходящий параметризованный маршрут. Повторная регистрация method/path заменяет handler, сохраняя позицию маршрута.

Статические пути допускают прежний fallback: `/item/` и `/item///` могут выбрать `/item`; точное совпадение имеет приоритет. Корень остаётся `/`, параметризованные пути требуют точного trailing slash. HEAD → GET автоматически не подставляется.

Если path существует только для других методов, `RouteResult::getAllowedMethods()` возвращает объединение методов всех совпавших маршрутов без дубликатов, в порядке регистрации методов. `RoutingMiddleware` отвечает `405` и `Allow`. Для неизвестного path ответ — `404` без `Allow`. Представление создаёт выбранный `ErrorResponse`; default JSON — `{"error":"Method not allowed"}` / `{"error":"Not found"}`. На этих ветках внутренние middleware и handler не вызываются. Метод `getHandler()` больше не используется для передачи списка разрешённых методов.

Для найденного маршрута [`RoutingMiddleware`](../../src/Platform/Http/Middleware/RoutingMiddleware.php) передаёт дальше attributes `RouteResult::class`, `routeParams`, `handler`. [`RouteHandlerResolver`](../../src/Platform/Http/RouteHandlerResolver.php) передаёт class name в `HandlerFactoryInterface`; `ContainerHandlerFactory` получает экземпляр из DI и вызывает `handle()`.

В минимальной композиции `StaticRouteProvider` получает записи непосредственно из `config/routes.php`. Автоматический fallback HEAD → GET не используется: HEAD зарегистрирован отдельной записью.

В API-композиции [`RoutingServiceProvider`](../../src/Platform/DI/ServiceProviders/RoutingServiceProvider.php) загружает manifests из `gen/Generated/OperationManifest`, явно связывает endpoint interfaces с реализациями и строит маршруты через `GeneratedOperationRouteProvider`. DI сам не ищет реализации endpoints.

Явная команда `php tools/project.php http:check` проверяет эти ветки и ограниченную матрицу transport behavior через настоящие FPM/RR: HEAD, 204/304, повторные headers/cookies, query и последовательные запросы одного worker. Запуск и границы проверки описаны в [HTTP guide](../development/http-and-baseline.md); в default `verify` серверы не запускаются.

Например, `/api/v1/health` выбирает generated `CheckHttpHandler`, который создаёт protobuf request, вызывает handwritten [`CheckEndpoint`](../../src/Platform/Http/Endpoint/Api/V1/HealthService/CheckEndpoint.php) и сериализует protobuf response в JSON. Общий [`AbstractProtobufRpcHandler`](../../src/Platform/Http/Handler/AbstractProtobufRpcHandler.php) читает raw body как protobuf JSON; это отдельный путь от SAPI `parsedBody`.

## Дополнительные слои API-композиции

[`SessionMiddleware`](../../src/Capabilities/Session/Transport/Http/SessionMiddleware.php) стоит до routing, поэтому выполняется и для неизвестного API-маршрута:

1. Ищет ID в `Authorization: Bearer ...`, затем в cookie из `SessionConfig`.
2. Проверяет сессию через `SessionService` и непосредственно SQL-backed `SessionRepository`.
3. При отсутствии действительной сессии создаёт новую анонимную с `sessionTtl` и payload запроса.
4. Передаёт `session` через request attributes.
5. После возврата response обрабатывает и удаляет внутренние session-control headers. Destroy истекает cookie; active ID позволяет выбрать проверенную новую сессию.
6. Для status < 400 вызывает refresh и выставляет cookie. Refresh продлевает TTL при необходимости; отсутствие активной строки приводит к истечению cookie, а не повторной выдаче старого ID.

Fingerprint, IP и User-Agent не восстанавливают session identity. `ClientDetector` зарегистрирован отдельно для аналитики; middleware его не вызывает. Сессии не читаются из RR KV cache.

[`ApiStatsMiddleware`](../../src/Capabilities/ApiStats/Transport/Http/ApiStatsMiddleware.php) измеряет выполнение следующих слоёв и после нормального возврата записывает session ID, route, method, status, execution time и timestamp. В текущем порядке он видит исходный request до routing, поэтому `route` записывается как URI path; внутренний `RouteResult` не возвращается наружу. Обычные 404 учитываются, поскольку routing возвращает response. Исключение downstream прерывает запись: внешний `ErrorHandlerMiddleware` обрабатывает его уже после выхода из ApiStats.

[`HttpLoggingMiddleware`](../../src/Platform/Http/Middleware/HttpLoggingMiddleware.php) расположен после routing. Он логирует query params перед handler и содержимое response после нормального возврата. Это не общий access log: 404/405 и исключения handler не проходят обе записи. Текущая реализация читает `getContents()` response stream без восстановления позиции; считать этот слой прозрачным для произвольных потоков нельзя.

При нормальном возврате API-ответа порядок обратной обработки: HttpLogging → ApiStats → Session → ErrorHandler → RequestContext → transport. Поэтому `X-Response-Time` включает session/statistics post-processing, но не сетевую отправку ответа.

## Время жизни состояния

[`DIContainer`](../../src/Platform/DI/DIContainer.php) кеширует разрешённые объекты и планы autowiring на время жизни контейнера. Отдельного DI request scope и автоматического reset всех сервисов нет. Request context, session и routing attributes передаются в конкретном request; хранить их в собственных долгоживущих сервисах без явного управления временем жизни нельзя.

При SAPI точка входа создаёт `App` на один PHP-запрос. При RoadRunner один `App`, его PDO-соединение после разрешения и кеши живут через многие вызовы `handle()`. Регистрация provider сама по себе не означает, что все его сервисы уже созданы; API pipeline, в отличие от минимального, разрешает session/statistics зависимости и БД при построении.

Старт `App` не выполняет глобальную очистку cache. API cache использует deployment `namespace_seed` и namespace/scope invalidation; fallback при недоступном KV локален конкретному PHP-процессу. Эти механизмы не задают request scope и не распространяют локальные значения между workers.
