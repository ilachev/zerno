# Инфраструктура и тестирование

## Runtime stack

Проект использует:

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
task services:start
task services:stop
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
task migrate
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

- `vendor/autoload.php`

Свойства:

- не требуют внешнюю БД;
- не требуют RoadRunner KV;
- используются в default `task test` и `task verify`.

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
- запускаются отдельным профилем через `task test:integration`.

Для тестов используются отдельные `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASSWORD`. Значения обычных `DB_*` не используются. `task test:integration` и `task sql:check:pg` по умолчанию подключаются к `localhost:5432/app_test`, пользователь `app`, пароль `password`.

Создайте тестовую БД один раз (локальный Docker Compose сам создаёт только runtime БД `app`):

```bash
docker compose exec db-postgres createdb -U app app_test
task test:integration
```

Для отдельного PostgreSQL instance:

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password task verify:integration
```

При прямом запуске PHPUnit `TEST_DB_NAME` необходимо задать явно. Пользователю БД нужны права создавать и удалять свои схемы. Каждый запуск получает отдельную схему, поэтому разные test runs могут использовать одну тестовую БД параллельно.

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
task lint
task phpstan
task test
task sql:check
task sql:check:pg
task test:integration
task test:full
task verify
task verify:integration
task verify:full
```

`task verify` — основной локальный gate. Он запускает:

1. lint
2. phpstan
3. sql artifact consistency check
4. unit tests

`task verify:integration` запускает PostgreSQL-backed SQL validation и integration profile.

`task verify:full` объединяет оба контура и дополнительно запускает полный quality gate самой `sql-gen` тулзы.

## Что должна помнить LLM при изменениях

- Изменения в storage/repository/migrations нужно проверять через `task test:integration` или `task verify:full`.
- Изменения в `.proto` почти всегда требуют регенерации артефактов.
- Изменения в middleware могут затронуть integration tests, даже если unit tests зелёные.
- Из-за RoadRunner нельзя бездумно использовать растущие static caches и состояние в singleton-like сервисах.
- Любая реструктуризация должна сохранять быстрый автономный `task verify`.
