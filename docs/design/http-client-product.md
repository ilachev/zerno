# Контракт и развитие `Platform/Http/Client`

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-17.

## Текущая роль

[Platform/Http/Client](../../src/Platform/Http/Client) — дополнительный outbound HTTP-компонент «Зерна». Он не является обязательной частью минимального HTTP/PDO-профиля.

`HttpClientServiceProvider` подключает `ResilientHttpClient` и `CurlTransport` в существующем `config/container.php`. `config/core.php` этот provider не регистрирует. Для cURL transport требуется платформенное PHP-расширение `curl`.

Текущая цепочка:

```text
Consumer -> HttpClient / ResilientHttpClient -> HttpTransport / CurlTransport
```

Client управляет attempts, backoff и общим deadline. Transport выполняет одну попытку. Typed consumer wrappers и domain mapping находятся вне этого слоя.

## Действующие контракты и настройки

- `HttpRequest`: method, URI, headers, string body, upstream label и options.
- `HttpResponse`: status, headers и полный string body.
- `HttpRequestOptions`: connect timeout, общий timeout budget, redirects, idempotent flag, user agent, optional deadline и retry policy.
- `RetryPolicy`: число попыток, задержки и retryable statuses.
- `Platform/Runtime/Deadline`: оставшийся бюджет; `RequestContext` переносит deadline и request ID входящего запроса.

Текущие defaults из [HttpRequestOptions](../../src/Platform/Http/Client/HttpRequestOptions.php) и [RetryPolicy](../../src/Platform/Http/Client/RetryPolicy.php):

| Настройка | Значение |
| --- | --- |
| Connect timeout | 3 секунды |
| Общий timeout | 10 секунд |
| Redirects | Включены, максимум 3 |
| `idempotent` | `true` |
| Attempts | 2, включая первую |
| Backoff | Base 100 ms, cap 1000 ms, exponential с jitter |
| Retryable statuses | 429, 500, 502, 503, 504 |

Это фактические defaults, а не автоматическое определение безопасности запроса. Client не выводит idempotency из HTTP method. Для запроса, который нельзя безопасно повторять, вызывающий код обязан передать `idempotent: false`; это относится и к POST с options по умолчанию.

## Текущая retry и timeout семантика

[ResilientHttpClient](../../src/Platform/Http/Client/ResilientHttpClient.php) повторяет выбранные statuses и любой `HttpTransportException`, если включён `idempotent` и остались attempts. Сейчас он не различает временные DNS/connect failures и постоянные TLS/configuration errors.

Один deadline ограничивает attempts и backoff. Если передан внешний deadline, client берёт меньший бюджет из его остатка и `timeoutSeconds`. `CurlTransport` ограничивает request и connect timeout оставшимся временем.

Наследование inbound deadline выполняется явно через [RequestContextAttributes::inheritDeadline](../../src/Platform/Http/RequestContextAttributes.php). Простое создание outbound `HttpRequest` внутри handler само по себе context не подхватывает. Уже заданный options deadline helper сохраняет.

При исчерпании attempts retryable HTTP response возвращается вызывающему коду; конечная transport exception выбрасывается. HTTP status 4xx/5xx сам по себе не превращается в exception. Consumer проверяет status/`isSuccess()`.

Перед повтором client пишет warning с URI, method, upstream, attempt и status/error. Автоматическое логирование каждого финального успеха/ошибки и metrics hooks не реализованы.

## cURL transport и границы

[CurlTransport](../../src/Platform/Http/Client/CurlTransport.php) выполняет синхронный запрос, использует configured redirects и возвращает headers последнего response block. Request и response bodies полностью находятся в памяти.

Реализованы отдельная timeout exception и общая cURL transport exception. Подробной taxonomy DNS/TLS/connect/read сейчас нет.

В текущий контракт не входят streaming API, bounded concurrency, circuit breaker, rate limiting, hedged requests или специальная HTTP/2/HTTP/3 policy. Возможности самой libcurl не равны отдельным гарантиям этого PHP-компонента.

## Потребитель и подтверждённые проверки

[UpdateGeoIPCommand](../../src/Capabilities/Session/Infrastructure/GeoLocation/UpdateGeoIPCommand.php) использует client для загрузки GeoIP archive: задаёт GET, timeout, retry policy и upstream label, затем сохраняет полный body. Наличие этого потребителя не доказывает streaming или проверку внешней сети при каждом quality gate.

[ResilientHttpClientTest](../../tests/Unit/Platform/Http/Client/ResilientHttpClientTest.php) проверяет retries transport/status, отключение повторов и inherited deadline с тестовым transport. Эти unit tests не заменяют проверку реальных TLS, DNS, redirects и libcurl в целевой среде.

## Правила изменения и предложения

Consumer задаёт URI, method, body и намерение retry/timeout; общие retry loops и budget accounting остаются в client. `HttpTransport` сохраняет контракт одной попытки.

Направления подчинены [трём целям проекта](../project/principles.md): максимальная простота, максимальная функциональность и максимальная производительность. Приоритет развития задаёт [общий roadmap](../project/roadmap.md). Ниже — предложения, ещё не реализованный API:

- сначала исключить неявное повторение изменяющих запросов и проверять options до отправки: тест должен доказывать число попыток POST при timeout/503 с defaults и при явно разрешённом повторе;
- различать transport failure classes;
- добавлять typed upstream wrappers и codecs по реальным потребителям;
- вводить metrics и concurrency только под измеренный workload.

Для уже существующей загрузки GeoIP сначала измеряются размер архива и пиковая память текущего buffered path. При необходимости ограниченного download рассматриваются простой лимит размера и запись в stream/file sink; общий streaming framework или concurrency не требуются заранее. Новый путь должен проверять cleanup частичного файла, ошибки записи и расход памяти на растущем body. Это отдельный outbound-контракт, а не отсутствие stream emission у SAPI response adapter.

Расширение retries оценивается вместе с числом upstream requests и полной latency, включая backoff. Повтор постоянной ошибки не является улучшением надёжности или производительности сам по себе. Новая taxonomy, wrapper или adapter должны сокращать код подтверждённого потребителя, а не увеличивать число обязательных концепций минимального HTTP-ядра.

gRPC deadline propagation, PSR-18 adapter и generic SDK factory не являются обещанными следующими этапами.
