# Инфраструктура и тестирование

## Runtime stack

Минимальный HTTP/PDO runtime запускается через PHP-FPM или обычный SAPI без сессий и API-интеграций. Дополнительная API-композиция использует RoadRunner и protobuf. PHP-зависимости и инструменты качества включены в репозиторий, менеджер пакетов и установка Task не требуются.

Платформа и включённые инструменты:

- PHP 8.5+
- RoadRunner HTTP worker
- PostgreSQL
- Redis через RoadRunner KV
- PHPUnit
- PHPStan
- PHP-CS-Fixer

## Конфигурация среды

### RoadRunner

Файл `.rr.yaml` определяет:

- запуск worker через `php public/index.php`;
- HTTP listener на `:8080`;
- RPC listener на `127.0.0.1:6001`;
- metrics/status endpoints;
- KV storage (`local-memory`, `redis`).

### Storage adapters

Основная конфигурация лежит в `config/storage.php`.

Runtime использует `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` и `DB_SCHEMA` (по умолчанию `public`). Имя схемы интерпретируется как один PostgreSQL identifier, а не как список `search_path`.

По умолчанию используется:

- `pgsql` как основной runtime adapter
- `sqlite` как лёгкий adapter для отдельных изолированных сценариев и тестов

Базовый quality gate шаблона не должен требовать поднятые внешние сервисы, но сам backend по умолчанию ориентирован на PostgreSQL.

### Redis

Используется как backend для RoadRunner KV.

Конфиг:

- `.rr.yaml`
- `config/cache.php`

Для deployment-aware cache invalidation используется `namespace_seed` в `config/cache.php` или `CACHE_NAMESPACE_SEED` из окружения.
Это позволяет менять namespace кеша между релизами без глобального `clear()` shared backend-а на старте воркеров.

## Docker services

`docker-compose.yml` поднимает:

- `db-postgres` для основного PostgreSQL runtime profile
- `redis`

Стандартный lifecycle:

```bash
php tools/project.php services:start
php tools/project.php services:stop
```

`services:start` также ждёт готовности БД и запускает миграции.

## Схема БД

### `sessions`

Назначение:

- хранение анонимных и пользовательских сессий;
- session payload в JSON-compatible field;
- индексы под поиск по `user_id`, `expires_at`, `payload->>'ip'`, `payload->>'fingerprint'`.

### `api_stats`

Назначение:

- запись вызовов API с привязкой к session;
- хранение route, method, status code, execution time, request timestamp.

### `users`

Сейчас это минимальная таблица для будущих auth/user сценариев.

## Миграции

Запуск:

```bash
php tools/project.php migrate
```

CLI wrapper:

- `bin/migrate.php`

Реализация миграций:

- `src/Platform/Storage/Migration/*`

## Тесты

### Unit tests

Покрывают:

- domain services;
- middleware;
- router;
- DI container;
- hydrator;
- repositories;
- route generation.

Bootstrap:

- `tests/bootstrap.php`

Свойства:

- не требуют внешнюю БД;
- не требуют RoadRunner KV;
- используются в default `php tools/project.php test` и `php tools/project.php verify`.

### Integration tests

Проверяют:

- реальное приложение через `App::handleRequest()`;
- PostgreSQL-backed flow;
- session creation/validation/fingerprinting;
- geolocation integration;
- api stats recording.

Bootstrap:

- `tests/Integration/bootstrap.php`

Свойства:

- требуют PostgreSQL profile;
- запускаются отдельным профилем через `php tools/project.php test:integration`.

Для тестов используются отдельные `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASSWORD`. Значения обычных `DB_*` не используются. `php tools/project.php test:integration` и `php tools/project.php sql:check:pg` по умолчанию подключаются к `localhost:5432/app_test`, пользователь `app`, пароль `password`.

Создайте тестовую БД один раз (локальный Docker Compose сам создаёт только runtime БД `app`):

```bash
docker compose exec db-postgres createdb -U app app_test
php tools/project.php test:integration
```

Для отдельного PostgreSQL instance:

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:integration
```

При прямом запуске `php tools/phpunit.php -c phpunit.integration.xml` `TEST_DB_NAME` необходимо задать явно. Пользователю БД нужны права создавать и удалять свои схемы. Каждый запуск получает отдельную схему, поэтому разные test runs могут использовать одну тестовую БД параллельно.

## Как устроены integration tests

Bootstrap в `tests/Integration/bootstrap.php`:

- проверяет отдельную тестовую конфигурацию до обращения к БД;
- создаёт новую уникальную схему `phpunit_<random>`;
- подключает runtime только к этой схеме через `DB_SCHEMA`;
- создаёт единый `App`;
- прогоняет миграции и сохраняет shared app в `TestAppFactory`;
- удаляет только созданную им схему при завершении процесса, включая ошибку bootstrap.

Bootstrap не удаляет таблицы `public` и не выбирает существующую схему для очистки. `sql:check:pg` независимо создаёт и удаляет собственную схему `sql_gen_check_<random>` в той же тестовой БД. Regression tests проверяют сохранность sentinel-таблицы в `public` и изоляцию соседних test runs.

Следствие:

- интеграционные тесты не мокают runtime;
- tests ближе к реальному application flow;
- состояние базы подготавливается централизованно.

## Команды проверки качества

```bash
php tools/project.php lint
php tools/project.php phpstan
php tools/project.php test
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php test:integration
php tools/project.php test:full
php tools/project.php verify
php tools/project.php verify:integration
php tools/project.php verify:full
```

`php tools/project.php verify` — основной локальный gate. Он запускает:

1. integrity hashes включённых зависимостей и инструментов
2. lint
3. phpstan
4. sql artifact consistency check
5. unit tests

`php tools/project.php verify:integration` запускает PostgreSQL-backed SQL validation и integration profile.

`php tools/project.php verify:full` объединяет оба контура и дополнительно запускает quality gates `sql-gen` и `protoc-php-gen`.

## Что должна помнить LLM при изменениях

- Изменения в storage/repository/migrations нужно проверять через `php tools/project.php test:integration` или `php tools/project.php verify:full`.
- Изменения в `.proto` почти всегда требуют регенерации артефактов.
- Изменения в middleware могут затронуть integration tests, даже если unit tests зелёные.
- Из-за RoadRunner нельзя бездумно использовать растущие static caches и состояние в singleton-like сервисах.
- Любая реструктуризация должна сохранять быстрый автономный `php tools/project.php verify`.
