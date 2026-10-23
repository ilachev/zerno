# LLM onboarding

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-23.

«Зерно» — универсальная автономная PHP HTTP/PDO-основа. [AGENTS.md](../AGENTS.md) задаёт правила работы, [принципы](project/principles.md) — три совместных критерия, [граница продукта](project/vision.md) — конкретный состав. План не устанавливает наличие функции.

## Текущий состав

Собственные Request/Response/Body/Uri/UploadedFile без PSR/Nyholm, маршруты/явная композиция, ErrorResponse, AttachmentResponse, request ID и metadata logging, PostgreSQL/PDO/SQL/транзакции/миграции, SQL generation, SAPI/FPM и RoadRunner. Каталог — прикладной потребитель; его Document, публикационные состояния, PDF-only и шаблоны не являются API ядра.

Унаследованные Session/GeoIP/fingerprints/ApiStats/cache/hydration/mapping/outgoing HTTP удалены. Не восстанавливать их под названием optional и не поддерживать старые контракты заглушками. Старый protobuf API/генератор также удалён, но пользователь подтвердил направление `.proto → DTO/endpoint/HTTP adapter/routes`; первый [сквозной Echo-этап](design/protobuf-contract-generation.md) реализован с обычными string DTO и unary POST JSON. PHP зависимости и quality/codegen tools включены; package installation/download отсутствуют на всех путях.

## Начать задачу

1. Прочитать AGENTS, [правила кода](development/coding-guidelines.md) и принципы, проверить git status и фактический код/тесты.
2. Найти документ-владелец через [индекс](README.md) и [инвентарь](documentation-inventory.json).
3. Реализовать минимальный контракт для конкретного потребителя, сохраняя SQL и время жизни ресурсов явными.
4. Обновить текущую документацию и выполнить verify. Для БД — PostgreSQL integration; для генератора — tools:sql:check; для transport — реальные HTTP checks.

| Задача | Документ | Код |
| --- | --- | --- |
| HTTP, runtime, routing, сборка объектов | [Архитектура](architecture/overview.md), [lifecycle](architecture/request-lifecycle.md) | config/core.php, public/index.php, src/Platform |
| Ограниченный JSON input | [Ручной POST-пример](../examples/json-echo/README.md) | Body::readLimited, examples/json-echo, tools/check-http.php |
| API-контракт и генерация DTO/endpoints | [Контракт protobuf](design/protobuf-contract-generation.md) | contracts/echo.proto, tools/proto-gen, gen/Generated/Echo, examples/contract-echo |
| Транзакции и миграции | [Контракт восстановления](design/transactions-and-migrations.md) | AbstractStorage, MigrationService, bin/migrate.php |
| SQL и генератор | [SQL-контракт](design/sql-gen-product.md), [tool README](../tools/sql-gen/README.md) | sql, tools/sql-gen, src/Platform/Storage |
| Проверки и БД | [Инфраструктура](development/infrastructure-and-testing.md) | tools/project.php, tests/Integration |
| FPM/RR, файлы и измерения | [HTTP guide](development/http-and-baseline.md) | tools/check-http.php, tools/http-fixtures, load-tests |
| Поставка | [Автономность](design/autonomous-core.md), [third-party](../third_party/README.md) | bootstrap.php, third_party/manifest.json |
| Каталог | [Запуск](../examples/document-catalog/README.md), [прикладные границы](project/document-catalog-example.md) | examples/document-catalog |
| Направление и документация | [Roadmap](project/roadmap.md), [maintenance](development/documentation.md) | Документ-владелец факта |

## Композиции и ограничения

config/core.php возвращает Closure(Logger): App. App принимает готовый RequestHandler, маршруты — готовые объекты. Контейнер, autowiring, service providers и class-name resolver удалены. public/http.php и public/index.php используют одну APP_CONFIG функцию; транспорт/RR logger создаются в точке входа. Целевой RoadRunner — 3.x, проверен 3.0.0; executable выбирается через PATH и не скачивается командами проекта. RR использует RoadRunnerHttpWorker → native HttpWorker, без PSR adapter; logger-removal patch записан в runtime manifest. RR_HTTP_CHUNK_SIZE=8192 включён явно в catalog/fixture server env, default0 буферизует body.

Каталог собирается в examples/document-catalog/application.php из Logger/Storage/private directory; config.php только выбирает эти входы. PostgreSQLStorage лениво открывает PDO при первой операции; handlers/repository строятся заранее без SQL. /health/404/405 не открывают БД. Миграции — отдельный CLI граф с директорией migrations, не HTTP-конфигурацией.

HttpLogging не читает тело. SAPI/RR закрывают body после отправки, включая HEAD/ошибки; App::handle его не закрывает. Каталог сохраняет прямой SQL; sql/queries/catalog.sql — отдельный пример генератора.

PostgreSQL — единственная БД приложения. SQLiteStorage находится только в tests/Fixtures для автономных migration unit tests, не в поставке. Integration bootstrap не создаёт общий App, API-таблицы или глобальную схему: тесты сами владеют случайными схемами TEST_DB_*.

Bundled RoadRunner HTTP требует protobuf/DTO runtime. Контракт Echo генерирует собственные readonly DTO и HTTP adapter; они не используют Google Message в runtime. SDK и legacy API не восстановлены; прочие protobuf types/transcoding пока не поддержаны. Исторические отчёты описывают прошлые версии; удалённые исходники доступны через Git, как объясняет [очистка состава](history/2026-10-20-core-scope.md).

Числовые бюджеты и основной runtime не выбраны. Не объявлять production readiness, отсутствие утечек или ускорение по короткому тесту. Механизм входа/сессий/CSRF/прав не реализован; не приписывать его ядру.
