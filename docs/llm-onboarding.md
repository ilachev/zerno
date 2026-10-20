# LLM onboarding

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-20.

«Зерно» — универсальная автономная PHP HTTP/PDO-основа. [AGENTS.md](../AGENTS.md) задаёт правила работы, [принципы](project/principles.md) — три совместных критерия, [граница продукта](project/vision.md) — конкретный состав. План не устанавливает наличие функции.

## Текущий состав

HTTP/маршруты/явная композиция, ErrorResponse, AttachmentResponse, request ID и metadata logging, PostgreSQL/PDO/SQL/транзакции/миграции, SQL generation, SAPI/FPM и RoadRunner. Каталог — прикладной потребитель; его Document, публикационные состояния, PDF-only и шаблоны не являются API ядра.

Унаследованные Session/GeoIP/fingerprints/ApiStats/cache/protobuf API/hydration/mapping/outgoing HTTP удалены. Не восстанавливать их под названием optional и не поддерживать старые контракты заглушками. PHP зависимости и quality/codegen tools включены; package installation/download отсутствуют на всех путях.

## Начать задачу

1. Прочитать AGENTS и принципы, проверить git status и фактический код/тесты.
2. Найти документ-владелец через [индекс](README.md) и [инвентарь](documentation-inventory.json).
3. Реализовать минимальный контракт для конкретного потребителя, сохраняя SQL и время жизни ресурсов явными.
4. Обновить текущую документацию и выполнить verify. Для БД — PostgreSQL integration; для генератора — tools:sql:check; для transport — реальные HTTP checks.

| Задача | Документ | Код |
| --- | --- | --- |
| HTTP, runtime, routing, DI | [Архитектура](architecture/overview.md), [lifecycle](architecture/request-lifecycle.md) | config/core.php, config/core-roadrunner.php, src/Platform |
| SQL, миграции, генератор | [SQL-контракт](design/sql-gen-product.md), [tool README](../tools/sql-gen/README.md) | sql, tools/sql-gen, src/Platform/Storage |
| Проверки и БД | [Инфраструктура](development/infrastructure-and-testing.md) | tools/project.php, tests/Integration |
| FPM/RR, файлы и измерения | [HTTP guide](development/http-and-baseline.md) | tools/check-http.php, tools/http-fixtures, load-tests |
| Поставка | [Автономность](design/autonomous-core.md), [third-party](../third_party/README.md) | bootstrap.php, third_party/manifest.json |
| Каталог | [Запуск](../examples/document-catalog/README.md), [прикладные границы](project/document-catalog-example.md) | examples/document-catalog |
| Направление и документация | [Roadmap](project/roadmap.md), [maintenance](development/documentation.md) | Документ-владелец факта |

## Композиции и ограничения

config/core.php — общий HTTP/PDO pipeline. config/core-roadrunner.php добавляет серверный адаптер; public/index.php и .rr.yaml по умолчанию выбирают его. public/http.php использует core.php. Каталог выбирает свои маршруты, HTML Errors, миграции и RR chunk 8192. Каталог использует прямой SQL; sql/queries/catalog.sql — отдельный пример для генератора.

/health не разрешает PostgreSqlStorageConfig/Storage. HttpLogging не читает тело. SAPI/RR закрывают body после отправки, включая HEAD/ошибки. Прямой App::handle не отправляет и не закрывает response. Default RR chunk 0 материализует тело; ограниченная память проверена для seekable-file пути с 8192, не для произвольного потока.

PostgreSQL — единственная БД приложения. SQLiteStorage находится только в tests/Fixtures для автономных migration unit tests, не в поставке. Integration bootstrap не создаёт общий App, API-таблицы или глобальную схему: тесты сами владеют случайными схемами TEST_DB_*.

Bundled RoadRunner HTTP требует protobuf/DTO runtime; собственных .proto, SDK, endpoints и protobuf generator в проекте нет. Исторические отчёты описывают прошлые версии; удалённые исходники доступны через Git, как объясняет [очистка состава](history/2026-10-20-core-scope.md).

Числовые бюджеты и основной runtime не выбраны. Не объявлять production readiness, отсутствие утечек или ускорение по короткому тесту. Механизм входа/сессий/CSRF/прав не реализован; не приписывать его ядру.
