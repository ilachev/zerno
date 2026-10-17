# LLM Onboarding

Короткий канонический обзор проекта для LLM и человека.

## Что это за проект

`base-api-template` развивается из backend template в небольшое автономное PHP веб-ядро для создания сайтов, в том числе муниципальных. Работа с БД через PDO и явный SQL входит в основу; CMS и продуктовая админка остаются прикладным кодом.

Сейчас проект использует PHP 8.5+, RoadRunner adapter, `protobuf-first` API, типизированную SQL-генерацию и набор reusable-блоков. Runtime-зависимости, генераторы и инструменты качества включены в поставку; команды запускаются через PHP без установки Composer или Task. Состав и границы поставки описаны в [плане автономного ядра](./design/autonomous-core.md).

Это не готовый продукт и не framework. Репозиторий должен восприниматься так:

- `Platform` — runtime core;
- `Capabilities` — reusable blocks;
- `HealthCheck` и `SystemDescribe` — минимальные нейтральные примеры текущего protobuf API.

## Цель проекта

- небольшое PHP веб-ядро с доступной из коробки работой с БД;
- простые building blocks и явная композиция приложения;
- включённые в поставку фиксированные сторонние исходники там, где они полезны;
- запуск, сборка и проверки без обязательной установки Composer, Task, npm и скачивания пакетов;
- производительность, подтверждённая замерами одинаковых сценариев.

Платформенные требования — PHP, расширения, PostgreSQL и выбранный HTTP runtime — учитываются отдельно. Изъятие менеджера пакетов из обязательного пути не означает отсутствия стороннего кода.

## Главные точки входа

- `public/index.php` — текущий RoadRunner entrypoint: создаёт `App`, разрешает worker и запускает `RoadRunnerRuntime`
- `public/http.php` — обычная PHP/SAPI точка входа для минимальной композиции, включая PHP-FPM
- `src/Platform/Runtime/App.php` — bootstrap DI и общий `RequestHandler`; `handle()` обрабатывает запрос, `handleRequest()` сохранён для совместимости
- `src/Platform/Runtime/RoadRunnerRuntime.php` — цикл получения запросов и отправки ответов через worker
- `config/container.php` — DI wiring и фабрика `RequestHandler`, которая задаёт middleware pipeline
- `config/core.php`, `config/routes.php` — минимальная HTTP/PDO-композиция и явные маршруты
- `config/core-roadrunner.php`, `.rr.core.yaml` — та же минимальная композиция с RR adapter
- `protos/proto/app/v1/*.proto` — core API contracts
- `taskfile.yaml` — команды разработки
- `docs/reusable-blocks.md` — каноническая карта reusable-блоков проекта
- `docs/design/autonomous-core.md` — аудит зависимостей и план автономной поставки
- `docs/design/protoc-php-gen-product.md` — целевая модель protobuf codegen-tooling
- `docs/design/sql-gen-product.md` — целевая модель typed SQL generation
- `docs/design/http-client-product.md` — целевая модель outbound HTTP client

## Текущая структура

### `src/Platform`

Runtime core:

- DI
- HTTP abstractions and middleware pipeline
- routing runtime and route generation support
- storage, cache, logging, console support
- hydration and data mapping

### `src/Capabilities`

Reusable blocks:

- `Session` — сессии, fingerprinting, client detection, session middleware, geolocation integration
- `ApiStats` — маленький add-on для записи request log

## Что реально работает

- runtime без product endpoints по умолчанию
- анонимные сессии и продолжение существующих сессий по bearer token или cookie
- fingerprint matching для аналитики клиентов, без восстановления session identity
- геолокация по IP
- request log для API-вызовов
- protobuf/codegen pipeline
- generated server-side endpoint handlers from protobuf `service/rpc`
- unit и integration profiles

## Какие generated артефакты считать основными

- `protos/gen/*` — основной protobuf SDK и metadata
- `gen/Generated/Endpoint/*` — generated endpoint contracts и handlers
- `gen/Generated/OperationManifest/*` — generated operation registry classes for each RPC group
- SQL source of truth должен жить в `sql/schema.sql` и `sql/queries/*`, а generated SQL artifacts — в `gen/Generated/Sql/*`
- legacy `gen/Infrastructure/Hydrator/*` больше не является валидным generated output
- `typhoon/type` допустим только как internal helper внутри codegen tooling, но не как runtime dependency основного проекта

