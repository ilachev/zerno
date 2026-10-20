# Пример: публичный каталог документов

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-20.

Каталог — потребитель HTTP/PDO-механизмов. application.php собирает граф обычными конструкторами из Logger/Storage/private directory, routes.php получает готовые handlers; контейнера нет. [Запуск и seed](../../examples/document-catalog/README.md) принадлежат примеру; развитие ядра определяется [roadmap](roadmap.md). Редактор, роли, формы публикации и CMS workflow в поставке отсутствуют.

## Реализованный поток

GET/HEAD /documents → HTML-список; /documents/{id} → карточка; /documents/{id}/download → PDF attachment. /health использует общий handler. Неподдерживаемый метод получает HTML 405 с Allow, неизвестный/закрытый документ — одинаковую 404. Ошибка инфраструктуры получает общую 500 без приватных деталей.

Document, статусы draft/published/withdrawn, PDF-only, private FileStore, шаблоны и no-store принадлежат приложению. Общие ErrorResponse, AttachmentResponse и metadata logging не знают публикационную политику.

[CatalogRepository](../../examples/document-catalog/src/CatalogRepository.php) выполняет прямой SQL с фиксированным published-фильтром. Список использует ORDER BY id DESC и LIMIT 21, интерфейс показывает 20 строк. before — положительный канонический ID. Lookup использует status/id. Проверки целостности published metadata остаются в repository, поскольку schema допускает nullable draft-поля.

Фактический DDL находится в [migration](../../examples/document-catalog/migrations/Migration20261017001_CreateDocuments.php). Partial index published/id обслуживает фиксированный predicate. SQL generator пока не поддерживает string literals в WHERE: ради его подключения каталог не переводится на параметр status, который меняет доступность partial index в generic prepared plans. [Основные inputs генератора](../../sql/queries/catalog.sql) — отдельный пример ограниченного typed SQL с параметром status, не runtime-источник каталога.

## Файл и ресурсы

Opaque storage key выбирается из записи, не из пользовательского пути. FileStore проверяет containment/тип/размер и метаданные открытого файла. DownloadHandler передаёт новый поток AttachmentResponse; HEAD закрывает файл и возвращает пустой body с теми же representation headers. Range игнорируется, выдаётся полный 200.

SAPI/RR закрывают body после отправки. Каталог выбирает RR chunk 8192 для seekable regular files; default RR 0 буферизует. Логирование не читает payload. Ограниченная память подтверждается [полным transport-путём](../development/http-and-baseline.md), а не самим наличием StreamInterface. Изменение файла во время передачи/client abort и долгосрочная устойчивость не объявляются проверенными.

## Проверки и границы

[PostgreSQL integration](../../tests/Integration/Examples/DocumentCatalog/CatalogApplicationTest.php) использует свои случайные схемы и временные файлы: published/hidden, курсор, пустые данные, LIMIT, один SELECT на полезный запрос, отсутствующий/неверный файл, HEAD и headers. SQL fixture отдельно проверяет LIMIT/native parameter types. /health/404/405 не разрешают Storage. Unit HTML tests проверяют escaping и семантическую структуру.

Тексты/верстка без JavaScript остаются примером, не шаблонизатором ядра. [Отчёт публичного этапа](../history/2026-10-17-public-catalog.md) фиксирует прежнее состояние и результаты запуска; действующие integration tests проверяют текущие SQL constants, страницы и файлы. Каталог не предоставляет login, sessions, upload UI, редактирование, публикацию через HTTP, антивирус или аудит действий.
