# Инфраструктура и тестирование

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-17.

Архитектурные границы описаны в [обзоре](../architecture/overview.md), точный порядок middleware — в [потоке запроса](../architecture/request-lifecycle.md). Источник команд — [`tools/project.php`](../../tools/project.php), а не прежние Composer/Task инструкции.

## Поставка и требования платформы

PHP-библиотеки, генераторы и инструменты качества включены в репозиторий. Для запуска, сборки и проверок нет шага установки пакетов; Composer, npm и Task не требуются. Наличие оригинальных `composer.json` внутри зафиксированных upstream sources не означает их использование для загрузки или установки.

| Контур | Что требуется вне включённого PHP-кода |
|---|---|
| Минимальное HTTP/PDO-ядро | PHP 8.5+, PDO; обычный SAPI/PHP-FPM либо отдельный `rr` executable |
| Работа с основной БД | PostgreSQL и `pdo_pgsql`; соединение нужно только при разрешении `Storage` |
| API GeoIP/исходящий HTTP | Расширения соответствующих возможностей; IP2Location объявляет `bcmath`/`curl`, HTTP transport использует curl |
| API cache через RR KV | `rr` с RPC/KV и настроенный Redis backend; при сбое есть локальный fallback |
| Проверки качества | PHP CLI с расширениями включённых PHAR; provenance находится в `third_party/tools/*/provenance.json` |
| Генерация protobuf SDK/endpoints | Установленный `protoc` |
| Генерация OpenAPI | `protoc` и `protoc-gen-openapiv2` |
| Compose-сервисы / load tests | Docker Compose / `k6` соответственно |

Базовые требования runtime зафиксированы в [`third_party/runtime/manifest.json`](../../third_party/runtime/manifest.json). PHP-библиотека RoadRunner не поставляет executable `rr`; его версия учитывается отдельно. CI сам подготавливает PHP и PostgreSQL как платформу, без установки PHP-пакетов проекта.

[`bootstrap.php`](../../bootstrap.php) проверяет PHP/PDO и регистрирует локальный [`Autoloader`](../../bootstrap/Autoloader.php) по включённой runtime map. [`tests/bootstrap.php`](../../tests/bootstrap.php) добавляет dev bootstrap с типами PHPUnit и namespace `Tests`. Каждый генератор использует собственные bootstrap и dependency graph в отдельном PHP-процессе: версии библиотек разных контуров нельзя объединять одним общим autoload.

## Композиции HTTP

| Запуск | Конфигурация по умолчанию | Особенности |
|---|---|---|
| [`public/http.php`](../../public/http.php) | [`config/core.php`](../../config/core.php) | SAPI/FPM, явные GET/HEAD `/health`, без API capabilities |
| [`public/index.php`](../../public/index.php) с [`.rr.core.yaml`](../../.rr.core.yaml) | RR config задаёт [`config/core-roadrunner.php`](../../config/core-roadrunner.php) | То же ядро, один worker, HTTP `127.0.0.1:8080`, без RPC/KV |
| `public/index.php` с [`.rr.yaml`](../../.rr.yaml) | [`config/container.php`](../../config/container.php) | Прежнее protobuf API, session/statistics/GeoIP/cache; HTTP `0.0.0.0:8080`, три workers |

Оба entrypoints принимают `APP_CONFIG`; рекомендуется абсолютный путь. Для локальной проверки SAPI:

```bash
php -S 127.0.0.1:8080 -t public public/http.php
```

Для FPM веб-сервер направляет запросы в `public/http.php`, сохраняет исходный request URI и публикует только каталог `public`. Scheme/host не берутся автоматически из proxy headers; политика доверия reverse proxy не настроена общим ядром.

`.rr.yaml` дополнительно задаёт RPC `127.0.0.1:6001`, status `127.0.0.1:2114`, metrics `127.0.0.1:2112`, KV `local-memory` и `redis`. Это конфигурация API-приложения с development logging, а не обязательные сервисы ядра.

API cache выбирает RR KV storage `redis` через [`config/cache.php`](../../config/cache.php). `CACHE_NAMESPACE_SEED` читается этой конфигурацией из `$_SERVER`; seed меняет namespace релиза без глобального wipe на старте. In-memory fallback ограничен по размеру, но не является общим кешем workers. Под PHPUnit используется testing fallback, поэтому integration gate не проверяет настоящий RPC/KV/Redis.

