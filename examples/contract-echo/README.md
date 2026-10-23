# Echo из API-контракта

Тип: `tool-guide`. Статус: `current`. Сверено с кодом: 2026-10-23.

[Контракт](../../contracts/echo.proto) → [generated PHP](../../gen/Generated/Echo) → [ручной endpoint](EchoEndpoint.php) → [явная композиция](config.php). Запросы обслуживает обычный App, без descriptor/генератора в runtime и без БД. Поведение JSON, поддержанные объявления и стоимость определяет [контракт генерации](../../docs/design/protobuf-contract-generation.md).

```bash
php tools/project.php proto:gen
php tools/project.php verify
rr serve -c examples/contract-echo/rr.yaml
```

В другом терминале:

```bash
curl -i http://127.0.0.1:8082/api/echo -H 'Content-Type: application/json' --data '{"message":"Привет"}'
curl -i http://127.0.0.1:8082/health
```

POST /api/echo возвращает JSON 200, `/health` — обычный GET/HEAD. Отсутствие message, null и пустая строка дают `{}`. Неизвестные поля/неверный JSON — 400, тело больше 65 536 байтов — 413, неверный media type — 415. Ошибки реализации проходят общий 500.

RoadRunner 3 — единственный HTTP runtime; rr.yaml задаёт APP_CONFIG и cap 1 MiB до PHP. Лимит adapter 64 KiB относится к дальнейшему чтению/decode. HTTP checks сами создают временный RR-профиль и используют endpoint через fixture composition.

В checkout protoc нужен только для изменения контракта и полной повторной компиляции; проверена 26.1. `proto:check` и verify используют сохранённый descriptor и hashes без compiler. В runtime archive отсутствуют contracts и tools: пропустите команды генерации/verify и запускайте готовый PHP с APP_CONFIG.

Локальное сравнение представлений: `php tools/proto-gen/measure.php` (нужен protoc, либо PROTOC=/path/to/protoc). HTTP-сравнение: `php tools/project.php http:check --measure-contract --rr=/path/to/rr3`. Эти короткие замеры не являются нагрузочным испытанием.
