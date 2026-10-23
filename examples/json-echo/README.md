# POST JSON с ограниченным body

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-23.

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

## RoadRunner 3

Команды выполняются из корня обычным пользователем. Нужны PHP 8.5+, PDO, sockets и установленный RR3 (проверен 3.0.0).

```bash
rr serve -c examples/json-echo/rr.yaml
```

В другом терминале:

```bash
curl -i http://127.0.0.1:8082/echo \
  -H 'Content-Type: application/json' \
  --data-binary '{"message":"Привет"}'
```

[rr.yaml](rr.yaml) задаёт APP_CONFIG и серверный cap 1 MiB. Тело больше cap получает 413 до вызова PHP и без его JSON/request ID; handler отдельно ограничивает вход 64 KiB. Native RR transport уже материализует payload до handler, поэтому readLimited не делает приём потоковым. /health работает без БД.

## Проверка и стоимость

```bash
php tools/project.php verify
php tools/project.php http:check --rr=/path/to/rr3
```

Матрица RR3 покрывает 65 535/65 536/65 537 байт, ошибки входа, размеры 1 MiB / 1 MiB + 1 с Content-Length и chunked, request ID и следующий запрос того же worker после отказа. [Общий guide](../../docs/development/http-and-baseline.md) описывает весь набор.

Чтение ограничивает накопленную строку и последующий JSON-разбор; вызов проверяется на строках, seekable-файле и non-seekable stream. Файл 1 MiB при лимите 64 KiB останавливается на позиции 65 537. Прямое неограниченное `getContents` прочитало бы весь остаток. Это ресурсная граница, не обещание ускорения: JSON создаёт собственные PHP-объекты, RR предварительно буферизует payload. Без вызова метода циклы чтения не выполняются; стоимость загрузки класса и поставки примера не нулевая.
