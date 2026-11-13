# HTTP-проверки и baseline RoadRunner

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-28.

RoadRunner 3.x — единственный HTTP runtime. PHP app и handler отделены от worker loop для композиции и unit tests; это не обещание других серверных адаптеров. executable RR3 и sockets — платформа, PHP libraries и tools уже включены.

## HTTP: настоящий RoadRunner

Из корня checkout:

```bash
php tools/project.php http:check --rr=/path/to/rr3
```

[Checker](../../tools/check-http.php) создаёт временные конфигурации, свободный loopback port, файлы и один PHP worker с memory_limit=32M/RR_HTTP_CHUNK_SIZE=8192. После запуска останавливает свои процессы и удаляет свои fixtures. БД, package installation/download и дополнительные HTTP-серверы не нужны. Приготовленные JSON/HTML композиции используют настоящие внутренние компоненты; helper внешнего WorkerInterface применяется только в unit tests.

Матрица проверяет routing/405/Allow, HEAD, 204/205/304, повторные headers/Set-Cookie, числовые имена headers, query/cookies/forms/nested upload с числовыми и строковыми ключами (включая name) и moveTo. Request ID и параметры проверяются в последовательных запросах одного worker. Файлы до 16 MiB + 17 байт проверяются по размеру и SHA-256; повторные передачи проверяют ограниченный рост PHP allocations после прогрева. Metadata logging не читает payload, исключения остаются в logger, ответ не раскрывает детали.

Это ограниченная проверка HTTP/1.1. Полное соответствие HTTP, реальные HTTP/2/3, отмена клиента и длительная устойчивость не установлены. 205 Reset Content подавляется без чтения body с нулевой длиной; streams закрываются runtime и для этого статуса.

## Ограниченный JSON

[Ручной POST](../../examples/json-echo/README.md) и [generated Echo](../../examples/contract-echo/README.md) проверяются через общий App на RR3. Размер handler — 65 536 байтов; тестируются соседние границы, invalid JSON/UTF-8/type/media type, ошибки реализации и output encoding, следующий пустой запрос. Временный RR-профиль ограничивает приём 1 MiB: более крупное тело получает 413 до PHP как с Content-Length, так и при chunked передаче. Размер ровно 1 MiB достигает handler и получает его 413. Формат серверного отказа не является JSON-контрактом приложения.

Native RR материализует вход до handler. Body::readLimited ограничивает дальнейшее чтение/decode, не весь transport memory. Запросы и response streams имеют отдельных владельцев; [lifecycle](../architecture/request-lifecycle.md) описывает их завершение.

Опциональный замер конкретного проверяемого пути:

```bash
php tools/project.php http:check --rr=/path/to/rr3 --measure-contract
```

Он сравнивает 100 последовательных manual/generated запросов после 20 warmup pairs, чередуя порядок. Включены socket/routing/metadata logging; это не concurrency/throughput benchmark. Старые межсерверные измерения остаются [историей](../history/2026-10-17-core-http-baseline.md), а [выбор единственного runtime](../history/2026-10-23-roadrunner-only.md) задан пользователем.

## Baseline: что измеряется

[Функция сборки](../../load-tests/baseline/config.php) создаёт Storage без PDO; /json возвращает `{"id":42,"value":"record-42"}` без SQL, /select/42 делает один параметризованный lookup. [database.php](../../load-tests/baseline/database.php) создаёт случайную схему zerno_baseline_<24 hex>, 10 000 записей, ANALYZE и EXPLAIN. SQL/request 0/1 описывает прикладные SELECT, исключая соединение, SET search_path и prepare. HTTP startup миграции не запускает.

## Подготовить одну изолированную схему

Нужна заранее созданная отдельная test database с правом создавать собственные схемы. Укажите TEST_DB_HOST/PORT/NAME/USER/PASSWORD по [правилам изоляции](infrastructure-and-testing.md); не используйте приложение или чужую БД. В одном терминале из корня:

