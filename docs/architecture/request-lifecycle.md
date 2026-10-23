# Поток HTTP-запроса

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-23.

Документ описывает существующие композиции приложения. Общая карта компонентов находится в [обзоре архитектуры](overview.md), команды запуска и проверки — в [инфраструктуре и тестировании](../development/infrastructure-and-testing.md).

## Композиция и точка входа

| Композиция | Конфигурация | Маршруты | Pipeline до конечного обработчика |
|---|---|---|---|
| Минимальное HTTP/PDO-ядро | [`config/core.php`](../../config/core.php) | Явные [`config/routes.php`](../../config/routes.php) | RequestContext → ErrorHandler → Routing → RouteHandlerResolver |
| Публичный каталог | [config.php](../../examples/document-catalog/config.php), [application.php](../../examples/document-catalog/application.php) | Собственные GET/HEAD с готовыми handlers | Те же middleware, прикладной HTML ErrorResponse |
| POST JSON | [config.php](../../examples/json-echo/config.php) | GET/HEAD /health, POST /echo | Общие middleware, готовый EchoHandler с лимитом 64 KiB |
| API из .proto | [config.php](../../examples/contract-echo/config.php) | GET/HEAD /health, generated POST /api/echo | Общие middleware, typed endpoint и EchoSendHttp |

GET/HEAD /health сохраняют JSON {"status":"ok"}. Core HTTP граф не создаёт Storage или migration services. Каталог строит Storage и handlers заранее, но PDO открывается при первой SQL-операции: /health/404/405 не зависят от БД.

Единственная HTTP-точка входа — [public/index.php](../../public/index.php). Она загружает [bootstrap.php](../../bootstrap.php), отклоняет PHP ниже [security floor](../development/security.md), выбирает APP_CONFIG (default config/core.php), передаёт RoadRunnerLogger в явную Closure(Logger): App и запускает RoadRunnerRuntime с native transport. Абсолютный APP_CONFIG позволяет не зависеть от рабочего каталога.

[App](../../src/Platform/Runtime/App.php) принимает готовый RequestHandler и делегирует handle/handleRequest, не читая конфигурацию и не запуская server loop. Это граница композиции/тестов; альтернативные HTTP runtimes не поставляются. CLI migrations/seed/generators остаются обычными PHP-процессами.

RoadRunner-путь:

```text
RoadRunner HTTP → RoadRunnerHttpWorker::waitRequest()
               → App::handle(Request)
               → RoadRunnerHttpWorker::respond(response)
               → следующий waitRequest()
```

[`RoadRunnerRuntime`](../../src/Platform/Runtime/RoadRunnerRuntime.php) завершает цикл при null из waitRequest(). Ошибки worker не перехватываются. [RoadRunnerHttpWorker](../../src/Platform/Runtime/RoadRunnerHttpWorker.php) переводит native RR Request в Request ядра без изменения $_SERVER/$_GET/$_POST/$_COOKIE/$_FILES; переносит headers, query, cookies, parsed form, attributes и nested uploads. Server params содержат REMOTE_ADDR, REQUEST_METHOD и исходный RR REQUEST_URI. Native RR parsed form передаётся отдельно от body; JSON сохраняется исходной строкой без автоматического decode.

Перед отправкой адаптер подавляет body для HEAD/1xx/204/304 без чтения/перемотки исходного; для 1xx/204 удаляет Content-Length. Runtime закрывает response body в finally, включая transport error. App::handle ничего не отправляет и не закрывает.

Текущий HTTP check пройден на PHP 8.5.12/RoadRunner 3.0.1 с worker 3.8.0, HTTP 4.2.1, Goridge 4.6.0 и DTO 2.1.0. Нативный RR HttpWorker обслуживает protocol/frames, собственный адаптер — HTTP-типы и чтение Body. RR_HTTP_CHUNK_SIZE=8192 включает чтение с одним chunk lookahead (до двух chunks в адаптере); default 0 материализует body строкой. Seekable body перематывается, non-seekable читается с текущей позиции; запрещённые тела отправляются пустой строкой даже при включённых chunks. После native completion при ошибке генератора адаптер повторно выбрасывает ошибку чтения. Отмена клиента остаётся поведением upstream; длительная устойчивость всех типов потоков не заявляется. Проверка больших regular files входит в http:check.

## Собственные HTTP-типы

