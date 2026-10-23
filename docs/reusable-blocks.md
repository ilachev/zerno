# Карта блоков и границ

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-23.

Текущая область продукта определена [видением](project/vision.md). Здесь нет уровня optional capabilities: реализованные механизмы имеют конкретную роль, приложение задаёт их использование обычной композицией.

| Код | Ответственность | Потребитель |
| --- | --- | --- |
| Platform/Runtime | App, request ID и RR loop | Точки входа и реальные HTTP fixtures |
| Platform/Http | RequestHandler/Middleware, ErrorResponse/JSON, AttachmentResponse, metadata logging | Ядро и каталог |
| Platform/Routing | RouteEntry с готовыми RequestHandler, method/path dispatch | config/routes.php и маршруты каталога |
| Platform/Storage | PostgreSQL PDO, SQL contracts/executor, транзакции/миграции | Каталог и PostgreSQL regression tests |
| Platform/Logging | Вывод metadata, обычный и RR logger | RequestContext/HttpLogging и ошибки |
| tools/sql-gen | Parser, inference, typed PHP generation, PostgreSQL checker | sql/queries/catalog.sql и SQL regression fixture |

/health и ответы маршрутизатора 404/405 не открывают БД, не создают cookies и не выполняют аналитику посетителя. Объекты и middleware собираются обычным PHP через конструкторы, без DI-контейнера; добавление HttpLogging не читает body. Регистрация autoload maps/bootstrap files имеет собственную стоимость, поэтому нулевая стоимость загрузки не заявляется.

RoadRunner 3.x — единственный HTTP runtime, App отделён от worker loop. Default RR буферизует body; каталог явно выбирает chunk 8192 для seekable файлов. Поток и helper сами по себе не гарантируют ограниченную память: проверяется [полный путь](development/http-and-baseline.md).

## Граница приложения

Каталог содержит Document, статусы публикации, private FileStore, PDF-only, HTML и cache policy. Прямые SQL constants находятся в CatalogRepository; sql/queries/catalog.sql — отдельный пример для генератора с параметром status и соответствующей структурой колонок. Platform не знает таблицу каталога, тип документа или права редактора.

Helper нужен, когда убирает повторяемую механику из существующего сценария. Наличие библиотечного API не обязывает каждый handler проходить через repository, mapper, domain service или другую иерархию. Авторизация/сессии/аналитика и редактор не спрятаны в отключённом профиле — их реализации в поставке нет.

Каталог имеет явную функцию application.php: Logger/Storage/private directory → repository/HTML/files → handlers → routes/pipeline. CLI миграции собираются отдельно от HTTP.

При изменении контракта указывать шаги разработчика, полезное поведение, стоимость, ресурсы и проверки по [принципам](project/principles.md). Новая функция проходит этот критерий до появления исходников и зависимостей.
