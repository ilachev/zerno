# Проверка HTTP и малый performance baseline

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-20.

Здесь два отдельных действия: проверка HTTP на настоящих adapters и небольшой воспроизводимый замер JSON/SELECT. Они запускаются явно, не входят в default `verify` и не устанавливают зависимости. Общие команды и тестовая БД описаны в [инфраструктуре](./infrastructure-and-testing.md), границы измерений — в [принципах](../project/principles.md) и [roadmap](../project/roadmap.md).

## HTTP: настоящие FPM и RoadRunner

Для выбранного runtime нужен его установленный executable; RoadRunner target — 3.x, проверен 3.0.0. Путь можно выбрать --rr=/path/to/rr, обычные rr/run команды используют PATH. По умолчанию Caddy, k6 и БД этой проверке не нужны:

```bash
php tools/project.php http:check
php tools/project.php http:check --runtime=fpm
php tools/project.php http:check --runtime=rr
php tools/project.php http:check --runtime=all --php-fpm=/path/to/php-fpm --rr=/path/to/rr
php tools/project.php http:check --rr=/path/to/rr3 --caddy=/path/to/caddy
```

[`tools/check-http.php`](../../tools/check-http.php) запускает собственные процессы на временных loopback-портах. Для FPM клиент говорит непосредственно по FastCGI, для RR — по HTTP. Конфигурации и логи временные; tool останавливает созданные им процессы и убирает временный каталог. Он не останавливает чужие серверы.

Базовые 51 случай выполняются с default JSON и с HTML-представлением ошибок. С ограниченным POST JSON получается 80 случаев на профиль FPM и 96 на RR: 352 при выборе обоих runtimes. `--caddy=/path/to/caddy` добавляет по 45 HTTP/FPM ingress checks на профиль, всего 442. Каждый профиль включает реальный HttpLoggingMiddleware; RR fixture явно выбирает chunk 8192. Проверяются статические и параметризованные маршруты, приоритет статического пути, literal `.`/`+`, path/query separation, принятые правила trailing slash, 404/405/500 и `Allow`, HEAD для успешного ответа и ошибок, отсутствие тела для 204/304, повторные headers/Set-Cookie и числовое имя заголовка 123 в запросе/ответе. HTML-профиль использует реальный `Errors` каталога через общий `ErrorResponse`; успешные fixture responses остаются JSON. Исключение handler не раскрывается в ответе; следующие запросы проходят тем же worker. Последовательные запросы проверяют request ID и route parameters без переноса контекста. Конфигурация задаёт недоступный адрес PostgreSQL (`127.0.0.1:1`): HTTP-сценарии должны проходить без открытия БД. Для RR fixture общий logs.level=error, channels.server.level=info: tool проверяет сохранение PHP metadata/error logs и production envelope time/INFO. Это проверки корректности, без измерения ускорения.

Из базовых 51 случая 20 на профиль проверяют GET/HEAD attachment для 0, 8191, 8192, 8193, 1 MiB и 16 MiB + 17 байт и четыре повторных больших GET/HEAD в одном worker. Проверяются длина, SHA-256, ASCII/UTF-8 Content-Disposition, no-store/nosniff и отсутствие session. Server memory_limit — 32M; после прогрева retained PHP allocation не должна вырасти больше чем на 2 MiB. Это проверка выбранного seekable-file path, а не универсальный memory budget: клиент отдельно буферизует body с ограничением 32 MiB, и этот предел не является RSS или peak server memory.

Ещё четыре базовых случая на профиль проверяют POST form-urlencoded с query/cookie, исходный JSON body, вложенный multipart upload с реальным moveTo/закрытием body и следующий пустой GET без переноса данных. Это конкретная wire-матрица, а не полная проверка HTTP, всех upload failures, reverse proxy или безопасности. Исходники — [`tools/http-fixtures`](../../tools/http-fixtures); поведение router объясняет [жизненный цикл запроса](../architecture/request-lifecycle.md).

## Ограниченный JSON и HTTP front

[JSON-пример](../../examples/json-echo/README.md) задаёт лимит 65 536 байт в handler и отдельный cap 1 MiB на сервере. Общая матрица добавляет 29 запросов на профиль: 14 POST-вариантов и проверку следующего GET в том же worker после каждого; один GET фиксирует PID до серии. Проверяются 65 535/65 536/65 537 байт, Unicode, параметры media type, некорректные JSON/UTF-8/поля, пустой body, 400/413/415 и request ID. Эти ответы определяет handler независимо от HTML-представления общих ошибок.