Handler имеет контракт handle(Request): Response. Минимальный ответ — new Response(200, ['Content-Type' => 'text/plain'], 'hello'). Для файла передать Body::create($resource) либо Body::fromFile($path) в AttachmentResponse::create(). Сообщения не реализуют PSR-7/17; фабрики и двойные interfaces для каждого concrete type отсутствуют. Это несовместимое изменение PHP API, все потребители репозитория переведены.

Request хранит method, Uri, headers, Body, protocol, server/query/cookie params, parsed body, uploads и attributes. Его with-методы копируют metadata; Response аналогично копирует headers/body reference. Uri сохраняет encoded path/query bytes; decode/route matching не смешиваются. Scheme/host нормализуются в lowercase, стандартные порты 80/443 опускаются. Credentials, fragment, недопустимые escape/control/space/backslash и неподдержанный scheme отвергаются.

Headers принимают string/int array keys: PHP превращает числовое имя вроде "123" в int; на границе имя переводится в string. Числовые поля сохраняются в запросе и ответе через RR3. Headers хранят отдельные значения, ищутся без учёта регистра и отклоняют недопустимые имена/CR/LF/control values. Set-Cookie не склеивается при отправке. Header value — string либо list<string>, status — 100..599. Reason phrase и response protocol выбирает server transport.

Body хранит строку с позицией либо владеет PHP stream resource. read/getContents читают с текущей позиции; преобразование в string перематывает seekable body и читает до конца. Ошибка чтения выбрасывается, не превращается в пустой успех. close идемпотентен; закрытый body не читается, getSize возвращает null. Body нельзя клонировать; копии сообщений разделяют его. String Body не создаёт временный ресурс; файловый payload не материализуется до явного чтения. При замене body вызывающий код отвечает за прежний поток.

`Body::readLimited($maxBytes)` читает остаток без rewind/close и возвращает целую строку либо выбрасывает `LengthException` после не более limit + 1 логических байт. Отрицательный лимит — `InvalidArgumentException`, ошибка I/O/отсутствие прогресса до EOF — `RuntimeException`. Заголовки и getSize не используются для доверия размеру. PHP stream wrapper может иметь собственное предварительное чтение. Вызов явный: общего лимита для всех маршрутов и автоматического JSON-разбора нет. [POST-пример](../../examples/json-echo/README.md) задаёт 64 KiB, закрывает input в finally и сам определяет JSON/ошибки 400/413/415.

Это предел чтения и разбора в handler, а не приёма до PHP. Входной RR payload уже материализован транспортом; до него нужен отдельный серверный cap. POST-пример содержит RR3 конфигурацию с cap 1 MiB. RR и приложение имеют разные буферы и владельцев ресурсов.

UploadedFile лениво открывает body и сохраняет error/размер/непроверенные client name/media type. Ошибочный или перемещённый upload нельзя читать/перемещать. moveTo закрывает открытый body и разрешён один раз: используется rename временного файла, переданного RR; режима move_uploaded_file нет. Имя клиента не выбирает storage path. Пределы загрузки задаются RR и приложением; общий upload policy/validation framework не добавлен.

## Общие middleware

[`Pipeline`](../../src/Platform/Http/Middleware/Pipeline.php) вызывает middleware в порядке массива конфигурации. Каждый следующий слой получает переданную ему копию Request; response возвращается через вызвавшие слои в обратном порядке. Изменение attributes во внутреннем слое не меняет request, сохранённый внешним слоем.

[`RequestContextMiddleware`](../../src/Platform/Http/Middleware/RequestContextMiddleware.php) выполняется первым во всех перечисленных композициях:

- сохраняет непустой `X-Request-ID` клиента или генерирует новый;
- записывает `requestContext` и `requestId` в attributes через `RequestContextAttributes`;
- для `RoadRunnerLogger` обновляет его mutable `requestId`;
- добавляет к возвращённому response `X-Request-ID`, `X-Response-Time`, `Server-Timing`.

RequestContext содержит request ID; неиспользуемый request deadline удалён вместе с outgoing HTTP client.

[`ErrorHandlerMiddleware`](../../src/Platform/Http/Middleware/ErrorHandlerMiddleware.php) оборачивает следующие слои: логирует полный `Throwable` строкой и создаёт общий `500` через выбранный `ErrorResponse`. Он не оборачивает предшествующий код `RequestContextMiddleware`, создание приложения, преобразование входящего запроса или transport emission. Детали исключения остаются в логах; доступ и хранение логов определяет эксплуатационный профиль.