```bash
mkdir -p var
: "${TEST_DB_NAME:?Укажите отдельную test database}"
php tools/project.php perf:prepare > var/baseline-fixture.json
export DB_HOST="$TEST_DB_HOST" DB_PORT="$TEST_DB_PORT"
export DB_NAME="$TEST_DB_NAME" DB_USER="$TEST_DB_USER" DB_PASSWORD="$TEST_DB_PASSWORD"
export DB_SCHEMA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["schema"];' var/baseline-fixture.json)"
rr serve -w "$PWD" -c load-tests/baseline/rr.yaml
```

Продолжайте только после успешного prepare, не перезаписывайте metadata используемой схемы. Сохраняйте свои env/credentials в игнорируемом var с ограниченными правами; другой терминал должен получить те же значения. RR слушает 127.0.0.1:19002 и использует один worker с CLI OPcache.

## Прогрев и измерение

В другом терминале с тем же окружением:

```bash
BASE_URL=http://127.0.0.1:19002 CASE=json DURATION=5s \
SUMMARY_PATH=var/rr-json-warmup.json php tools/project.php perf:baseline
BASE_URL=http://127.0.0.1:19002 CASE=json DURATION=30s \
SUMMARY_PATH=var/rr-json.json php tools/project.php perf:baseline
```

Повторить с CASE=select и отдельными SUMMARY_PATH. Нужен установленный k6, команда не скачивает инструменты. Проверяйте точный результат, статус, errors, throughput и p50/p95/p99; сохраняйте серверные настройки, число workers, PHP/extensions/OPcache, входные данные и CPU/RSS RR server + всех PHP workers. БД и k6 учитывать отдельно. Сумма RSS может повторно учитывать shared pages; snapshot не является peak или доказательством отсутствия роста памяти.

## Завершить свой запуск

Остановите свой RR через Ctrl+C. Только после остановки удалите свою fixture schema:

```bash
php tools/project.php perf:cleanup "$DB_SCHEMA"
```

Cleanup проверяет имя, marker и роль-владельца; чужие схемы/БД/контейнеры не удаляются.

## Смешанный длительный прогон

`load-tests/soak/config.php` явно объединяет существующие baseline и HTTP fixtures. Два worker обслуживают SQL, малый JSON, ручной/generated Echo, последовательные route/query context, GET/HEAD файлов 1 MiB и 16 MiB + 17 байт, 204/205 и ожидаемые 400/404/405/500. SQL исполняется небольшими параллельными сериями по четыре запроса, чтобы нагрузка попадала в оба worker. Wrapper добавляет только диагностические headers с PID, числом запросов и PHP allocations/peak; запросы и body не удерживаются. Это проверочный граф, не минимальная композиция production-приложения.

Нужны заранее подготовленные PHP 8.5.12+/pdo_pgsql/sockets/OPcache, RR3, PostgreSQL и k6. Из source checkout подготовьте свою baseline schema через `perf:prepare`, перенесите её TEST_DB_* в DB_* и DB_SCHEMA по инструкции выше. Подготовьте свои файлы и их hashes:

```bash
mkdir -p var/soak/files
php -r 'require "tools/http-fixtures/AttachmentFiles.php"; echo json_encode(Project\HttpFixtures\AttachmentFiles::prepare($argv[1]), JSON_THROW_ON_ERROR);' var/soak/files > var/soak/hashes.json
export WIRE_FILE_DIRECTORY="$PWD/var/soak/files"
export FILE_HASHES="$(cat var/soak/hashes.json)"
rr serve -w "$PWD" -c load-tests/soak/rr.yaml
```

Конфигурация слушает loopback port 19003, включает два worker без max_jobs recycling, memory_limit=64M и chunk size 8192. В контейнере RR должен быть PID 1; при публикации порта явно переопределите listener `-o http.address=0.0.0.0:19003`. Выбирайте существующий executable/образ платформы: команды проекта ничего не устанавливают. Перезапуск контейнера с автоматически выбранным host port требует повторно определить его через docker port.

В другом терминале с теми же FILE_HASHES и фактическим BASE_URL:

```bash
BASE_URL=http://127.0.0.1:19003 RATE=10 DURATION=60s SUMMARY_PATH=var/soak/warmup.json php tools/project.php perf:soak --quiet
BASE_URL=http://127.0.0.1:19003 RATE=10 DURATION=15m SUMMARY_PATH=var/soak/measure.json php tools/project.php perf:soak --quiet
```

RATE задаёт iterations/s, а не requests/s: серия SQL и пары context увеличивают число HTTP-запросов. Проверяются точные SQL/JSON/file hash результаты, собственный request ID, отсутствие переноса параметров/сессии, PHP telemetry и ожидаемые ошибки. k6 представляет body 204 как null; 205 имеет пустую строку. Запрет реальных bytes дополнительно проверяет raw http:check. Thresholds требуют всех checks, отсутствия сетевых/неожиданных HTTP failures и пропущенных iterations; ожидаемый 500 сам по себе не является transport failure, его status/privacy проверяются отдельно.

Для Linux-контейнера с RR как PID 1 [sample.php](../../load-tests/soak/sample.php) снимает /proc RSS, kernel peak RSS, CPU ticks, start time и число FD для server и всех PHP workers, исключая собственный процесс. Например, в отдельном терминале выполните конечную серию из 194 samples с интервалом 5 s, охватывающую прогрев и измерение:

```bash
for sample in $(seq 1 194); do
    docker exec "$SOAK_CONTAINER" php load-tests/soak/sample.php >> var/soak/processes.jsonl
    sleep 5
done
```

SOAK_CONTAINER — только созданный вами сервер. Узнайте CLK_TCK через getconf внутри него; CPU seconds = разность cpu_ticks / CLK_TCK. Сопоставляйте pid вместе со start_ticks, чтобы не пропустить restart или повторное использование PID. Сумма RSS включает общие страницы повторно; Zend allocations, kernel RSS/peak и память Docker VM — разные показатели. PostgreSQL и k6 учитывать отдельно. Сравните первые и последние 60 s после прогрева, extrema и FD, проверьте активность каждого worker. Нагрузку/срок, image/binary hashes, PHP flags и source hashes сохраняйте вместе с результатом. После остановки своего RR удалите только свою baseline schema через perf:cleanup и принадлежащие вам fixtures.

[Отчёт от 2026-10-28](../history/2026-10-28-runtime-soak.md) и JSON сохраняют финальный прогон на подготовленной платформе. Пятнадцать минут подтверждают только наблюдаемое поведение этого сценария. Они не устанавливают многодневную устойчивость, пригодность любого stream, поведение при потере БД/отмене клиента или production SLO. Числовые ориентиры сравнимы только с тем же результатом, платформой и нагрузкой.

## Стоимость файлового пути

[Сценарий файлов](../../load-tests/files/scenario.js) выполняет последовательные GET/HEAD attachment fixture с точным SHA-256. Подготовка:

```bash
mkdir -m 700 -p var/file-benchmark
php -r 'require "tools/http-fixtures/AttachmentFiles.php"; echo json_encode(Project\HttpFixtures\AttachmentFiles::prepare($argv[1]), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);' var/file-benchmark > var/file-hashes.json
```

Запустите RR3 с явными fixture-настройками:

```bash
rr serve -w "$PWD" -c .rr.yaml \
  -o server.env.APP_CONFIG=tools/http-fixtures/config.php \
  -o server.env.WIRE_FILE_DIRECTORY=var/file-benchmark \
  -o server.env.RR_HTTP_CHUNK_SIZE=8192
```

WIRE_HTTP_LOGGING=0 даёт контрольный запуск без logging middleware; default включает его. Это fixture приложения, не настройки ядра. Передавайте BASE_URL своего RR, FILE_SIZE, EXPECTED_SHA256, DURATION и отдельный SUMMARY_PATH в k6 run load-tests/files/scenario.js; METHOD=HEAD проверяет пустой body при том же Content-Length. k6 материализует body на стороне клиента, его память не является памятью сервера. Старые [данные файлового этапа](../history/2026-10-18-file-responses.md) описывают прежнюю конфигурацию и не задают текущий состав.
