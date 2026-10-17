# Карта блоков и границ

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-17.

Этот документ отвечает, где должна жить функциональность. Композиции и точки входа описаны в [обзоре архитектуры](architecture/overview.md); будущие функции — в [roadmap](project/roadmap.md). «Входит в поставку» и «включено в минимальный pipeline» — разные свойства.

## Критерий включения

Блок ядра нужен для обычного веб-приложения, имеет небольшой понятный контракт и не навязывает предметную модель. Optional-компонент подключается явно и сохраняет полезность вне одного продукта. Сущности, роли, публикационные процессы и административный интерфейс принадлежат приложению.

Сейчас используется единое дерево исходников `src/Platform`; это логические границы, а не обещание физически отдельных дистрибутивов каждого блока.

## Основа HTTP/PDO

| Блок | Реализованная роль | Граница |
|---|---|---|
| `Platform/Runtime` | `App` / общий handler, request context, SAPI adapters; отдельный RR loop | Цикл конкретного сервера не должен определять прикладную логику |
| `Platform/Http` | Request/response contracts, middleware, JSON responses | API/protobuf adapters и исходящий клиент используются по потребности |
| `Platform/Routing` | Dispatch, route result, явные маршруты; support generated routes | Минимальное ядро использует `StaticRouteProvider`; generated discovery включается API provider |
| `Platform/DI` | Контейнер, явные factories/bindings, providers | Нет неявного поиска generated endpoints; `has()` проверяет регистрацию без autoload |
| `Platform/Storage` | PDO adapters, SQL executor, параметры, транзакции, миграции | Доступен из ядра лениво; PostgreSQL — целевой движок, SQL остаётся явным |
| `Platform/Logging` | Logging contract и реализации | Минимальный output logger и RR logger выбираются композицией |

Минимальная композиция не открывает БД для `/health`. Наличие storage provider позволяет обработчику запросить SQL executor; миграции запускаются отдельной CLI-командой. Политика данных и таблицы приложения не принадлежат платформе.

## Подключаемые блоки

| Блок | Текущая возможность | Ограничение / назначение |
|---|---|---|
| `Platform/Runtime/RoadRunnerRuntime` и RR provider | Worker loop и server services | Дополнительный runtime; не обязательный путь для FPM/SAPI |
| `Platform/Cache` | Scoped cache, tags/invalidation и RR KV integration с fallback | Capability получает `ScopedCacheFactory` / `ScopedCache`; `CacheService` — backend contract. Локальный fallback не обещает общий кеш workers и не является готовым HTTP page cache |
| `Platform/Http/Client` | Transport separation, request options, deadlines, retry primitives | Точные гарантии и ответственность за idempotency — в [контракте клиента](design/http-client-product.md) |
| `Platform/Hydration`, `Platform/DataMapping` | Hydration и преобразования DTO | Не включены в минимальную HTTP/PDO-композицию; не заменяют прикладные mapper decisions |
| `Platform/Console` | Небольшие operational commands | CLI конкретного продукта размещается в приложении |
| `Capabilities/Session` | Анонимные сессии и восстановление по cookie/bearer token; client/GeoIP enrichment в API | Это lifecycle сессии, а не полный credentials login, permissions или CSRF framework |
| `Capabilities/ApiStats` | Запись факта API-запроса в storage | Небольшой addon; не полноценная audit trail и не платформа observability |
| Protobuf adapters и generators | SDK, endpoint contracts/handlers, operation registry, OpenAPI | Необязательный API-контур; обычный HTML-сайт не обязан определять `.proto` |

Сессия восстанавливается только по cookie/bearer token; fingerprint нужен для аналитики. Авторитетная проверка и отзыв читают БД без session cache. Продление использует `UPDATE ... RETURNING` существующей неистёкшей записи. Эти свойства не устанавливают правила входа пользователя и доступа к документам.

## Примеры и прикладной код

- `src/Platform/Http/Handler/HealthHandler.php` и `config/routes.php` показывают минимальный обычный HTTP flow.
- `HealthService.Check` и `SystemService.Describe` показывают optional protobuf flow для GET и POST, а не обязательный контракт всех сайтов.
- [Каталог документов](project/document-catalog-example.md) — проект отдельного сквозного приложения, пока без реализации. Его будущие `Document`, editor roles и publication states должны жить вне `Platform`.

HTML rendering, формы, CSRF, вход, прикладные permissions, файловая публикация и HTTP page cache запланированы в roadmap. Наличие существующих storage/session/cache primitives не означает, что эти сценарии уже собраны и проверены.

## Решение перед добавлением блока

1. Какой работающий сценарий требует изменение и почему существующего контракта недостаточно?
2. Что является общей механикой, а что — политикой приложения?
3. Должна ли эта механика выполняться при каждом запросе или подключаться явно?
4. Какие проверки подтвердят корректность, а при оптимизации — измеримый выигрыш?

Не вводить универсальный CMS/workflow/ORM слой ради одного примера. Расширять основу по результатам реального сценария и поддерживать независимость минимального профиля.