## Представление HTTP-ошибок

[`ErrorResponse`](../../src/Platform/Http/ErrorResponse.php) — один метод `error(Error $error, int $status): Response`. Он создаёт ответ с переданным status, заголовками и телом. Core передаёт только публичную ошибку из [`Error`](../../src/Platform/Error/Error.php), без `Throwable`, trace или других внутренних деталей. `RoutingMiddleware` использует его для 404/405 и сам добавляет `Allow` для 405; `ErrorHandlerMiddleware` — для 500. Готовые ответы handlers, включая ошибки, не преобразуются.

В [config/core.php](../../config/core.php) явно создаётся JsonResponse. Для другого формата реализовать ErrorResponse в приложении и передать тот же объект в RoutingMiddleware($router, $errors) и ErrorHandlerMiddleware($errors, $logger). Он возвращает новый response/body для каждого вызова, сохраняя status. Templates, язык сообщений и cache policy выбирает приложение.

Полный потребитель — [`конфигурация каталога`](../../examples/document-catalog/config.php) и его [`Errors`](../../examples/document-catalog/src/Errors.php). Каталог использует общий core pipeline и непосредственно строит HTML, без промежуточного JSON и распознавания `Content-Type`. `Html` и тексты страниц остаются локальными.

Выбор представления выполняется композицией один раз при построении pipeline. Core создаёт JsonResponse; каталог создаёт Errors/Html обычными конструкторами, шаблоны читаются только при создании страницы. Успешный ответ handler не вызывает `error()`. Формат не выбирается по `Accept`, и дополнительных БД/сетевых обращений механизм не добавляет. Числовая стоимость и ускорение не измерены; сокращение промежуточной работы не объявляется доказанным выигрышем latency.

HEAD обрабатывает RoadRunner adapter: прямой App::handle() возвращает обычный response с телом, runtime его не читает и не отправляет. Ошибки native request conversion и bootstrap находятся вне этого контракта. Ошибка логирования или создания самого ответа 500 выходит наружу; автоматического запасного renderer нет. Renderer не должен хранить состояние текущего запроса между вызовами.

Unit tests проверяют generic 500 и логирование, сохранение готового ответа, HTML без загрузки `JsonResponse` и последовательность ошибок/health. `http:check` повторяет wire-матрицу для JSON и HTML на RR3, включая 404/405/500, `Allow`, HEAD и продолжение работы worker после исключения.

## Файловый attachment и владение потоком

[`AttachmentResponse::create()`](../../src/Platform/Http/AttachmentResponse.php) создаёт Response со status 200, Content-Type (default `application/octet-stream`), явным Content-Length, Content-Disposition attachment и `nosniff`. Он принимает уже открытый stream, имя и проверенный вызывающим кодом размер; не открывает файл, не читает и не перемещает body. Путь разработчика — проверить доступ и файл, открыть новый поток, передать его helper и вернуть response:

```php
return AttachmentResponse::create(
    $body,
    $originalName,
    $verifiedSize,
    contentType: 'application/pdf',
    fallbackFilename: 'document-' . $id . '.pdf',
)->withHeader('Cache-Control', 'no-store');
```

