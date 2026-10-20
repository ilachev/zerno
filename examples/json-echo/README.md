# POST JSON с ограниченным body

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-20.

Небольшой потребитель [`Body::readLimited`](../../src/Platform/Http/Body.php): один [`EchoHandler`](EchoHandler.php), явный маршрут и [композиция](config.php). БД, контейнер, DTO, общий JSON-parser или validation framework не нужны. Пример включён в runtime-архив; это ручной HTTP-сценарий, не protobuf-генерация.

## Контракт операции

`POST /echo` принимает UTF-8 JSON-объект с единственным строковым полем `message`, включая пустую строку. Успех — `200 application/json` с тем же полем. Неизвестные поля, другой корневой тип, неверный тип поля, пустой body, некорректные JSON/UTF-8 дают 400. Используется штатный `json_decode` с depth 32: повторяющиеся ключи имеют его обычную семантику последнего значения.

| Условие | Ответ обработчика |
| --- | --- |
| Некорректный JSON или структура | `400 {"error":"Invalid JSON request"}` |
| Body больше 65 536 байт | `413 {"error":"Request body too large"}` |
| Нет единственного `Content-Type: application/json` или передана другая кодировка содержимого | `415 {"error":"Unsupported media type"}` |
| Ошибка I/O | Общий 500 через ErrorHandler; подробности в logger |

Media type сравнивается без учёта регистра, параметры после `;` игнорируются; JSON всегда декодируется как UTF-8. `Content-Encoding` отсутствует либо равен `identity`: распаковки нет. Сначала проверяется тип, затем размер, затем JSON и поля. Поэтому большой `text/plain` получает 415. `Content-Length` не определяет лимит обработчика: проверяются прочитанные байты. Handler закрывает input body в `finally`, в том числе при раннем отказе. Response body закрывает транспорт.

В ядре добавлен только `readLimited(int $maxBytes): string`: чтение с текущей позиции без rewind/close, `LengthException` при превышении, `InvalidArgumentException` для отрицательного лимита, `RuntimeException` для ошибки или отсутствия прогресса до EOF. Нулевой лимит принимает только пустой остаток. При превышении потребляется не больше limit + 1 логических байт, частичный результат не возвращается. PHP stream wrapper может предварительно буферизовать дополнительные байты. Метод синхронный; таймаут чтения задаётся транспортом.

## Локальный SAPI

Команды выполняются из корня обычным пользователем. Нужны PHP 8.5+ и стандартные требования [проекта](../../README.md).

```bash
APP_CONFIG="$PWD/examples/json-echo/config.php" php -S 127.0.0.1:8082 -t public public/http.php
```

В другом терминале:

```bash
curl -i http://127.0.0.1:8082/echo \
  -H 'Content-Type: application/json' \
  --data-binary '{"message":"Привет"}'
```

Встроенный PHP-сервер подходит для разработки; этот запуск проверяет лимит обработчика, а не ограничение входящего трафика до PHP.

## RoadRunner 3

```bash
rr serve -w "$PWD" -c examples/json-echo/rr.yaml
```

[`rr.yaml`](rr.yaml) задаёт серверный предел 1 MiB отдельно от 64 KiB операции. В [конфигурации RR3](https://github.com/roadrunner-server/docs/blob/release/v3/http/http.md) `max_request_size` измеряется в MiB; 0 выбирает default, а не запрет body. В проверенном RR 3.0.0 превышение даёт 413 до PHP и без его JSON/request ID. Native RR transport уже материализует входной payload до handler; `readLimited` не делает этот участок потоковым.

## PHP-FPM с HTTP front

Готовые [`php-fpm.conf`](php-fpm.conf) и [`Caddyfile`](Caddyfile) проверены на PHP 8.5.9 / Caddy 2.11.4. Это явный локальный профиль; executable Caddy — платформа, не PHP dependency. Создайте `var` и запустите в двух терминалах:

```bash
mkdir -p var
APP_CONFIG="$PWD/examples/json-echo/config.php" php-fpm -F -p "$PWD" -y examples/json-echo/php-fpm.conf
```

```bash
ZERNO_ROOT="$PWD" caddy run --config examples/json-echo/Caddyfile --adapter caddyfile
```

Запрос из предыдущего примера направьте на `http://127.0.0.1:18083/echo`. Front принимает HTTP только на loopback и всегда передаёт его в `public/http.php`; прямой доступ к файлам не включён.

Front заранее отклоняет Content-Length свыше 1 MiB и отдельно ограничивает фактическое чтение body до 1 MiB, время чтения — до 10 s. Ранний отказ по известной длине нужен до FastCGI: одного ограничивающего reader недостаточно, поскольку Caddy может начать передачу в FPM и прервать её после ошибки чтения. Для chunked без Content-Length FastCGI требует определить длину: front буферизует body с пределом 1 MiB + 1 байт; дополнительный байт нужен для определения EOF на точной границе. PHP `post_max_size`/`upload_max_filesize` не заменяют ограничение сырого JSON до PHP. О серверном механизме: [request_body](https://caddyserver.com/docs/caddyfile/directives/request_body), [request_buffers](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy#streaming).

В Caddy 2.11.4 тело свыше 1 MiB с Content-Length получает 413, chunked — 411: после ошибки ограниченного чтения front не может задать FastCGI length. Оба отказа происходят до PHP; их формат не является JSON-контрактом handler. Это проверенное ограничение данного профиля, без подмены серверных ошибок в приложении. Ровно 1 MiB доходит до handler и получает его 413; ровно 64 KiB принимается. Для другого front проверять эти границы заново.

## Проверка и стоимость

```bash
php tools/project.php verify
php tools/project.php http:check --rr=/path/to/rr3 --caddy=/path/to/caddy
```

Без `--caddy` FPM проверяется напрямую через FastCGI, RR — через HTTP; флаг добавляет настоящие HTTP/FPM проверки с профилем выше. Матрица покрывает 65 535/65 536/65 537 байт, некорректные входы, размеры 1 MiB / 1 MiB + 1 с длиной и chunked, ранний отказ через Expect: 100-continue, request ID и следующий запрос в том же worker после каждого отказа. [Общий guide](../../docs/development/http-and-baseline.md) описывает весь набор.

Чтение ограничивает накопленную строку и последующий JSON-разбор; вызов проверяется на строках, seekable-файле и non-seekable stream. Файл 1 MiB при лимите 64 KiB останавливается на позиции 65 537. Прямое неограниченное `getContents` прочитало бы весь остаток. Это ресурсная граница, не обещание ускорения: JSON создаёт собственные PHP-объекты, RR предварительно буферизует payload, Caddy/FPM профиль тоже имеет отдельный буфер. Без вызова метода циклы чтения не выполняются; стоимость загрузки класса и поставки примера не нулевая.
