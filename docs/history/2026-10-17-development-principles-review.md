# Ревизия документации: три цели развития

Тип: `history`. Статус: `historical`. Дата: 2026-10-17. Исходное состояние кода: `4c59899`.

Ревизия выполнена после переименования в «Зерно» по требованию совместно учитывать максимальную простоту, функциональность и производительность. Это датированный отчёт о найденных ограничениях и изменённых планах; текущие правила находятся в [принципах](../project/principles.md), следующие работы — в [roadmap](../project/roadmap.md).

## Область и метод

Просмотрены действующие инструкции, README, onboarding, видение, roadmap, дизайн каталога, карта блоков, архитектурные справочники и component/tool guides из [инвентаря](../documentation-inventory.json). Описание HTTP, SQL, retries и подключения optional-блоков сопоставлено с конфигурацией и исходниками. Прежние history/supply snapshots сохранены; generated OpenAPI и сторонний код не изменялись.

Ревизия не является новым benchmark, security audit или проверкой реальных HTTP-серверов. Указанные ниже свойства кода установлены по исходникам. Исправление runtime здесь не объявляется выполненным.

## Наблюдения и результат пересмотра

| Наблюдение в исходном состоянии | Почему существенно для трёх целей | Что зафиксировано |
|---|---|---|
| Цели были распределены между README, vision и component proposals; единых обязательных правил оценки не было. Инструкции предписывали слои/mappers без привязки к сложности операции | Расходятся критерии выбора; простой endpoint может получить лишние понятия, а полезная функция — лишние ограничения | Один canonical principles document; краткие ссылки из AGENTS, onboarding и design. Слои вводятся по реальной сложности/повторному использованию |
| Первая волна допускала полный набор HTML/list/transaction benchmarks до создания приложения | Подготовительная инфраструктура могла отложить полезный результат и измерять искусственную работу | Начальный baseline ограничен малым ответом и параметризованным SELECT; далее публичное приложение и измерения его сценариев |
| Runtime map общая, API-композиция заранее разрешает session/statistics SQL; слово optional само по себе не задаёт цену | Удобство подключения не гарантирует отсутствие startup/request затрат | Отделены поставка, autoload, регистрация, создание сервисов и выполнение; проверяются фактические I/O и стоимость профиля |
| [Router](../../src/Platform/Routing/Router.php) проверяет известный literal path до parameterized matching; literal части pattern не экранируются. [RoutingMiddleware](../../src/Platform/Http/Middleware/RoutingMiddleware.php) не передаёт `Allow` в 405 | Малый API маршрутов ещё не означает корректное поведение; benchmark может закрепить неверный ответ | Ограниченная HTTP-матрица и исправление наблюдаемых ошибок предшествуют сравнению производительности |
| [RR provider](../../src/Platform/DI/ServiceProviders/RoadRunnerServiceProvider.php) сохраняет `PSR7Worker::chunkSize=0`: [respond](../../third_party/runtime/packages/spiral/roadrunner-http/src/PSR7Worker.php) приводит body к строке. [HttpLoggingMiddleware](../../src/Platform/Http/Middleware/HttpLoggingMiddleware.php) читает `getContents()` без восстановления позиции | Потоковый SAPI emitter не обеспечивает ограниченную память всех профилей; логирование влияет на поток и стоимость | Download оценивается через полный pipeline и server adapter; заявления о памяти требуют соответствующего прогона |
| [SqlExecutor::fetchOne](../../src/Platform/Storage/Sql/SqlExecutor.php) получает весь результат через storage; [PostgreSQLStorage](../../src/Platform/Storage/PostgreSQLStorage.php) использует `fetchAll`. [Grammar](../../tools/sql-gen/src/Parser/grammar/sql_subset.pp2) не поддерживает LIMIT; outer JOIN nullability не выводится | Удобный typed API может читать лишние данные или получить неверный row type | До каталога нужен bounded SQL path с устойчивым порядком; для outer JOIN — исправление inference либо явный отказ, прежде чем расширять expressions/facades |
| [HttpRequestOptions](../../src/Platform/Http/Client/HttpRequestOptions.php) задаёт `idempotent=true`; [client](../../src/Platform/Http/Client/ResilientHttpClient.php) не определяет безопасность повторов по method. Curl/GeoIP download целиком буферизует body | Удобный default способен повторить запись; расширение SDK не решает корректность и память текущего потребителя | Перед unsafe mutations определить и проверить безопасную retry policy; для больших downloads измерить цену и ввести необходимые границы |
| [MigrationService](../../src/Platform/Storage/Migration/MigrationService.php) делит SQL по `;` и не оборачивает весь migration в общую транзакцию | Краткая команда не гарантирует восстановление после частичного DDL | Перед редакторским сценарием проверить failure/retry/recovery на PostgreSQL; универсальный SQL parser и автоматическая атомарность не обещаются |

## Граница результата

Обновлены правила выбора решений и последовательность развития. План сохраняет шесть областей: HTTP/runtime, HTML/forms/files, SQL/data, защищённые разделы, публикация/cache и эксплуатация. Для них добавлены результаты по всем трём целям и ограниченные этапы приёмки.

Ближайший результат — корректный существующий HTTP-путь и два воспроизводимых начальных замера. Затем — полезный публичный каталог. Ограничения optional HTTP client, outer JOIN и migration recovery имеют собственные зависимости и не превращаются в обязательную работу для каждого публичного маршрута.

Этот отчёт сохраняет состояние ревизии. После исправления кода текущие architecture/component docs и roadmap должны обновляться; исторические формулировки не подменяют эти источники.