RR дополнительно проверяет восемь POST на границах 64 KiB и 1 MiB с Content-Length и chunked, затем восемь GET в том же worker. Тело ровно 1 MiB достигает PHP, 1 MiB + 1 — отклоняется до него. Temporary RR config задаёт max_request_size=1 явно.

Без `--caddy` FPM-клиент проверяет FastCGI и Content-Length. С флагом tool запускает Caddy на своём loopback-порту с [профилем примера](../../examples/json-echo/Caddyfile), подставляя временный FPM-порт. Эта серия повторяет 29 + 16 проверок через настоящий HTTP front и охватывает chunked без Content-Length. Буфер front ограничен, чтение имеет timeout. Заведомо большой Content-Length отклоняется до FastCGI; тест с Expect: 100-continue требует финального отказа без начала загрузки body. Это устраняет зависание FPM после прерванной передачи слишком большого тела. Access log FPM с исходным REQUEST_URI отдельно подтверждает отсутствие отклонённых запросов в PHP; одного отсутствия response request ID для этого недостаточно. В проверенном Caddy 2.11.4 превышение 1 MiB даёт 413 для известной длины и 411 для chunked, оба до PHP; это ограничение профиля зафиксировано в README примера. Формат серверного отказа не является JSON API приложения.

Unit tests отдельно проверяют строку, файл и non-seekable stream, точный предел, отрицательный/нулевой лимит, PHP_INT_MAX, ошибки I/O и отсутствие прогресса. Ошибка I/O доходит до общего ErrorHandler как 500, handler закрывает body. Метод не полагается на заявленный Content-Length или getSize; ресурсный тест подтверждает остановку файла 1 MiB на позиции 65 537 при лимите 64 KiB. Это не load test и не обещание потокового входа RR: его transport буферизует payload заранее.

## Baseline: что сравнивается

Нужны PHP 8.5 с `pdo_pgsql` и OPcache, PHP-FPM, `rr`, Caddy, k6 и доступная отдельная PostgreSQL test database. Это требования платформы; команды проекта не создают сервер БД и не скачивают executables или пакеты. Запускайте процессы обычным пользователем из корня checkout.

Одна [`функция config.php`](../../load-tests/baseline/config.php) явно собирает объекты для SAPI/FPM и RoadRunner; точки входа выбирают logger. Прикладные миграции не запускаются при старте HTTP.

| Сценарий | Работа приложения | Точный ответ |
| --- | --- | --- |
| `CASE=json`, `GET /json` | Создаёт граф с Storage, но не открывает PDO | `{"id":42,"value":"record-42"}` |
| `CASE=select`, `GET /select/42` | Один параметризованный lookup по primary key, до одной строки | `{"id":42,"value":"record-42"}` |

[`database.php`](../../load-tests/baseline/database.php) создаёт случайную схему `zerno_baseline_<24 hex>`, таблицу `baseline_records` с 10 000 детерминированных записей, выполняет `ANALYZE` и сохраняет `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`. Подготовка проверяет индексный lookup с одной строкой. Результат содержит schema, размер fixture и план, без пароля; приложение миграции не запускает.

SQL/request равен 0/1 **прикладных SELECT по устройству handlers**, а не по внешнему SQL tracer. Эта величина исключает соединение, `SET search_path` и prepare. FPM создаёт PDO/statement на каждом SELECT-request; RR сохраняет их в worker. Обычные logger implementations двух профилей тоже различаются. Эти затраты остаются частью end-to-end профиля, их не выравнивают искусственными persistent connections или заменой logger.

## Подготовить одну изолированную схему

Оператор заранее создаёт отдельную test database и предоставляет роли возможность создавать свои схемы. `perf:prepare` использует только явно выбранные `TEST_DB_*`, требует `TEST_DB_NAME` и не выбирает runtime БД из `DB_*`. Значения ниже — пример для уже существующей локальной БД; замените их параметрами своего стенда.

Локальный env-файл нужен, чтобы три терминала получили одинаковые значения. Он содержит тестовый пароль и остаётся в игнорируемом `var/` вместе с результатами:

```bash
mkdir -p var
umask 077
cat > var/baseline.env <<'ENV'
export TEST_DB_HOST=127.0.0.1
export TEST_DB_PORT=5432
export TEST_DB_NAME=app_test
export TEST_DB_USER=app
export TEST_DB_PASSWORD=password
ENV
. var/baseline.env
php tools/project.php perf:prepare > var/baseline-fixture.json
```

