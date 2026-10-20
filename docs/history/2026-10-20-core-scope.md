# Очистка состава ядра

Тип: `history`. Статус: `historical`. Дата: 2026-10-20.

Состояние до очистки: `f7b28ecad141a64bb7d0cfcb622256ccbde952c1`. Решение пользователя — оставить конкретные функции HTTP/PDO-ядра и удалить лишние подсистемы целиком. [Данные ревизии](2026-10-20-core-scope.json) перечисляют удалённые пути и измеренный размер исходников; [видение](../project/vision.md) задаёт текущую границу, [архитектура](../architecture/overview.md) — реализованное поведение.

## Изменение продукта и совместимость

Удалены sessions/anonymous visitor enrichment, GeoIP/fingerprints, ApiStats, Cache/KV, собственный protobuf API/SDK/OpenAPI и генератор, hydration/DTO mapping, исходящий HTTP client и deadlines без потребителя. Удалены конфигурации, старые API migrations, команды, tests, load scenarios и обслуживавшие их bundled packages. Кода или каталогов optional нет. Старые API routes и APP_CONFIG=config/container.php больше не поддерживаются; исходники прежнего контракта доступны через Git по указанной версии. Очистка не выполняет DDL и не удаляет существующие данные пользователя.

HTTP/PDO, DI/routing, ErrorResponse, AttachmentResponse, request ID и metadata logging, PostgreSQL transactions/migrations и SQL generator сохранены. SAPI/FPM и RoadRunner исполняют один HTTP-контракт; стандартные entrypoints и .rr.yaml согласованы. PostgreSQL — единственный прикладной backend; реальный SQLite PDO оставлен в tests/Fixtures исключительно для автономных migration unit tests и не упаковывается.

Основные inputs SQL-генератора заменены: три ограниченных запроса на структуре каталога служат проверочным примером typed queries вместо удалённых sessions/statistics. Каталог сохраняет исходные SQL constants с literal published и прежним partial index. Проверка на 50 000 строках показала, что параметризация status позволяет PostgreSQL перейти на generic primary-key scan с фильтром после повторных native prepares; простой composite index не гарантирует смену этого плана. Переписывание приложения и добавление migration ради генератора отвергнуто. SQL literal support остаётся отдельной будущей потребностью, не частью очистки.

Пустая project-profile обёртка удалена: runner передаёт runtime namespace напрямую. Generator по-прежнему является самостоятельным инструментом с parser/type/checker/runtime regression tests и реальными PostgreSQL checks.

RoadRunner HTTP зафиксированной версии требует roadrunner-api-dto и google/protobuf. Эти upstream деревья сохранены целиком как transport dependencies; собственных .proto, SDK или API endpoints в приложении нет. Runtime autoload map, notices и hashes согласованы, удалены целые пакеты GeoIP/simple-cache/RR KV и вся protobuf-codegen distribution.

## Три цели и стоимость

Путь разработчика — один core pipeline, два серверных адаптера и один PostgreSQL config без выбора backend; нет legacy API profile, обязательных sessions/analytics и команд их обслуживания. Генератор проверяется на выделенных SQL/PG fixtures, каталог сохраняет свой работающий SQL. Это намеренно несовместимое сокращение функциональной области; возврат удалённых функций потребует отдельного конкретного сценария.

Меньше исходников и distributions упрощают сопровождение и уменьшают поставку. Footprint в JSON считает PHP-файлы src и все файлы third_party/runtime до/после, включая notices и manifest; это размеры исходников, не runtime allocations. Ускорение, пропускная способность, отсутствие утечек и production readiness по удалению файлов не объявляются. Ограничения fetchOne, SQL subset и RR streaming сохраняются в текущих документах.

## Проверки

`verify:full` прошёл: 225 unit tests / 869 assertions, 167 SQL tool tests / 625 assertions, 31 PostgreSQL integration tests / 271 assertions. Проверены 3 bundled distributions / 1661 files, PHPStan level 10. `http:check` прошёл 188 случаев на настоящих FPM/RR с JSON/HTML error renderers. Runtime archive прошёл 38 HTTP smoke cases: стандартный core без доступной БД, отсутствие startup DDL, явная migration/seed, страницы и private PDF GET/HEAD на SAPI/RR. Manifest архива содержит только runtime distribution с правильным hash; development/tests и удалённые подсистемы отсутствуют. Итоги записаны в данных ревизии. Исторические замеры 2026-10-17/18 не пересчитывались под новый состав; две ссылки на удалённые исходники закреплены за предшествующей Git-версией. Нельзя воспроизводить их старые API-команды на текущем checkout.

## Уточнение направления после очистки

После коммита `bde3c3d` пользователь уточнил, что protobuf нужен как источник API-контракта для генерации DTO и endpoints. Удаление этого механизма вместе с прикладным наследством было слишком широким решением агента. Исторический состав и результаты очистки выше сохранены; текущее принятое направление зафиксировано в [отдельном плане](../design/protobuf-contract-generation.md). Старые sessions/GeoIP/statistics и API-профиль из этого уточнения не следуют.
