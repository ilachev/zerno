# Направления развития ядра

Тип: `roadmap`. Статус: `accepted-plan`. Сверено с кодом: 2026-10-23.

Продукт — конкретная [HTTP/PDO-основа](vision.md). [Простота, полезная функциональность и эффективность](principles.md) обязательны вместе. Сохранение наследства API-шаблона и накопление optional-подсистем не являются направлением развития.

## Проверенная база

- [Только RoadRunner](../history/2026-10-23-roadrunner-only.md): удалены SAPI/FPM entry point, adapters, upload mode, fixtures и конфигурации; App и CLI остаются независимы от loop.

- [API из контракта](../design/protobuf-contract-generation.md): первый string/unary POST JSON Echo, readonly DTO, typed endpoint, adapter/routes, автономные snapshots и реальные RR3 checks.

- [RoadRunner 3](../history/2026-10-20-roadrunner3.md): native transport, фиксированные upstream sources и проверки RR3 без Composer/PSR.
- [Восстановление транзакций и миграций](../design/transactions-and-migrations.md): исходные ошибки, атомарность каждой версии, явные SQL-списки и native PostgreSQL/CLI checks.
- [Однострочный SQL-путь](../history/2026-10-23-query-one.md): queryOne/fetchOne без полного PHP-массива, сохранение SQL, проверки PostgreSQL и измеренная граница PHP/native памяти.
- [Границы JSON input](../../examples/json-echo/README.md): явный Body::readLimited, ручной POST с 400/413/415, серверный cap и реальные RR3/chunked проверки.

- [Явная сборка без контейнера](../history/2026-10-20-explicit-composition.md): конструкторы, готовые handlers, общий HTTP config для RR3 и lazy PDO.
- Автономные runtime, SQL codegen и quality tools; проверки и runtime archive без package installation/download.
- Общие App/RequestHandler, RoadRunner 3.x, явные маршруты, 404/405/Allow/HEAD, выбор ErrorResponse.
- AttachmentResponse, прозрачная metadata-диагностика и завершение streams; [проверки/замер](../history/2026-10-18-file-responses.md) выбранного файлового пути.
- PostgreSQL PDO/параметры/транзакции/миграции, typed queries и явный LIMIT; [границы SQL](../design/sql-gen-product.md) проверяются через настоящий PostgreSQL.
- Каталог как потребитель: ограниченный список, карточка, private PDF attachment. Это приложение, а не направление CMS.
- [Очистка состава](../history/2026-10-20-core-scope.md): удалены старый API, sessions/GeoIP/fingerprints/statistics/cache, protobuf generation, hydration/mapping и outgoing HTTP. Общие точки входа согласованы.

Начальные [JSON/SELECT](../history/2026-10-17-core-http-baseline.md) и [catalog](../history/2026-10-17-public-catalog.md) отчёты описывают прежние состояния кода. Они предшествуют выбору RoadRunner единственным runtime и не гарантируют production readiness.

## Следующие ограниченные задачи

| Задача | Конкретный потребитель/проблема | Проверяемый результат |
| --- | --- | --- |
| Расширение API-контракта — пока без нового consumer | Presence, типы и transcoding добавляются только под конкретную операцию | Полный сценарий, проверки wire compatibility и сравнимые затраты; не добавлять все protobuf types заранее |

Это отдельные задачи, не заказ на новую подсистему. Для каждой сначала показать текущего потребителя, минимальный API, шаги разработчика и сравнимые затраты, затем реализовывать. Сессии, редактор, auth framework и общий cache не следуют автоматически из таблицы. Protobuf-генерация имеет отдельное прямое основание — уточнённый пользователем API-контракт; старый API-шаблон целиком не восстанавливается.

RoadRunner 3.x выбран единственным HTTP runtime по продуктовому решению. Бюджеты определяются по сравнимым данным, включая CPU/RSS server и всех workers. Для долгоживущего worker нужны длительные проверки памяти/состояния; короткие локальные серии недостаточны. Лицензия, версия/срок поддержки и политика обновлений собственного кода пока открыты.