## База данных и миграции

PostgreSQL — контрольный движок для разработки, integration tests и production-поведения SQL. [`config/storage.php`](../../config/storage.php) по умолчанию выбирает `STORAGE_ENGINE=pgsql` и использует `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SCHEMA` (по умолчанию `public`). `DB_SCHEMA` экранируется как один identifier; это не произвольный список `search_path`.

Существующий `SQLiteStorage` остаётся опциональным адаптером (`STORAGE_ENGINE=sqlite`, `SQLITE_DATABASE`). Он не заменяет PostgreSQL в integration gate и не подтверждает совместимость PostgreSQL SQL, JSONB, индексов или транзакций.

В минимальном ядре регистрация `StorageServiceProvider` не открывает PDO. Соединение создаётся конструктором storage при первом разрешении `Storage`/`SqlExecutor`. HTTP `/health` проходит без БД. В API-композиции построение session/statistics middleware уже разрешает SQL-зависимости, поэтому ей БД нужна при создании `App`.

Миграции запускаются явно, без автоматического выполнения на HTTP-запросе:

```bash
php tools/project.php migrate migrate config/core.php
php tools/project.php migrate rollback config/core.php
php tools/project.php migrate
```

[`bin/migrate.php`](../../bin/migrate.php) принимает действие первым аргументом и конфигурацию вторым. Последняя команда использует API `config/container.php`. `rollback` текущей реализации откатывает все загруженные выполненные миграции, а не только последнюю.

- `config/core.php` задаёт путь `src/Migrations`; сейчас прикладных PHP-миграций там нет. Разрешение migration service создаёт служебную таблицу `migrations`; таблицы API не создаются.
- API provider использует путь выбранного adapter из `config/storage.php`: `src/Platform/Storage/Migration/PostgreSQL` либо `SQLite`.
- API PostgreSQL migrations создают `sessions` (JSONB payload и индексы), `api_stats` (ссылка на session, route/status/timing) и `users` (минимальная user/password схема). Эти таблицы принадлежат API-приложению, а не обязательной схеме ядра.

Исходники миграций и их исполнение находятся в [`Storage/Migration`](../../src/Platform/Storage/Migration). [`sql/schema.sql`](../../sql/schema.sql) отдельно описывает схему для SQL tooling; HTTP entrypoints не применяют этот файл.

## Локальные сервисы

[`docker-compose.yml`](../../docker-compose.yml) содержит PostgreSQL 16 (`db-postgres`, порт 5432, runtime БД `app`) и Redis 7 (порт 6379). Эти образы и Docker являются платформой, а не включёнными PHP-зависимостями.

```bash
php tools/project.php services:start
php tools/project.php services:status
php tools/project.php services:stop
```

`services:start` выполняет `docker compose up -d`, ждёт `pg_isready` и запускает миграции API с конфигурацией по умолчанию. Для тестов отдельно создаётся БД `app_test`:

```bash
docker compose exec db-postgres createdb -U app app_test
```

`php tools/project.php run` сначала вызывает `services:start`, затем `rr serve -c .rr.yaml`. Для запуска минимального ядра эти API-миграции не нужны; используйте SAPI/FPM либо `rr serve -c .rr.core.yaml` непосредственно.

## Автоматические проверки

Все команды выполняются из [`tools/project.php`](../../tools/project.php), который запускает включённые PHAR и инструменты отдельными процессами с нужным рабочим каталогом.

| Команда | Фактический состав |
|---|---|
| `php tools/project.php verify` | Third-party integrity → documentation inventory/links → стиль → PHPStan → соответствие SQL-артефактов → root unit tests |
| `php tools/project.php docs:check` | Инвентарь собственных документов, пути источников и локальные Markdown-ссылки без сети |
| `php tools/project.php verify:integration` | PostgreSQL SQL validation → root integration tests |
| `php tools/project.php verify:full` | `verify` → style/PHPStan/tests SQL generator → style/PHPStan/tests protobuf generator → `verify:integration` |
| `php tools/project.php test:full` | Только root unit + integration tests |
| `php tools/project.php build VERSION` | Third-party integrity и упаковка runtime `.tar.gz` + OpenAPI sidecar; не полный quality gate |

