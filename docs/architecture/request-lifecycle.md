# Поток запроса

Этот документ описывает фактический runtime flow HTTP-запроса.

## Краткая схема

```text
Client
  -> RoadRunner HTTP
  -> public/index.php
  -> App
  -> DI container
  -> Pipeline
  -> Middleware chain
  -> Router
  -> Handler
  -> Mapper / JsonResponse
  -> Response
```

## 1. Вход в приложение

RoadRunner запускает PHP worker через:

```yaml
server:
  command: "php public/index.php"
```

Файл `public/index.php` создаёт `App` и вызывает `run()`.

## 2. Инициализация `App`

`src/Platform/Runtime/App.php` делает следующее:

1. загружает `config/container.php`;
2. создаёт `DIContainer`;
3. регистрирует сервисы через service providers;
4. получает `PSR7Worker`;
5. собирает middleware pipeline из platform middleware и capability middleware.
 
Кеш при старте worker-а больше не очищается глобально.

Deployment-aware invalidation теперь идёт через `namespace_seed` в cache config, а runtime invalidation внутри приложения должна опираться на namespace/scope rotation, а не на полный wipe shared backend-а.

## 3. Получение запроса

Внутри `App::run()` работает бесконечный цикл:

- `waitRequest()`
- `handleRequest($request)`
- `respond($response)`

Это long-running модель, поэтому любой singleton-like объект живёт дольше одного запроса.

## 4. Выполнение middleware pipeline

Pipeline в проекте рекурсивный. Каждый middleware получает:

- текущий `ServerRequestInterface`;
- следующий `RequestHandler`.

Порядок выполнения:

1. `RequestContextMiddleware`
2. `ErrorHandlerMiddleware`
3. `SessionMiddleware`
4. `ApiStatsMiddleware`
5. `RoutingMiddleware`
6. `HttpLoggingMiddleware`
7. конечный `RouteHandlerResolver`

`RequestContextMiddleware` создаёт общий request-scoped context:

- `requestId`
- inherited `Deadline`

Если клиент уже прислал `X-Request-ID`, runtime сохраняет его. Иначе request ID генерируется внутри `Platform/Runtime/RequestContextFactory`.

Этот context живёт в request attributes и должен считаться канонической точкой привязки для downstream timeout-budget propagation.

## 5. `SessionMiddleware`

Это ключевой этап, потому что он формирует session context поверх уже существующего request context.

Основные действия:

- ищет session ID в `Authorization: Bearer ...` или cookie;
- валидирует существующую сессию;
- если сессии нет, формирует `SessionPayload`;
- без действительного bearer token или cookie создаёт новую анонимную сессию с настроенным `sessionTtl`;
- добавляет объект `session` в request attributes;
- после успешного ответа refresh-ит TTL и выставляет `Set-Cookie`.

В результате downstream код может считать, что:

- `requestContext` уже присутствует в request;
- `session` уже присутствует в request.

Fingerprint, IP и User-Agent не подтверждают владение сессией и не используются для восстановления session identity. `ClientDetector` доступен отдельно для аналитики похожих клиентов.

## 6. `ApiStatsMiddleware`

Измеряет время выполнения запроса и сохраняет запись в `api_stats`.

Что он использует:

- `session` из request attributes;
- route information после dispatch;
- HTTP method, status code, execution time.

Это cross-cutting concern, но он зависит от фактической модели сессий.

## 7. `RoutingMiddleware`

Вызывает router и определяет:

- найден ли маршрут;
- какой handler должен выполнить запрос;
- какие route params надо записать в request.

Если маршрут не найден, middleware сразу возвращает JSON error response.

Если маршрут найден, в request attributes кладутся:

- `routeParams`
- `handler`

## 8. `RouteHandlerResolver`

Финальный request handler в pipeline.

Его задача:

- взять имя handler из request attributes;
- разрешить handler через DI;
- вызвать `handle($request)`.

Таким образом, middleware не знает, как именно создаётся handler, а runtime resolution централизован.

## 9. Handler и endpoint implementation

На примере `HealthService.Check` flow выглядит так:

1. router выбирает сгенерированный `CheckHttpHandler`;
2. handler декодирует protobuf request через `AbstractProtobufRpcHandler`;
3. handler вызывает handwritten endpoint implementation `App\Platform\Http\Endpoint\Api\V1\HealthService\CheckEndpoint`;
4. endpoint implementation создаёт protobuf response model;
5. handler отдаёт JSON через `JsonResponse`.

Именно здесь reusable transport adapter должен заканчиваться, а прикладная логика начинаться в endpoint implementation.

## 10. Возврат ответа

Response возвращается обратно через цепочку middleware.

На обратном пути возможны побочные эффекты:

- `SessionMiddleware` refresh-ит сессию и выставляет cookie;
- `ApiStatsMiddleware` сохраняет метрику;
- `HttpLoggingMiddleware` пишет лог;
- `ErrorHandlerMiddleware` перехватывает исключения и нормализует ошибку.

## Что должна помнить LLM

- Сессия создаётся до routing и handler.
- Route selection происходит не по контроллерам, а по сгенерированному route config.
- Default runtime не должен зависеть от demo-flow кода.
- Long-running runtime требует осторожности с кешами, статикой и состоянием сервисов.
