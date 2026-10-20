# Явная сборка приложения без контейнера

Тип: `history`. Статус: `historical`. Дата: 2026-10-20.

Пользователь подтвердил vertical slices/Unix-философию и потребовал убрать DI-контейнер ради явной сборки зависимостей. До изменения: `e6ae4f66b6940c850454af2c269b01a8965ff274`. [Данные проверки](2026-10-20-explicit-composition.json) содержат счётчики, исходник CLI probe и fingerprints. Текущий контракт описывает [архитектура](../architecture/overview.md), правила — [coding guidelines](../development/coding-guidelines.md).

## Результат и путь разработчика

Удалены Container/DIContainer, autowiring plans, service providers и class-name handler factory. App получает готовый RequestHandler; config/core.php — обычная Closure(Logger): App. Точка входа явно выбирает logger/transport и вызывает функцию. RouteEntry получает готовый handler, Router — массив маршрутов; промежуточные route definitions/providers/collectors и дублирующий HandlerInterface удалены. Собственный PHP runtime: 72 → 49 файлов. Это число файлов, не оценка скорости.

Каталог собирается функцией application.php из Logger, Storage и private directory. Repository/Html/FileStore/List/Detail/Download создаются через new, связанные GET/HEAD используют один handler. Tests передают реальные зависимости в эту же функцию; service overrides и универсального get/resolve нет. Бизнес-логика не помещена в composition root и не размножена по обязательным слоям.

Подключение БД перенесено в PostgreSQLStorage: первый SQL/transaction/lastInsertId создаёт PDO, устанавливает schema и сохраняет соединение. Объект Storage и handlers каталога строятся заранее без SQL. Core HTTP не создаёт Storage. /health/404/405 остаются независимы от БД; меняется момент ошибки подключения — с конструктора на первую операцию. Prepared statement cache и SQL contracts сохранены.

CLI migrate получает directory вместо HTTP config. Он явно строит Storage/repository/loader/service, проверяет действие и directory до соединения. Loader подключает файлы выбранного каталога, поэтому новая миграция не требует регистрации namespace приложения. Default — src/Migrations; для каталога — examples/document-catalog/migrations. Это несовместимое изменение CLI/App/RouteEntry; заглушки старого контейнера не оставлены.

Оба HTTP транспорта используют одну APP_CONFIG функцию. RoadRunnerRuntime::createWorker собирает PSR17/PSR7 adapter из переданного transport; RR_HTTP_CHUNK_SIZE default0 сохраняет buffering, каталог/fixture явно выбирают8192. Проверки streaming сохранены и перенесены с удалённого provider к действующей функции сборки. Будущий protobuf output должен получать реализации/зависимости конструкторами и возвращать готовые routes без восстановления контейнера.

## Три цели и стоимость

Простота: граф виден в одном PHP-файле без bindings, reflection и lookup по имени класса. Функциональность: тот же HTTP/PDO путь и серверы, произвольный RequestHandler можно собрать вручную; изменения проверяют CLI, файлы, errors и database lifecycle. Цена: eager construction создаёт доступные handlers сразу; PDO остаётся lazy. Нельзя обещать отсутствие startup I/O только потому, что соединение отложено: config/классы всё равно читаются, объекты создаются.

Ограниченный CLI probe: PHP 8.5.9, OPcache CLI выключен, один отброшенный процесс каждого варианта, затем семь чередующихся пар отдельных процессов. Bootstrap + core composition измеряются вместе; затем 10 000 in-process GET /health с одинаковым request ID, проверкой status/body и закрытием stream. Ни сеть, ни БД, ни SAPI emission не измеряются.

| Медиана семи процессов | До | После |
| --- | ---: | ---: |
| Bootstrap + сборка графа, ms | 1.816 | 1.045 |
| 10 000 handle + проверка/закрытие, ms | 53.266 | 52.641 |
| Loaded PHP classes после сборки | 325 | 316 |
| Используемая PHP memory после сборки, bytes | 672768 | 594360 |

Это короткое локальное наблюдение, не доказательство ускорения HTTP или отсутствия утечек. PHP memory не равна RSS; малую разницу горячего цикла нельзя переносить на FPM/RR throughput. Другие задачи могли влиять на host. Для воспроизведения извлечь probe_source из JSON в свой временный PHP-файл и выполнить его отдельными процессами с `php -d opcache.enable_cli=0 probe.php ROOT before` для Git snapshot указанной старой версии и `... ROOT after` для текущего кода; использовать те же число запусков/чередование и сохранить raw samples.

## Проверки

verify:full прошёл: 200 root unit tests / 804 assertions, 167 SQL generator tests / 625 assertions, 32 PostgreSQL integration tests / 272 assertions. PHPStan level 10, bundled integrity 3 distributions / 1661 files и SQL synchronization прошли. Тесты удалённого контейнера и collector удалены с их кодом; проверки RR streaming сохранены у действующей функции сборки, добавлены проверки готового handler, migration loading без application autoload и первой транзакции.

http:check прошёл 188 случаев на настоящих FPM/RR с JSON/HTML errors, logging и файлами. Дополнительные 43 HTTP smoke cases подтвердили default SAPI/RR startup, каталог в распакованном runtime и JSON/SELECT baseline из source tree. В архиве проверены явный CLI migrate, rollback/reapply/seed без регистрации application namespace, отсутствие startup DDL, 21 typed generated row через явно созданный SqlExecutor и отказ некорректных CLI arguments до соединения. Manifest содержит только runtime distribution; контейнер и отдельные RR profiles отсутствуют. Результаты сохранены в JSON. Старые отчёты сохраняют свои данные; ссылки на удалённые исходники закреплены за прежней Git-версией. Новая сборка не означает готовность к промышленной эксплуатации или выбор основного runtime.