`verify` не требует PostgreSQL, Redis, `rr` или protobuf compiler. `verify:full` требует PostgreSQL, но не запускает `proto:gen:all`, FPM/RR серверы или load tests. Команды генерации protobuf отдельно требуют native compilers из таблицы выше.

Выборочные команды:

```bash
php tools/project.php test tests/Unit/Platform/Runtime/CoreApplicationTest.php
php tools/project.php test --filter=testStorageIsAvailableWithoutOpeningItForHttpRequests
php tools/project.php test:integration --filter=CoreApplicationTest
php tools/project.php sql:check
php tools/project.php sql:check:pg
php tools/project.php tools:sql:check
php tools/project.php tools:proto:check
```

### Unit profile

[`phpunit.xml`](../../phpunit.xml) включает `tests/Unit` с bootstrap `tests/bootstrap.php`. Проверяются domain services, DI, routing, hydration, HTTP client, middleware, runtime adapters, loaders и SQL contracts без внешней БД/RR KV. Тесты SAPI проверяют factory/emitter, в том числе через отдельный PHP CLI-процесс; `RoadRunnerRuntimeTest` использует внешний worker double. Это не прогон всех запросов через реальные FPM и RR listeners.

### PostgreSQL integration profile

[`phpunit.integration.xml`](../../phpunit.integration.xml) включает `tests/Integration`. [`bootstrap`](../../tests/Integration/bootstrap.php) создаёт отдельную схему, настраивает БД, строит API `App`, применяет API-миграции и передаёт shared app через `TestAppFactory`.

Проверяются прямые `App::handle()`/`handleRequest()` вызовы, сессии и их отзыв между SQL connections, отсутствие восстановления identity по fingerprint, GeoIP, статистика, изоляция схем. Отдельный `CoreApplicationTest` строит core с собственной схемой: проверяет отсутствие API-таблиц, PDO parameters, commit/rollback и `SqlExecutor`. HTTP-сервер и реальный RR KV этим профилем не запускаются.

Runner использует отдельные `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASSWORD`. Обычные `DB_*` не выбирают тестовую БД. Значения runner по умолчанию: `localhost:5432/app_test`, user `app`, password `password`.

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=15432 TEST_DB_NAME=app_test TEST_DB_USER=app TEST_DB_PASSWORD=password php tools/project.php verify:integration
```

При прямом `php tools/phpunit.php -c phpunit.integration.xml` задайте `TEST_DB_NAME` явно. Пользователю БД нужны права создавать и удалять собственные схемы. [`PostgreSqlTestDatabase`](../../tests/Integration/PostgreSqlTestDatabase.php) создаёт случайную `phpunit_<24 hex>`; приложение получает только эту схему через `DB_SCHEMA`. Shutdown cleanup удаляет только успешно созданную данным объектом схему, включая ошибку после её создания в bootstrap. Убийство процесса без shutdown может оставить схему для ручной уборки.

Параллельные запуски получают разные схемы. `sql:check:pg` отдельно создаёт `sql_gen_check_<random>`. Regression tests сохраняют sentinel-таблицу в `public` и проверяют соседние схемы. Внутри одного suite app/schema общие, поэтому отдельные тесты подготавливают нужные им таблицы сами; общей транзакции/reset перед каждым тестом нет.

## CI и границы свидетельств

Текущие [CI](../../.github/workflows/ci.yml) и [release workflow](../../.github/workflows/release.yml) подготавливают PHP 8.5 и PostgreSQL 16, запускают `verify:full`, затем `build`. CI работает на push/PR в `master`, release — при создании GitHub release. Эти workflows не регенерируют protobuf SDK/endpoints/OpenAPI и не запускают FPM/RR HTTP-совместимость или performance scenarios.

Ручные проверки реальных PHP-FPM/RoadRunner, автономной распакованной поставки и работы без сети описаны как выполненные эксперименты в [истории автономного ядра](../history/2026-10-17-autonomy-audit.md). Они использовали временные конфигурации/порты и не являются постоянными wire fixtures CI. По ним нельзя утверждать наличие непрерывной проверки всех HTTP edge cases, настоящего RR KV или сравнительной производительности.

При изменениях storage/repository/migrations используйте PostgreSQL gate; для middleware учитывайте обе композиции. Изменение `.proto` требует соответствующей регенерации и проверки generated artifacts. Не объединяйте runtime и generator bootstraps ради доступа к библиотеке: их версии и процессы намеренно разделены.