Продолжайте после успешного `perf:prepare`; не перезаписывайте metadata ещё используемой схемы. Дополните тот же env-файл отображением настроек приложения:

```bash
cat >> var/baseline.env <<'ENV'
export DB_HOST="$TEST_DB_HOST"
export DB_PORT="$TEST_DB_PORT"
export DB_NAME="$TEST_DB_NAME"
export DB_USER="$TEST_DB_USER"
export DB_PASSWORD="$TEST_DB_PASSWORD"
export DB_SCHEMA="$(php -r '$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $data["schema"];' var/baseline-fixture.json)"
export ZERNO_ROOT="$PWD"
export APP_CONFIG="$PWD/load-tests/baseline/config.php"
ENV
. var/baseline.env
```

Проверьте, что `DB_SCHEMA` получен из успешного prepare. Настройки действуют только для этого стенда; публичный `/json` работает без разрешения SQL-сервиса, но `/select/42` требует подготовленной таблицы.

## Запустить два runtime и общий HTTP front

В каждом терминале перейдите в корень того же checkout. Терминал FPM:

```bash
. var/baseline.env
php-fpm -p "$PWD" -y load-tests/baseline/php-fpm.conf -F
```

Терминал RoadRunner:

```bash
. var/baseline.env
rr serve -w "$PWD" -c load-tests/baseline/rr.yaml
```

Терминал Caddy:

```bash
export ZERNO_ROOT="$PWD"
caddy run --config load-tests/baseline/Caddyfile
```

| Путь | Вход k6 | Backend |
| --- | --- | --- |
| FPM через Caddy FastCGI | `http://127.0.0.1:18081` | FPM `127.0.0.1:19001` |
| RR через Caddy HTTP proxy | `http://127.0.0.1:18082` | RR `127.0.0.1:19002` |

Оба профиля используют один PHP worker. Текущий RR-профиль предназначен для RR3; прежние исторические измерения RR2025 не являются результатами нового server. [FPM config](../../load-tests/baseline/php-fpm.conf) включает OPcache; [RR config](../../load-tests/baseline/rr.yaml) включает его для CLI и выбирает тот же `config.php` через `APP_CONFIG`. Общий [Caddyfile](../../load-tests/baseline/Caddyfile) использует один front process для двух протоколов backend. OPcache/JIT и прочие настройки унаследованного PHP ini всё равно нужно сохранить в metadata и проверить на сопоставимость.

Все порты loopback. Если они заняты, выберите свободные и согласованно измените локальные sample configs и `BASE_URL`; не останавливайте чужие сервисы. Возможен запуск runtime по очереди с тем же Caddy front. Не запускайте две нагрузки одновременно.

## Прогрев и измерение

В отдельном терминале выберите один профиль и сценарий. Пример FPM/JSON с одним VU:

```bash
BASE_URL=http://127.0.0.1:18081 CASE=json VUS=1 DURATION=2s SUMMARY_PATH=var/fpm-json-warmup.json \
php tools/project.php perf:baseline

BASE_URL=http://127.0.0.1:18081 CASE=json VUS=1 DURATION=10s SUMMARY_PATH=var/fpm-json-summary.json \
php tools/project.php perf:baseline --out json=var/fpm-json-raw.json
```

Повторите отдельно для `CASE=select`, затем для RR с `BASE_URL=http://127.0.0.1:18082`. Используйте отдельные имена `fpm-select`, `rr-json`, `rr-select` для файлов. Перед каждым измерением выполняйте такой же отдельный прогрев соответствующего случая. Длительности 2s/10s — короткие параметры воспроизведения, а не критерий достаточной статистики или бюджет задержки; для сравнения параметры нагрузки и прогрева должны совпадать.

[`scenario.js`](../../load-tests/baseline/scenario.js) имеет defaults `CASE=json`, `VUS=1`, `DURATION=10s`. Один запрос выполняется за итерацию без искусственной паузы; redirect не следует автоматически. Проверяются status 200, content type, точный body и отсутствие Set-Cookie. Пороги касаются только ошибок и корректности; latency threshold не задан. `SUMMARY_PATH` выбирает JSON summary, иначе он выводится в stdout. Runner отключает usage reporting k6; `--out json=...` сохраняет отдельные raw samples. Не включайте `--discard-response-bodies`: проверка точного body нужна для валидного результата.

