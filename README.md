# Зерно

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-17.

[![CI](https://github.com/ilachev/zerno/actions/workflows/ci.yml/badge.svg)](https://github.com/ilachev/zerno/actions/workflows/ci.yml)

**Зерно (`zerno`) — небольшое автономное веб-ядро на PHP.** Основа, из которой разработчик собирает серверный сайт или веб-приложение: явные HTTP-маршруты, обработчики, PDO, подготовленные SQL-запросы, транзакции и миграции.

Ядро универсально. При развитии проекта особое внимание уделяется потребностям муниципальных и государственных организаций: понятному устройству, контролируемой поставке и возможности сопровождать сайты в собственной инфраструктуре. Страницы, предметная модель, роли и редакционные процессы определяет приложение.

## Принципы

**Максимальная простота + максимальная полезная функциональность + максимальная производительность.** Оцениваем путь разработчика и сопровождение, законченные веб-сценарии и фактическую стоимость всего запроса. Правила выбора решений и компромиссов закреплены в [принципах развития](docs/project/principles.md).

- **Автономная поставка.** Сторонние PHP-исходники, зависимости генераторов и закреплённые инструменты качества включены в репозиторий. Для запуска, разработки, генерации, проверок и сборки нет шага установки пакетов. Composer, npm и Task не требуются.
- **Явная композиция.** Минимальное HTTP/PDO-приложение подключает необходимую основу; сессии, статистика, protobuf API и runtime integrations выбираются конфигурацией.
- **БД входит в основу.** SQL остаётся исходником запросов; PDO, параметры, транзакции, миграции и типизированная генерация доступны из коробки.
- **Производительность через измерения.** Цель — небольшие накладные расходы PHP. Сравнительный baseline FPM/RoadRunner ещё предстоит получить; измерения и критерии описаны в [roadmap](docs/project/roadmap.md).

Автономность означает отсутствие установки зависимостей проекта, а включённый сторонний код остаётся сторонним. PHP, расширения, СУБД и выбранный сервер являются требованиями платформы. Состав distributions, notices и происхождение файлов описаны в [архитектуре поставки](docs/design/autonomous-core.md).

## Текущее состояние

Работают минимальная HTTP/PDO-композиция, локальный bootstrap, PHP/SAPI и RoadRunner adapters, явные маршруты, SQL execution и миграции. Отдельный protobuf API-профиль сохраняет generated endpoints, OpenAPI, сессии, GeoIP, кеш и статистику API.

Серверный HTML, прикладные формы, вход по учётным данным, CSRF и редакторский интерфейс находятся в плане развития. Первый сквозной пример — [каталог документов](docs/project/document-catalog-example.md): публичные список, карточка и скачивание, затем защищённый поток публикации. Приложение каталога ещё не реализовано.

## Быстрый старт

Для минимального запуска нужны PHP 8.5+ и PDO. Все команды выполняются из корня репозитория.

```bash
git clone https://github.com/ilachev/zerno.git
cd zerno

php -S 127.0.0.1:8080 -t public public/http.php
```

Открыть [локальный health endpoint](http://127.0.0.1:8080/health). GET возвращает `{"status":"ok"}`; HEAD зарегистрирован отдельно. Этот запрос не открывает соединение с БД, не создаёт сессию и не запускает API-статистику, GeoIP или protobuf discovery.

`public/http.php` служит и front controller для PHP-FPM. Требования к веб-серверу и конфигурации описаны в [инфраструктуре](docs/development/infrastructure-and-testing.md). Ту же минимальную композицию можно запустить через установленный RoadRunner:

```bash
rr serve -c .rr.core.yaml
```

Примеры используют один порт; серверы запускаются по очереди.

## Работа с БД

Целевой движок — PostgreSQL с расширением `pdo_pgsql`. Подключение настраивается через `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` и необязательный `DB_SCHEMA`. В минимальной композиции storage создаётся при запросе `Storage` или `SqlExecutor` обработчиком. Существующий SQLite adapter остаётся дополнительным вариантом и не заменяет PostgreSQL-проверки.

Миграции приложения находятся в `src/Migrations`, namespace `App\Migrations`; ядро не запускает их при HTTP-запросе:

```bash
php tools/project.php migrate migrate config/core.php
php tools/project.php migrate rollback config/core.php
```

Текущий `rollback` откатывает все загруженные выполненные миграции. Подробности движков, схем и CLI — в [инфраструктуре и проверках](docs/development/infrastructure-and-testing.md).

## Разработка, проверки и сборка

```bash
php tools/project.php help
php tools/project.php docs:check
php tools/project.php verify

# Focused unit test; PHPUnit arguments are forwarded directly.
php tools/project.php test tests/Unit/Platform/Runtime/AppTest.php

# Generate or check typed SQL.
php tools/project.php sql:gen
php tools/project.php sql:check

# Full checks require a separately prepared PostgreSQL test database.
TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:full

# Runtime archive and optional API reference in dist/.
php tools/project.php build local
```

`verify` проверяет bundled integrity, документацию, стиль, PHPStan, синхронизацию SQL и root unit tests без внешних сервисов. `verify:full` добавляет gates обоих генераторов и PostgreSQL SQL/integration checks. Для инструментов качества нужны расширения PHP, включая DOM/XML, XMLWriter, mbstring и tokenizer; упаковка использует Phar и zlib.

`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` и `TEST_DB_PASSWORD` задают тестовую БД независимо от application `DB_*`. Defaults runner: `localhost:5432/app_test`, пользователь `app`, пароль `password`. БД создаётся заранее; каждый integration run создаёт и удаляет собственную случайную схему.

Сборка создаёт `dist/zerno-local.tar.gz` и `dist/zerno-openapi-local.json`. Runtime-архив содержит приложение, конфигурацию, committed generated artifacts, entrypoints, bootstrap, runtime distribution с notices/manifests и документацию. Тесты, инструменты качества и generator distributions остаются в исходном репозитории. OpenAPI sidecar берётся из `docs/api.swagger.json` и относится к необязательному protobuf API.

CI и release workflow выполняют `verify:full` и сборку. Протоколы не регенерируются в CI; это явная команда разработки. Сборка не устанавливает пакеты. Task остаётся необязательной обёрткой над PHP runner.

## Необязательный protobuf API

API-композиция находится в `config/container.php`, использует `public/index.php` и `.rr.yaml`. Сессии, GeoIP, кеш и API-статистика включены в этом профиле; ему нужны соответствующие сервисы и расширения, включая `bcmath` и `curl`.

```bash
# Existing Compose services and API migrations.
php tools/project.php services:start

# Compose creates app; create app_test once for integration checks.
docker compose exec db-postgres createdb -U app app_test

rr serve -c .rr.yaml

# Regeneration requires protoc and protoc-gen-openapiv2.
php tools/project.php proto:gen:all

php tools/project.php services:stop
```

Docker Compose, RoadRunner, `protoc` и `protoc-gen-openapiv2` — платформенные инструменты соответствующего профиля; команды проекта их не скачивают. Исходящий HTTP-клиент использует расширение `curl`. Карта входов, выходов и границы protobuf находятся в [документации API-контура](docs/architecture/protobuf-and-codegen.md).

## Документация

- [Карта документации](docs/README.md) — текущая архитектура, направления, компоненты и история.
- [Назначение проекта](docs/project/vision.md) и [roadmap](docs/project/roadmap.md) — границы, последовательность и критерии развития.
- [Обзор архитектуры](docs/architecture/overview.md) — работающие профили и компоненты.
- [AGENTS.md](AGENTS.md) и [LLM onboarding](docs/llm-onboarding.md) — правила работы и маршрут чтения для агентов.
- [Машинный инвентарь](docs/documentation-inventory.json) — документы, статусы и связанные исходники.