## Что важно считать инвариантами

- не редактировать вручную `protos/gen/*`
- для изменения текущего protobuf API сначала менять `.proto`, потом запускать генерацию
- `Platform` не должен содержать продуктовую политику
- reusable block должен оставаться полезным вне конкретного продукта
- example code должен быть явно вторичен по отношению к runtime
- default `php tools/project.php verify` не должен требовать поднятых внешних сервисов
- текущий RoadRunner adapter работает в long-running процессе, значит надо следить за памятью и накоплением состояния; `App` получает общий `RequestHandler` и не запускает worker
- состав middleware принадлежит конфигурации приложения: `config/container.php` сохраняет API-композицию, `config/core.php` задаёт минимальный HTTP/PDO вариант
- DI не ищет generated endpoints автоматически; API provider регистрирует их bindings явно, а `has()` проверяет регистрации без autoload
- persistence должен двигаться в сторону explicit SQL + typed generation, а не в сторону усложняющегося ORM-like слоя
- type-system libraries допустимы только внутри generators и только там, где они реально заменяют ручной type rendering
- внутри `tools/sql-gen` canonical PHP type model уже должен считаться Typhoon-based, а не строковым
- `sql-gen` должен восприниматься как generic engine с внешним project profile из `codegen/Sql`, а не как тулза со встроенным знанием этого шаблона
- roadmap `sql-gen` сейчас идёт в richer expression typing и relation-aware schema semantics, а не в ORM-like abstractions или “полную поддержку PostgreSQL”
- cache layer должен восприниматься как reusable library: capability code должно идти через `ScopedCacheFactory` / `ScopedCache`, а не напрямую через общий `CacheService` с ручной склейкой keys и invalidation logic
- outbound HTTP должен двигаться через `Platform/Http/Client` как reliability block с deadlines, retry policy и transport separation, а не через ad-hoc `file_get_contents()`/stream-context logic
- общий timeout budget primitive теперь живёт в `Platform/Runtime/Deadline`, а `Platform/Runtime/RequestContext` должен восприниматься как источник inherited deadline для downstream IO

## Команды

`tools/project.php` использует включённые в репозиторий исходники и закреплённые инструменты. Установка пакетов не нужна; Task остаётся необязательной обёрткой.

```bash
php tools/project.php proto:gen:all
php tools/project.php sql:gen
php tools/project.php lint
php tools/project.php phpstan
php tools/project.php test
php tools/project.php test:integration
php tools/project.php verify
php tools/project.php verify:full
php tools/project.php services:start
php tools/project.php services:stop
php tools/project.php run
```

Практически:

- `php tools/project.php verify` — основной локальный gate
- `php tools/project.php test:integration` использует PostgreSQL profile
- `php tools/project.php verify:full` дополнительно включает quality gates обоих генераторов и PostgreSQL profile
- storage/runtime изменения нельзя проверять только unit-тестами

## Минимальный порядок чтения

1. `docs/architecture/request-lifecycle.md`
2. `docs/reusable-blocks.md`
3. `src/Platform/Runtime/App.php`
4. `src/Platform/Runtime/RoadRunnerRuntime.php`
5. `config/container.php`
6. `docs/design/autonomous-core.md`
7. `src/Capabilities/*`
8. `src/Platform/Storage/*`
9. `protos/proto/app/v1/*`

## Текущие зоны риска

- storage/query слой всё ещё transitional и со временем должен уступить место более прямому `sqlc-like` пути
- hydration/data mapping layer всё ещё чувствителен к усложнению
- endpoint implementations всё ещё пишутся вручную, а generator пока валидирует только наличие файла и корректное объявление класса
- consistency между generated operation registries, generated handlers и endpoint implementations теперь проверяется в default `verify`, но это всё ещё verify-time guard, а не отдельный generator module
- `protoc-php-gen` сейчас стабилен только в endpoint-контуре; дальнейшее расширение допустимо только как modular codegen, а не как возврат к старой смешанной генерации

## Как оценивать изменения

Изменение хорошее, если оно:

- делает проект проще для чтения
- уменьшает library/framework gravity
- не вводит продуктовые demo-flow в основной runtime
- усиливает reusable blocks, а не раздувает архитектурный словарь
