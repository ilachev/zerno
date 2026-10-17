# Карта reusable-блоков

Этот документ фиксирует главный ориентир проекта: какие блоки реально составляют шаблон, зачем они нужны и как их трактовать при дальнейшей разработке.

## Как понимать блок

Блок стоит оставлять в шаблоне, если он:

- нужен многим современным backend-проектам;
- может быть использован без привязки к одной бизнес-модели;
- не навязывает продуктовую политику;
- понятен как самостоятельный строительный элемент.

Если кусок кода этим критериям не соответствует, ему место либо вне шаблона, либо в отдельном downstream-проекте.

## Core runtime blocks

### `Platform/Runtime`

- Роль: bootstrap приложения и запуск pipeline.
- Обязательность: обязательный core.
- Что здесь считается core: `App` как `RequestHandler`, SAPI request/response adapters и явная сборка middleware pipeline. Worker loop — optional `RoadRunnerRuntime`.
- Что не должно попадать сюда: продуктовые сценарии и feature-specific policy.

### `Platform/Http`

- Роль: request/response abstractions, handler contracts, middleware interfaces.
- Обязательность: обязательный core.
- Что здесь считается core: HTTP request/response, handler и middleware contracts. Protobuf transport adapters подключаются API-композицией.
- Что не должно попадать сюда: конкретные auth/session/business правила.

### `Platform/Routing`

- Роль: runtime routing и support для route generation.
- Обязательность: обязательный core.
- Что здесь считается core: dispatch, route result, явная таблица маршрутов. Generated protobuf routes подключаются отдельным provider.
- Что не должно попадать сюда: feature-specific handler logic.

### `Platform/DI`

- Роль: wiring runtime и блоков.
- Обязательность: обязательный core.
- Что здесь считается core: container, service providers, registration rules.
- Что не должно попадать сюда: продуктовые зависимости и example policy.

## Support blocks

### `Platform/Storage`

- Роль: storage abstractions, SQL executor, migrations.
- Обязательность: обязательный reusable block для backend template.
- Что здесь считается core: storage adapters, typed SQL execution, migrations.
- Optional-часть: дополнительные storage profiles и engine-specific tuning.
- Целевое направление: explicit SQL с typed generation поверх него.

### `Platform/Cache`

- Роль: cache abstraction и runtime backend integration.
- Обязательность: optional reusable block.
- Что здесь считается core: единый cache contract, scoped cache views, scope-aware invalidation и fallback-safe runtime behavior.
- Optional-часть: конкретный backend вроде RoadRunner KV.
- Канонический consumer path: capability code должен получать `ScopedCacheFactory` и работать через `ScopedCache`, а не склеивать cache keys вручную поверх общего `CacheService`.
- `CacheService` должен оставаться backend-level abstraction для platform/runtime слоя, а не превращаться в точку, куда capability code напрямую тащит свою key/invalidation policy.

### `Platform/Logging`

- Роль: минимальный logging contract и runtime logger implementations.
- Обязательность: обязательный support block.
- Что здесь считается core: простой logging API без framework coupling.
- Optional-часть: конкретные output formats и runtime integrations.

### `Platform/Console`

- Роль: маленькие operational commands вокруг runtime.
- Обязательность: optional support block.
- Что здесь считается core: команды, которые обслуживают reusable runtime pieces.
- Что не должно попадать сюда: product-only CLI сценарии.

### `Platform/Hydration` и `Platform/DataMapping`

- Роль: преобразование между storage/runtime structures и PHP objects.
- Обязательность: optional reusable support block, не включённый в минимальную HTTP/PDO-композицию.
- Что здесь считается support: reflection-based hydration support, DTO mapping.
- Зона внимания: не раздувать этот слой абстракциями без явной пользы.

## Capability blocks

### `Capabilities/Session`

- Роль: reusable session management block.
- Обязательность: optional reusable block; минимальная HTTP/PDO-композиция не создаёт сессии.
- Что здесь считается core: session lifecycle, cookie/bearer restore, fingerprinting, client detection, geolocation hooks.
- Optional-часть: конкретные payload conventions и enrichments.
- Авторизация восстанавливается только по cookie/bearer token; fingerprint служит аналитике и не подтверждает личность.
- Проверка и отзыв сессий читают авторитетную БД без кеширования. Продление выполняет `UPDATE ... RETURNING` существующей неистёкшей сессии и не может восстановить удалённую запись.

### `Capabilities/ApiStats`

- Роль: маленький add-on для записи request log.
- Обязательность: optional reusable block.
- Что здесь считается core: middleware, который пишет факт API-вызова в storage.
- Что не должно происходить: превращение в большую analytics/observability платформу внутри шаблона.

## Minimal example

### `HealthService.Check` и `SystemService.Describe`

- Роль: минимальные нейтральные примеры protobuf-first endpoint flow.
- Обязательность: часть core surface.
- Назначение: показать, как `.proto` превращается в generated route, generated handler и endpoint implementation без продуктовой логики, и покрыть и `GET`, и `POST` body flow.
- Что не должно происходить: превращение health-check в demo-модуль с отдельной архитектурой вокруг него.

## Как применять эту карту

Перед любым изменением нужно ответить на четыре вопроса:

1. Это core runtime block, support block, capability block или минимальный transport example?
2. Это reusable building block или конкретная product policy?
3. Это должно быть обязательной частью шаблона или optional add-on?
4. Улучшает ли изменение reuse-value блока, а не просто увеличивает архитектурный словарь?

## Практический критерий качества

Проект движется в правильную сторону, если новый backend можно собирать так:

- брать `Platform` как runtime base;
- подключать только нужные `Capabilities`;
- использовать `HealthCheck` как минимальный эталон endpoint flow;
- не переписывать смысл блоков под конкретный продукт;
- для persistence писать явный SQL и получать поверх него typed generated PHP, а не прятать его за ORM-like магией.

## Команды и поставка

Runtime загружается через `bootstrap.php`, тесты — через `tests/bootstrap.php`. Фиксированные runtime-исходники лежат в `third_party/runtime`; генераторы и инструменты качества поставляются отдельно от runtime-релиза. Команды: `php tools/project.php help`, основной gate: `php tools/project.php verify`.
