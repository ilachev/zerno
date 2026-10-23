# Echo из API-контракта

Тип: `tool-guide`. Статус: `current`. Сверено с кодом: 2026-10-23.

[Контракт](../../contracts/echo.proto) → [generated PHP](../../gen/Generated/Echo) → [ручной endpoint](EchoEndpoint.php) → [явная композиция](config.php). Запросы обслуживает обычный App, без descriptor/генератора в runtime и без БД. Поведение JSON, поддержанные объявления и стоимость определяет [контракт генерации](../../docs/design/protobuf-contract-generation.md).

```bash
php tools/project.php proto:gen
php tools/project.php verify
APP_CONFIG="$PWD/examples/contract-echo/config.php" php -S 127.0.0.1:8082 -t public public/http.php
```

В другом терминале:

```bash
curl -i http://127.0.0.1:8082/api/echo -H 'Content-Type: application/json' --data '{"message":"Привет"}'
curl -i http://127.0.0.1:8082/health
```

POST /api/echo возвращает JSON 200, `/health` — обычный GET/HEAD. Отсутствие message, null и пустая строка дают `{}`. Неизвестные поля/неверный JSON — 400, тело больше 65 536 байтов — 413, неверный media type — 415. Ошибки реализации проходят общий 500. CLI PHP server предназначен для разработки.

Для RoadRunner 3 используйте тот же APP_CONFIG и установленный RR3:

```bash
rr serve -c examples/contract-echo/rr.yaml
```

FPM также получает абсолютный APP_CONFIG снаружи; серверные body cap и Caddy/FPM конфигурация описаны в [ручном примере](../json-echo/README.md). HTTP checks сами создают временные профили и используют endpoint через fixture composition.

В checkout protoc нужен только для изменения контракта и полной повторной компиляции; проверена 26.1. `proto:check` и verify используют сохранённый descriptor и hashes без compiler. В runtime archive отсутствуют contracts и tools: пропустите команды генерации/verify и запускайте готовый PHP с APP_CONFIG.

Локальное сравнение представлений: `php tools/proto-gen/measure.php` (нужен protoc, либо PROTOC=/path/to/protoc). HTTP-сравнение: `php tools/project.php http:check --measure-contract --caddy=/path/to/caddy --rr=/path/to/rr3`. Эти короткие замеры не являются нагрузочным испытанием.
