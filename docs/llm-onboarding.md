# LLM onboarding

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-18.

## Минимальный контекст

«Зерно» (`zerno`) — небольшое универсальное автономное PHP HTTP/PDO-ядро для разработчиков веб-приложений. Разработчики муниципальных и государственных сайтов — одна из приоритетных аудиторий, которая не ограничивает назначение ядра. Продуктовая цель описана в [видении](project/vision.md), принятые направления — в [roadmap](project/roadmap.md). [Публичный каталог](../examples/document-catalog/README.md) уже собирает HTML-список, карточку и PDF download; формы, полноценный вход и редактор остаются планом.

Сейчас развиваем core-функции. Каталог — проверочный потребитель: его редактор и публикационный workflow отложены. Следующие задачи — общие HTTP-ошибки, файловые ответы/прозрачная диагностика и ограниченные SQL-результаты. Html, FileStore и HTML Errors пока находятся в примере; не считать их готовыми API Platform и не расширять прикладной функционал ради продолжения roadmap.

API развивается по [собственной модели](project/vision.md#собственная-модель-разработки): обычный PHP, явная композиция, видимый SQL и автономная поставка. Не превращать roadmap в воспроизведение чужого API или набора подсистем; необычность решения должна приносить конкретную пользу по трём принципам.

БД входит в основу: PDO, параметры SQL, транзакции, миграции и типизированные generated queries. Репозиторий содержит фиксированные сторонние исходники и инструменты; установка пакетов через Composer, npm или Task не требуется ни для разработки, ни для сборки. PHP, расширения, PostgreSQL и выбранные server/compiler executables остаются требованиями платформы.

## Перед первой правкой

1. Прочитать [AGENTS.md](../AGENTS.md): язык общения, код, проверки, БД и generated/vendor boundaries.
2. Прочитать [принципы развития](project/principles.md): простота, полезная функциональность и производительность — совместные обязательные критерии; затем проверить рабочее дерево и фактические исходники области задачи.
3. Прочитать [обзор архитектуры](architecture/overview.md) и нужный документ из таблицы ниже. Не загружать всю документацию без необходимости.
4. Различать реализованное поведение, принятое направление, предложение и открытый вопрос. Исторический аудит не устанавливает текущий контракт.
5. После правки обновить документы, которыми затронутое поведение описывается, и выполнить требуемые проверки.

## Композиции приложения

| Профиль | Композиция | Запуск и поведение |
|---|---|---|
| Минимальный HTTP/PDO | `config/core.php`, `config/routes.php` | `public/http.php` через PHP/SAPI/FPM; явные GET/HEAD `/health`, без открытия БД и включения capabilities |
| Минимальный с RR | `config/core-roadrunner.php` | `.rr.core.yaml`, общий handler и отдельный worker loop |
| Необязательный protobuf API | `config/container.php` | По умолчанию `public/index.php` / `.rr.yaml`; generated routes, Session, ApiStats, GeoIP и cache integrations |
| Публичный пример | `examples/document-catalog/config.php`, `roadrunner.php` | Собственные SQL/миграция/шаблоны; RR явно включает chunk 8192, GET/HEAD каталог без сессий |

`App` реализует общий `RequestHandler`; он не запускает RoadRunner. Middleware выбирает конфигурация. Наличие optional-пакета в поставке не означает его выполнение при минимальном HTTP-запросе.

## Чтение по задаче

| Задача | Документы | Начать проверку исходников с |
|---|---|---|
| Runtime, HTTP, маршруты, DI | [Обзор](architecture/overview.md), [жизненный цикл](architecture/request-lifecycle.md) | `config/core.php`, `config/container.php`, `src/Platform/Runtime`, `src/Platform/Routing`, `src/Platform/DI` |
| Реальные FPM/RR и производительность | [HTTP и baseline](development/http-and-baseline.md), [начальный отчёт](history/2026-10-17-core-http-baseline.md) | `tools/check-http.php`, `tools/http-fixtures`, `load-tests/baseline` |
| Граница core / optional / приложение | [Карта блоков](reusable-blocks.md), [видение](project/vision.md) | Providers выбранной композиции |
| SQL и миграции | [SQL-дизайн](design/sql-gen-product.md), [SQL tool](../tools/sql-gen/README.md), [проверки](development/infrastructure-and-testing.md) | `src/Platform/Storage`, `sql`, `codegen/Sql`, `tools/sql-gen` |
| Protobuf API и генерация | [Текущий контур](architecture/protobuf-and-codegen.md), [правила генератора](design/protoc-php-gen-guidelines.md), [tool README](../tools/protoc-php-gen/README.md) | `protos/proto/app/v1`, `codegen/Protobuf`, `tools/protoc-php-gen` |
| Исходящий HTTP | [HTTP client](design/http-client-product.md) | `src/Platform/Http/Client`, `src/Platform/Runtime/Deadline.php`, `config/http.php` |
| Session, статистика, cache | [Жизненный цикл](architecture/request-lifecycle.md), [карта блоков](reusable-blocks.md) | `src/Capabilities`, `src/Platform/Cache`, API providers |
| Поставка, сборка и инструменты | [Автономность](design/autonomous-core.md), [проверки](development/infrastructure-and-testing.md) | `bootstrap.php`, `bootstrap`, `third_party/manifest.json`, `tools/project.php` |
| Новая функциональность | [Roadmap](project/roadmap.md), [проект каталога](project/document-catalog-example.md) | Сначала текущая реализация, затем минимальный сквозной сценарий |
| HTML и файлы каталога | [Запуск примера](../examples/document-catalog/README.md), [проверки и замеры](history/2026-10-17-public-catalog.md) | `examples/document-catalog`, `tests/Integration/Examples`, `load-tests/catalog` |
| Обновление знаний | [Правила документации](development/documentation.md), [инвентарь](documentation-inventory.json) | Документ-владелец факта и связанные `source_paths` |

## Источники истины

| Вопрос | Источник |
|---|---|
| Как агент работает с репозиторием | `AGENTS.md`; `CLAUDE.md` только подключает его |
| Что исполняется сейчас | Код, конфигурация и соответствующие тесты; architecture-документы объясняют их |
| Как оценивать решения и компромиссы | `docs/project/principles.md` |
| Что строим и в каком порядке | `docs/project/vision.md`, `docs/project/roadmap.md` |
| Что генерируется | SQL/protobuf inputs и generator profile; generated output не редактируется вручную |
| Какие зависимости поставляются | `third_party/manifest.json` и manifests distributions |
| Какие команды доступны | `php tools/project.php help` и код runner |
| Что проверялось раньше | Только явно датированные исторические отчёты с границами проверки |

## Проверки

```bash
php tools/project.php docs:check
php tools/project.php verify
```

`verify` не требует внешних сервисов. При изменении БД выполнить реальные PostgreSQL checks через `verify:integration`; при изменении генератора — его `tools:sql:check` или `tools:proto:check`. Полный набор — `verify:full`. Подготовка окружения и `TEST_DB_*` описаны в [инфраструктуре](development/infrastructure-and-testing.md).

Не делать вывод о безопасности, производительности или готовности к эксплуатации по наличию блока или успешному unit test. Известные ограничения и проверяемые результаты читать в документе соответствующей области. Полной системы credentials/permissions/CSRF/audit пока нет. Начальный FPM/RR baseline охватывает только JSON и indexed SELECT; он не выбирает основной runtime и не проверяет длительную устойчивость worker.
