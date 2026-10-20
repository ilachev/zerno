# Собственный HTTP без Nyholm и PSR-пакетов

Тип: `history`. Статус: `historical`. Проверено: 2026-10-20.

Снимок перехода после `9c300d3`: RequestHandler/Middleware/ErrorResponse и потребители переведены на конкретные Request/Response/Body/Uri/UploadedFile ядра. Текущий контракт принадлежит [lifecycle](../architecture/request-lifecycle.md), поставка — [autonomous core](../design/autonomous-core.md). [Данные проверки](2026-10-20-native-http.json) содержат версию до изменения, raw CLI samples и исходник probe.

## Решение и цена сопровождения

Разработчик принимает Request, возвращает new Response(status, headers, string|Body), для файлов передаёт Body::create(resource) в AttachmentResponse. Composition root и маршруты сохраняют готовые объекты; factories/interfaces для сообщений отсутствуют. Полная поверхность PSR-7/17 не воспроизводится; это breaking PHP API change, все потребители checkout переведены. Внешние PSR middleware/adapters потребуют отдельной интеграции, которой здесь нет.

Удалены четыре пакета: nyholm/psr7, psr/http-factory, psr/http-message и psr/log. Runtime содержит семь retained packages для RR, protobuf/DTO и transport support. Собственный Logger уже существовал; единственный PSR LoggerTrait в тесте заменён явной реализацией. Upstream PSR7 adapter/logger sources сохранены как provenance и не загружаются. В RR Worker.php отдельным patch удалён неиспользуемый logger, eager default которого требовал psr/log; обработка frames сохранена. Upstream notices/hashes и обе worker adaptations зафиксированы в manifest.

Теперь команда проекта отвечает за header validation, URI, uploads и владение Body. Это дополнительный собственный код и ответственность, поэтому одного уменьшения vendor недостаточно для доказательства простоты/производительности. Выбраны concrete types с необходимыми текущим потребителям методами. Строковый Body не открывает php://temp; файл остаётся ресурсом. Metadata copies разделяют body, close идемпотентен. В RR chunk path удерживаются текущий и следующий блоки; default 0 по-прежнему буферизует весь body. Native upstream завершает stream при generator failure; адаптер сохраняет ошибку чтения и выбрасывает её после завершения, транспортные ошибки также выходят наружу.

Без RR его библиотеки не загружаются; autoload map и bootstrap имеют стоимость. Без файлового helper нет файлового I/O. Ниже измерен только малый общий PHP-путь. Он не выбирает основной сервер и не подтверждает production readiness.

## Проверки

- Пройден verify:full: root 229 tests / 908 assertions, SQL generator 167 / 625, PostgreSQL integration 32 / 272. PHPStan 10, style, docs, SQL synchronization и bundled integrity пройдены; три distributions / 1614 files.
- Реальные FPM/RR: 51 случай × JSON/HTML errors × 2 транспорта = 204. Сохранены исходные 188, добавлены form-urlencoded/query/cookie, raw JSON, nested multipart upload с moveTo и следующий пустой request.
- Body/headers/immutable metadata, malformed URI/status/header, upload errors/повторный move/SAPI validation, запрет чтения HEAD/1xx/204/304, закрытие при transport/logging/read failure, buffered/chunked non-seekable response и mid-stream read failure проверяются реальными внутренними объектами. Внешняя граница RR WorkerInterface изолирована; ObservedStream — настоящий PHP stream wrapper.
- verify:full включает PostgreSQL integration и SQL generator checks; распакованный runtime archive отдельно прошёл 43 HTTP-проверки на SAPI/RR с каталогом, миграциями, rollback/reapply и 21 generated typed row. Legacy classes/interfaces не загружаются из чистого bootstrap.

Полная HTTP-матрица сохраняет длины/hashes больших файлов до 16 MiB + 17 при memory_limit 32M и повторных GET/HEAD. Это ограниченный regular-file сценарий и retained PHP allocation, не RSS/peak или доказательство отсутствия утечек при длительной нагрузке. Проверки входа не являются полным аудитом uploads/proxy/security; server limits и политика файлов остаются у приложения.

## Ограниченное сравнение

PHP 8.5.9 CLI, OPcache CLI выключен, семь пар отдельных процессов с чередованием порядка, предварительно по одному отброшенному процессу. Каждый процесс измеряет bootstrap/composition, затем 10 000 новых GET /health, проверку Content-Type/request ID/status/body и закрытие обоих bodies. БД, сеть и emission отсутствуют. Медианы и raw samples находятся в JSON рядом; PHP memory counters не являются RSS. Измерения не задают FPM/RR p95/p99, throughput или долгоживущую устойчивость.

| Медиана | До (`9c300d3`) | После |
| --- | ---: | ---: |
| Bootstrap/composition, ms | 1.038166 | 1.083583 |
| 10 000 запросов с проверками, ms | 73.214 | 60.24325 |
| PHP bytes после запросов | 883720 | 689416 |
| Peak PHP bytes | 971704 | 736952 |
| Included files после запросов | 45 | 37 |

В этом конкретном CLI-сценарии цикл дешевле, короткий startup-замер не показал улучшения. Общий вывод об ускорении сайта из этих чисел не следует. Собственных runtime PHP-файлов стало 56 вместо 49; уменьшение пакетов сопровождается собственным кодом, тестами и ответственностью за HTTP-контракт.