Сохраняйте summary и raw samples отдельно от прогрева. По summary доступны задержки p50/p95/p99, throughput и ошибки при указанном числе VU. Это closed-loop нагрузка, а не заданный входящий RPS и не доказательство предельной пропускной способности.

## Metadata, стоимость процессов и границы вывода

Для сравнения сохраните revision и локальные изменения, ОС/CPU, версии PHP/FPM/RR/Caddy/k6/PostgreSQL, PHP extensions/ini и OPcache/JIT, worker count, fixture JSON/EXPLAIN, URL/CASE/VUS/duration и прогрев. Укажите, что Caddy, генератор нагрузки и БД работают локально или в другом окружении. Raw outputs и локальный env-файл в `var/` не коммитятся.

При доступных process counters снимайте CPU time и RSS до/во время/после измерения для всего активного профиля: FPM master + child либо RR server + PHP worker, а также общего Caddy. k6 и БД учитываются отдельно. Не сравнивайте один PHP PID с полным pool другой модели и не включайте без пояснения второй неактивный runtime. Сумма RSS может повторно учитывать shared pages; отдельный snapshot не является peak и не показывает длительный рост. Если CPU/RSS надёжно не собраны, отметьте отсутствие измерения, а не записывайте ноль.

Это первый малый baseline двух реальных профилей с общим front, различающимися transport/lifecycle/logger затратами. Он не задаёт числовых бюджетов, не выбирает основной runtime и не заменяет HTML/файловый сценарий или длительный прогон. Fixtures находятся в [`load-tests/baseline`](../../load-tests/baseline) и `tools/http-fixtures`, входят в root style/PHPStan checks и не включаются в runtime-архив.

## Завершить свой запуск

Остановите созданные вами foreground процессы FPM, RR и Caddy через Ctrl-C в соответствующих терминалах. После остановки серверов удалите только свою схему:

```bash
. var/baseline.env
php tools/project.php perf:cleanup "$DB_SCHEMA"
```

Cleanup проверяет формат имени, baseline marker и принадлежность текущей DB-роли. Он не удаляет БД или контейнер PostgreSQL и не убирает чужие схемы. Metadata/результаты можно сохранить для сравнения; подготовка следующей схемы — отдельный запуск.

## Стоимость файлового пути

[`load-tests/files/scenario.js`](../../load-tests/files/scenario.js) выполняет один последовательный GET или HEAD attachment fixture, проверяет headers и точный SHA-256 body. Он требует заранее подготовленные private файлы и сервер с `tools/http-fixtures/config.php` на FPM/RR; для RR выбрать RR_HTTP_CHUNK_SIZE=8192, как в http:check. Это development fixture без БД. `WIRE_FILE_DIRECTORY` задаёт её private directory; `WIRE_HTTP_LOGGING=0` исключает только logging middleware для сопоставимого контрольного запуска (default включает его). Эти переменные не относятся к конфигурации core/каталога.

Подготовка из корня, без загрузки зависимостей:

```bash
mkdir -m 700 -p var/file-benchmark
php -r 'require "tools/http-fixtures/AttachmentFiles.php"; echo json_encode(Project\HttpFixtures\AttachmentFiles::prepare($argv[1]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);' var/file-benchmark > var/file-hashes.json
```

После запуска собственного сервера и HTTP front, для каждого варианта отдельно:

```bash
BASE_URL=http://127.0.0.1:18081 FILE_SIZE=1048576 \
EXPECTED_SHA256="$(php -r '$h = json_decode(file_get_contents("var/file-hashes.json"), true, 512, JSON_THROW_ON_ERROR); echo $h[1048576];')" \
DURATION=2s SUMMARY_PATH=var/file-warmup.json \
k6 run --no-usage-report --quiet load-tests/files/scenario.js
```

Затем повторить с `DURATION=5s` и отдельным SUMMARY_PATH. `METHOD=HEAD` проверяет пустой body при том же Content-Length. Один VU задан сценарием; нагрузка не перекрывается с другими проверками/замерами. k6 материализует body на стороне клиента; его память и CPU не следует приписывать серверу. Собирать CPU/RSS всего runtime (master/server и PHP worker) и front отдельно; доступные counters, log bytes и пределы интерпретации фиксировать. В [отчёте 2026-10-18](../history/2026-10-18-file-responses.md) есть серверные конфигурации, результаты с/без диагностики, длительная серия и ограничения стенда. Файлы удаляются только после остановки собственных серверов.
