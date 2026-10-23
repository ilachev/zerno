# HTTP-проверки и baseline RoadRunner

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-23.

RoadRunner 3.x — единственный HTTP runtime. PHP app и handler отделены от worker loop для композиции и unit tests; это не обещание других серверных адаптеров. executable RR3 и sockets — платформа, PHP libraries и tools уже включены.

## HTTP: настоящий RoadRunner

Из корня checkout:

```bash
php tools/project.php http:check --rr=/path/to/rr3
```

[Checker](../../tools/check-http.php) создаёт временные конфигурации, свободный loopback port, файлы и один PHP worker с memory_limit=32M/RR_HTTP_CHUNK_SIZE=8192. После запуска останавливает свои процессы и удаляет свои fixtures. БД, package installation/download и дополнительные HTTP-серверы не нужны. Приготовленные JSON/HTML композиции используют настоящие внутренние компоненты; helper внешнего WorkerInterface применяется только в unit tests.

Матрица проверяет routing/405/Allow, HEAD, 204/304, повторные headers/Set-Cookie, числовые имена headers, query/cookies/forms/nested upload и moveTo. Request ID и параметры проверяются в последовательных запросах одного worker. Файлы до 16 MiB + 17 байт проверяются по размеру и SHA-256; повторные передачи проверяют ограниченный рост PHP allocations после прогрева. Metadata logging не читает payload, исключения остаются в logger, ответ не раскрывает детали.

Это ограниченная проверка HTTP/1.1. Полное соответствие HTTP, реальные HTTP/2/3, отмена клиента и длительная устойчивость не установлены. В частности, общий adapter пока не подавляет тело для 205 Reset Content; это отдельный известный пробел HTTP-семантики, не исправление данной смены состава.

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
