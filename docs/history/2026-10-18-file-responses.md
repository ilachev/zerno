# Файловый HTTP-ответ и прозрачная диагностика

Тип: `history`. Статус: `historical`. Проверено: 2026-10-18.

Итерация на базе `31bd2b2`: общий AttachmentResponse, metadata-only HttpLogging, явное закрытие SAPI body и существующий download каталога как потребитель. [Контракт](../architecture/request-lifecycle.md#файловый-attachment-и-владение-потоком) описывает API, подключение, владение и границы; [HTTP guide](../development/http-and-baseline.md#стоимость-файлового-пути) — повторяемые команды. Bundled код, SQL/схема и generated artifacts не менялись.

## Результат и цена

| Цель | Результат | Цена и ограничение |
| --- | --- | --- |
| Простота | Один статический helper вместо сборки attachment headers в handler; без DI registration или новых interfaces | Caller передаёт открытый stream и проверенный размер; ownership передаётся явно, новый stream на запрос |
| Функциональность | ASCII/UTF-8 имена, безопасные header values, GET/HEAD, закрытие при success/failure и диагностика без изменения body | Document, published, private FileStore, PDF и no-store остаются приложением; Range/upload/validators не добавлены |
| Производительность | Payload не читается ради лога; bounded transfer проверен на FPM и RR chunk 8192 с логированием | Две log records и metadata stream имеют цену; default RR 0 материализует body, произвольный streaming RR не заявляется |

Лог больше не содержит payload/preview. Это изменение response log context ради предсказуемой диагностики файлов и одноразовых streams; query log сохранён. `body_size` означает PSR metadata (`null` допустим, socket Nyholm может сообщить `0`), не отправленные bytes. Получение metadata может вызвать `fstat`; чтение payload, seek и rewind middleware не выполняет. Без helper/middleware дополнительные DI или файловые обращения не появляются. Общий bootstrap сохраняет прежнюю стоимость; абсолютный нулевой overhead не измерялся.

## Проверки

- `php tools/project.php verify`: 361 unit tests / 1359 assertions, PHPStan level 10, style, integrity 1945 bundled files, docs и SQL synchronization — OK.
- `php tools/project.php verify:integration`: 38 tests / 361 assertions и PostgreSQL SQL check — OK; из них каталог 12/174.

- Unit: filename/header injection, invalid UTF-8, separators/controls, отрицательный размер, construction failure closure, позиция stream и non-seekable body.
- HttpLogging: GET/HEAD в начале/середине/EOF, реальный socket, неизвестный размер, logger failure и закрытие body.
- SAPI: seekable/non-seekable, bounded reads, HEAD/1xx/204/304 без чтения, закрытие при success/read failure/headers_sent.
- RR: реальный PSR7Worker, chunk 8192 с Pipeline/HttpLogging, точные 16401 bytes и chunks; закрытие реального ресурса при success и transport failure после первого chunk.
- `http:check`: 188 случаев, по 47 на FPM/RR × JSON/HTML. Из них 20 файловых на профиль: GET/HEAD 0, 8191, 8192, 8193, 1 MiB, 16 MiB + 17 bytes и четыре повторных больших GET/HEAD. Проверяются exact length/SHA-256, headers, отсутствие session и один PID. Worker memory_limit=32M; после прогрева retained PHP allocation растёт не больше 2 MiB. Клиент буферизует ограниченный body отдельно от сервера.
- Дополнительный реальный каталог с логированием: 52 HTTP cases (26 на runtime), PDF 512/8191/8192/8193/1048576/16777233 bytes, повторные большие GET/HEAD и скрытые/missing/truncated записи с 404; точные SHA-256 и прикладные headers сохранены. Композиция добавляет HttpLogging после Routing к реальному catalog config, RR provider 8192. Это отдельный preflight вне измеряемой нагрузки.
- Каталог: PostgreSQL tests проверяют published/hidden, file unavailable/size mismatch, attachment headers, HEAD и Range→полный 200. БД создана в собственном container из cached `postgres:16-alpine` без pull; тесты используют случайные изолированные схемы.

## Измерение

Сравнивается один и тот же development fixture без SQL: открытие private regular file → AttachmentResponse → выбранный Pipeline → FPM/SAPI или RR chunk 8192 → Caddy → k6. Внутри каждого runtime меняется только наличие HttpLoggingMiddleware, logger сохраняется штатным (FPM ReadableOutputLogger / RR RoadRunnerLogger). Код старого небезопасного логирования в нагрузку не возвращался; эти результаты показывают стоимость включения новой диагностики, а не ускорение относительно старого payload log.

macOS/arm64, Apple M3 Max, PHP CLI/FPM 8.5.9, RR 2025.1.15, Caddy 2.11.4, k6 2.2.0. Один PHP worker на профиль, memory_limit=32M, OPcache включён в FPM и RR CLI, JIT выключен. Private файлы подготовлены AttachmentFiles один раз, содержат повтор всех bytes 0..255; page cache прогрет. Local Caddy и k6 работают на том же host. Host CPU не изолирован, параллельная работа других чатов/процессов не контролировалась; собственные проверки и нагрузки не перекрывались.

Для каждой пары runtime/logging — три повтора: 2 s прогрева и 5 s измерения, один VU и точная проверка SHA-256 каждого body 1 MiB. Во втором повторе порядок runtime и logging обратный. Closed-loop RPS не задаёт входящий поток и не устанавливает предел сервера. CPU — delta суммарного `ps time` runtime server/master + PHP child; RSS — сумма их `ps rss`, samples примерно раз в 100 ms плюс стоимость вызова ps. Caddy собран отдельно, k6 исключён. Сумма RSS может повторно считать shared pages; sampled max не является строгим OS peak.

Медианы трёх повторов по 5 s для body 1 MiB (RPS из k6; CPU всего runtime на запрос; RSS — максимальный sampled sum по трём запускам):

| Профиль | Logging | RPS | p50 / p95 / p99, ms | CPU, ms/request | RSS, MiB | Runtime log, bytes/request |
| --- | --- | --- | --- | --- | --- | --- |
| FPM | off | 149.27 | 5.913 / 9.264 / 10.038 | 2.530 | 38.97 | 0 |
| FPM | on | 148.40 | 6.046 / 7.390 / 10.208 | 2.642 | 39.00 | 203 |
| RR 8192 | off | 41.19 | 23.688 / 24.789 / 25.281 | 31.353 | 93.44 | 286 |
| RR 8192 | on | 41.31 | 23.635 / 24.596 / 24.912 | 31.401 | 93.34 | 627 |

RR info-level runtime также пишет собственные request records: рост лога включает их, добавление HTTP middleware увеличило его примерно на 341 bytes/request. FPM добавил 203 bytes/request. Объём диагностического вывода зависит от metadata/обрамления, не от размера payload. Штатные logger реализации не выравнивались искусственно. Caddy в этих же запусках потреблял медианно 3.989 ms CPU/request для FPM и 6.377–6.425 ms для RR, sampled RSS до 47.17 / 45.27 MiB соответственно; его цена не включена в колонку CPU runtime.

Отдельные серии 30 s с логированием и body 16 MiB + 17 bytes: FPM 492, RR 81 точных GET, без ошибок и с последующим HEAD в том же worker. PHP allocated memory до/после — 2/2 MiB FPM и 4/4 MiB RR. Runtime RSS start/end/sampled max: FPM 38.86/38.86/38.86 MiB, RR 93.83/95.19/95.19 MiB. CPU за серию: FPM runtime 11.22 s + Caddy 29.03 s, RR runtime 39.93 s + Caddy 8.14 s. Суммарный CPU может превышать wall time из-за нескольких потоков Go. У RR небольшое изменение RSS остаётся; 30 s не устанавливает долгосрочного отсутствия утечек. Отдельный контроль всего PHP пути после отправки (10 больших GET/HEAD на runtime) показал peak allocated memory 2 MiB FPM и 4 MiB RR при body 16 MiB + 17 bytes. Для FPM счётчик снимался в shutdown после emitter, для RR — перед следующим запросом, который наблюдает peak предыдущей передачи. Эти данные исключают полную материализацию выбранного body в PHP; RSS Go server имеет отдельную стоимость. Измерительная обёртка и все показания сохранены в JSON; она не участвовала в latency/CPU сериях.

Все проверки k6 прошли (checks 100%, http_req_failed 0), log и переданные bytes сохранены по каждому повтору. Результаты и точные конфигурации — в [JSON](2026-10-18-file-responses.json). Числа не выбирают основной runtime и не подтверждают ускорение: конфигурации, logger, lifecycle и protocol у FPM/RR различаются. Малые различия logging on/off находятся на уровне короткого локального замера; статистически значимого выигрыша не заявлено.

## Воспроизведение и границы

`php tools/project.php http:check` самостоятельно готовит private files, выбирает временные loopback ports, запускает и останавливает только свои процессы. Для измерения подготовить файлы по HTTP guide, взять server/front configs из JSON и заменить `@ROOT@` абсолютным путём своего checkout. Запустить свой FPM или RR и Caddy; APP_CONFIG, WIRE_FILE_DIRECTORY, WIRE_HTTP_LOGGING, ENVIRONMENT и STORAGE_ENGINE также указаны в JSON. Порты согласовать со свободными локальными; чужие процессы не останавливать.

Использовать `load-tests/files/scenario.js`, BASE_URL своего front, FILE_SIZE=1048576, EXPECTED_SHA256 из prepared hashes, `DURATION=2s` / `DURATION=5s` и отдельный SUMMARY_PATH. Затем повторить при WIRE_HTTP_LOGGING=0/1 с fresh runtime; не смешивать прогрев с измерением. Для длительной проверки — FILE_SIZE=16777233, logging=1, 2 s прогрева и 30 s измерения. До/после проверить HEAD, headers PID и выделенную PHP-память, снимать полные runtime/front process counters. Логи и raw summaries хранить локально в игнорируемом `var/`.

Измерены переданные logical body bytes (exact size×requests) и рост runtime log file; physical disk/page-cache I/O syscall tracer не собирался. HEAD wire проверяет отсутствие отправленного body, отсутствие чтения подтверждается unit tests на всём relevant middleware/transport пути. Это ограниченный локальный прогон стабильных seekable files, без гарантии произвольного streaming, client abort, изменения файла во время отправки или длительной эксплуатации. Bundled RR некоторые read failures подавляет; `finally close` гарантирует освобождение при возвращении/исключении, но не исправляет этот upstream transport contract. Default RR 0 остаётся buffered. Формат ошибок, авторизация каталога, PDF preparation и storage permissions этой итерацией не расширены.