В заголовке всегда два имени: ASCII `filename` и UTF-8 `filename*`. В fallback остаются только ASCII буквы, цифры, `.`, `_`, `-`; остальные байты заменяются `_`, крайние точки убираются, пустое имя заменяется на `download`. UTF-8 имя очищается от Unicode control/format characters, `/` и `\` заменяются `_`, крайние точки и пробелы убираются; затем всё имя кодируется `rawurlencode`. Пустой или невалидный UTF-8 заменяется fallback. Имя служит подсказкой скачивания, не путём storage. Отрицательный размер и невалидные header values отклоняются. Размер не пересчитывается и соответствие MIME содержимому не проверяется.

Helper принимает владение stream при вызове: если создание ответа завершается исключением, он закрывает body. После успеха владение переходит вызывающему коду до передачи transport. Прямой `App::handle()` ничего не закрывает: его пользователь должен отправить ответ или закрыть body сам. RoadRunnerRuntime закрывает отправленный body и при ошибках. Handler возвращает свежий stream на каждый запрос и не хранит его в сервисе. Замена body через `withBody()` сама по себе не закрывает старый: заменивший его код обязан сделать это явно.

[`DownloadHandler`](../../examples/document-catalog/src/DownloadHandler.php) — полный потребитель. Document, published lookup, private FileStore, проверка regular file/размера, PDF-only и no-store остаются в приложении. Для HEAD handler проверяет файл, создаёт те же headers и закрывает исходный stream без чтения, возвращая пустой body. Для общего ответа HEAD также подавляет transport; выбирать метод внутри helper не требуется. Range, conditional requests, HTTP cache и upload в контракт не входят. Файл должен оставаться неизменным между проверкой и завершением отправки.

Без вызова helper нет новой регистрации сервисов, middleware или файлового I/O. При вызове выполняются обработка имени, создание headers/response и передача того же body; цена обработки имени зависит от длины имени, не от размера файла. Размер проверяется приложением. RR требует явного RR_HTTP_CHUNK_SIZE=8192 для отправки частями; default 0 материализует body. Seekable body перематывается, non-seekable читается с текущей позиции. Собственный adapter сохраняет read failure и повторно выбрасывает его после native completion. Закрытие ресурса не гарантирует завершение передачи при изменении файла или отмене клиента.

Проверки с логированием, измеренная стоимость и границы выводов приведены в [отчёте файловой итерации](../history/2026-10-18-file-responses.md).

## Routing и конечный handler

[`Router`](../../src/Platform/Routing/Router.php) строит таблицы маршрутов и компилирует parameter patterns при создании. Literal-части пути экранируются для regex. Dispatch сначала ищет статический путь для запрошенного метода, затем параметризованные пути этого метода в порядке регистрации. Статический путь другого метода не блокирует подходящий параметризованный маршрут. Повторная регистрация method/path заменяет handler, сохраняя позицию маршрута.

Статические пути допускают прежний fallback: `/item/` и `/item///` могут выбрать `/item`; точное совпадение имеет приоритет. Корень остаётся `/`, параметризованные пути требуют точного trailing slash. HEAD → GET автоматически не подставляется.

Если path существует только для других методов, `RouteResult::getAllowedMethods()` возвращает объединение методов всех совпавших маршрутов без дубликатов, в порядке регистрации методов. `RoutingMiddleware` отвечает `405` и `Allow`. Для неизвестного path ответ — `404` без `Allow`. Представление создаёт выбранный `ErrorResponse`; default JSON — `{"error":"Method not allowed"}` / `{"error":"Not found"}`. На этих ветках внутренние middleware и handler не вызываются. Метод `getHandler()` больше не используется для передачи списка разрешённых методов.

Для найденного маршрута [`RoutingMiddleware`](../../src/Platform/Http/Middleware/RoutingMiddleware.php) передаёт дальше attributes `RouteResult::class`, `routeParams`, `handler`. [`RouteHandlerResolver`](../../src/Platform/Http/RouteHandlerResolver.php) проверяет готовый объект RequestHandler и вызывает handle(). Строки имён классов не разрешаются и не конструируются.

Router получает список RouteEntry непосредственно из функции сборки; core использует config/routes.php. Автоматический fallback HEAD → GET не используется: HEAD зарегистрирован отдельной записью.

Явная команда `php tools/project.php http:check` проверяет эти ветки и ограниченную матрицу transport behavior через настоящий RR3: HEAD, 204/304, повторные headers/cookies, query и последовательные запросы одного worker. Запуск и границы проверки описаны в [HTTP guide](../development/http-and-baseline.md); в default `verify` серверы не запускаются.

## Диагностика

[HttpLoggingMiddleware](../../src/Platform/Http/Middleware/HttpLoggingMiddleware.php) добавляется явно после routing. Он пишет query params перед handler, после — status, body_size и body_seekable. Payload не читается; поток и позиция сохраняются. Если запись response metadata бросает исключение, body закрывается до передачи ошибки внешнему ErrorHandler. Это не полный access log: routing 404/405 и исключения handler не проходят обе записи. Размер/seekability узнаются через metadata stream; неизвестный размер — null.

## Время жизни состояния

Объекты создаёт функция сборки; их владельцем является App/pipeline на время worker RR. Контейнера, кеша autowiring и автоматического request scope нет. Request context и routing attributes остаются в конкретном request; удерживать их в долгоживущих сервисах нельзя.

Граф строится один раз на worker RR. Готовые handlers используют общие repository/Storage, его PDO появляется при первой операции и затем переиспользуется вместе с bounded statement cache. Ленивость PDO не означает отсутствия стоимости создания остальных PHP objects.
