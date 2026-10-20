# Зерно · Zerno

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-20.

Небольшая автономная PHP-основа для сайтов и веб-приложений: HTTP, явные маршруты и обработчики, PostgreSQL/PDO и SQL. Разработчики муниципальных и государственных сайтов — приоритетная аудитория; назначение ядра универсальное.

Цели — простота использования и сопровождения, полезная функциональность и измеряемая эффективность. [Принципы](docs/project/principles.md) применяются совместно. Исполнение остаётся обычным PHP: маршрут → handler → SQL/поток → response.

## Конкретный состав

- Собственные Request/Response/Body без Nyholm и PSR-пакетов, маршруты, middleware и сборка объектов через обычные PHP-конструкторы и функции. Контейнера и autowiring нет.
- JSON/прикладные HTML-ошибки через ErrorResponse; файловый AttachmentResponse с безопасными заголовками.
- Request ID, время обработки и логирование метаданных без чтения тела ответа.
- PostgreSQL PDO, параметры SQL, транзакции и явные CLI-миграции.
- SQL-генератор: типизированные queries/rows, явный LIMIT, проверки PostgreSQL. Генератор не загружается при запросе.
- Два способа исполнения одного RequestHandler: PHP/SAPI/FPM и RoadRunner.

Сессии, GeoIP/fingerprints, ApiStats, cache-модули, старый protobuf API/генератор, hydration/mapping и исходящий HTTP-клиент удалены. Папки optional, совместимых заглушек и старого API-профиля нет. [Граница продукта](docs/project/vision.md) описывает правило включения новых возможностей; [история очистки](docs/history/2026-10-20-core-scope.md) — удалённый состав и несовместимость.

API из `.proto` — принятое направление: генерация DTO, endpoint contracts, HTTP adapters и маршрутов. Старый контур удалён; [первый сквозной этап](docs/design/protobuf-contract-generation.md) ещё предстоит реализовать.

## Запуск

Требуются PHP 8.5+ и PDO; для работы с БД — pdo_pgsql и PostgreSQL. PHP-исходники и инструменты уже поставляются: установка пакетов не нужна.

```bash
php -S 127.0.0.1:8080 -t public public/http.php
```

[GET /health](http://127.0.0.1:8080/health) возвращает `{"status":"ok"}`; HEAD зарегистрирован отдельно. Запрос не открывает БД и не создаёт cookies. Встроенный PHP-сервер предназначен для локальной разработки; обычная SAPI/FPM точка входа — public/http.php.

Для RoadRunner с установленным executable:

```bash
php tools/project.php run
```

[.rr.yaml](.rr.yaml) запускает ту же [функцию сборки](config/core.php), что SAPI/FPM: `Closure(Logger): App`. Точка входа выбирает logger, функция явно создаёт граф через new. APP_CONFIG задаёт этот файл; отдельных RR service providers/профилей нет. Маршруты хранят готовые RequestHandler objects. Примеры применения — [обычные маршруты](config/routes.php) и [каталог документов](examples/document-catalog/README.md).

## База данных и генерация

[config/storage.php](config/storage.php) читает DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD и DB_SCHEMA. Поддерживается PostgreSQL; выбора STORAGE_ENGINE нет. Файл возвращает PostgreSQLStorage, соединение открывается при первой SQL-операции/транзакции, а не при создании объекта. HTTP startup никогда не запускает миграции.

```bash
php tools/project.php migrate migrate
php tools/project.php sql:gen
```

По умолчанию миграции ядра ищутся в пустом прикладном src/Migrations. Второй аргумент — каталог миграций: для примера передайте examples/document-catalog/migrations. HTTP-конфигурация CLI больше не передаётся. [SQL inputs](sql/queries/catalog.sql) и [schema source](sql/schema.sql) дают отдельный проверочный пример генератора на структуре каталога. Приложение сохраняет прямой SQL с published literal; runtime DDL находится в его миграции. Подробности — в [SQL-контракте](docs/design/sql-gen-product.md).

## Проверки и поставка

```bash
php tools/project.php help
php tools/project.php verify
php tools/project.php verify:full
php tools/project.php http:check
php tools/project.php build local
```

verify не требует внешних сервисов. verify:full добавляет SQL-tool checks и настоящую PostgreSQL test database через отдельные TEST_DB_*; http:check запускает FPM/RR явно. [Инфраструктура и тестирование](docs/development/infrastructure-and-testing.md) описывают платформу и изоляцию. Runtime-архив содержит готовый код и SQL-artifacts, исключая инструменты генерации, качества и тесты.

Внутри bundled RoadRunner HTTP остаются требуемые им protobuf/DTO libraries. Это зависимость транспорта, а не API приложения. Происхождение, notices и hashes описаны в [поставке](docs/design/autonomous-core.md).

Для агентов: [AGENTS.md](AGENTS.md), [правила кода](docs/development/coding-guidelines.md), [onboarding](docs/llm-onboarding.md), [индекс документации](docs/README.md). Последующие ограниченные задачи — в [roadmap](docs/project/roadmap.md). Лицензия и политика публичных релизов собственного кода пока не определены.
