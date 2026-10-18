# Проверка HTTP и малый performance baseline

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-18.

Здесь два отдельных действия: проверка HTTP на настоящих adapters и небольшой воспроизводимый замер JSON/SELECT. Они запускаются явно, не входят в default `verify` и не устанавливают зависимости. Общие команды и тестовая БД описаны в [инфраструктуре](./infrastructure-and-testing.md), границы измерений — в [принципах](../project/principles.md) и [roadmap](../project/roadmap.md).

## HTTP: настоящие FPM и RoadRunner

Для выбранного runtime нужен его установленный executable. Caddy, k6 и БД этой проверке не нужны:

```bash
php tools/project.php http:check
php tools/project.php http:check --runtime=fpm
php tools/project.php http:check --runtime=rr
php tools/project.php http:check --runtime=all --php-fpm=/path/to/php-fpm --rr=/path/to/rr
```

[`tools/check-http.php`](../../tools/check-http.php) запускает собственные процессы на временных loopback-портах. Для FPM клиент говорит непосредственно по FastCGI, для RR — по HTTP. Конфигурации и логи временные; tool останавливает созданные им процессы и убирает временный каталог. Он не останавливает чужие серверы.

Сейчас выполняются по 27 случаев с default JSON и с HTML-представлением ошибок: 54 для каждого runtime, 108 при выборе обоих. Проверяются статические и параметризованные маршруты, приоритет статического пути, literal `.`/`+`, path/query separation, принятые правила trailing slash, 404/405/500 и `Allow`, HEAD для успешного ответа и ошибок, отсутствие тела для 204/304, повторные headers/Set-Cookie. HTML-профиль использует реальный `Errors` каталога через общий `ErrorResponse`; успешные fixture responses остаются JSON. Исключение handler не раскрывается в ответе; следующие запросы проходят тем же worker. Последовательные запросы проверяют request ID и route parameters без переноса контекста. Конфигурация задаёт недоступный storage engine: БД, Session и ApiStats не включаются. Это проверки корректности, без измерения ускорения.

Это конкретная wire-матрица, а не полная проверка HTTP, uploads, reverse proxy, безопасности или памяти больших файлов. Исходники — [`tools/http-fixtures`](../../tools/http-fixtures); поведение router объясняет [жизненный цикл запроса](../architecture/request-lifecycle.md).

## Baseline: что сравнивается

Нужны PHP 8.5 с `pdo_pgsql` и OPcache, PHP-FPM, `rr`, Caddy, k6 и доступная отдельная PostgreSQL test database. Это требования платформы; команды проекта не создают сервер БД и не скачивают executables или пакеты. Запускайте процессы обычным пользователем из корня checkout.

Две конфигурации расширяют минимальный core: [`config.php`](../../load-tests/baseline/config.php) для SAPI/FPM и [`roadrunner.php`](../../load-tests/baseline/roadrunner.php) с обычным RR provider. Capabilities и API migrations не подключаются.

| Сценарий | Работа приложения | Точный ответ |
| --- | --- | --- |
| `CASE=json`, `GET /json` | Не разрешает `Storage`, не открывает PDO | `{"id":42,"value":"record-42"}` |
| `CASE=select`, `GET /select/42` | Один параметризованный lookup по primary key, до одной строки | `{"id":42,"value":"record-42"}` |

[`database.php`](../../load-tests/baseline/database.php) создаёт случайную схему `zerno_baseline_<24 hex>`, таблицу `baseline_records` с 10 000 детерминированных записей, выполняет `ANALYZE` и сохраняет `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`. Подготовка проверяет индексный lookup с одной строкой. Результат содержит schema, размер fixture и план, без пароля; приложение миграции не запускает.

SQL/request равен 0/1 **прикладных SELECT по устройству handlers**, а не по внешнему SQL tracer. Эта величина исключает соединение, `SET search_path` и prepare. FPM создаёт PDO/statement и пишет сообщение о создании storage на каждом SELECT-request; RR сохраняет их в worker. Обычные logger implementations двух профилей тоже различаются. Эти затраты остаются частью end-to-end профиля, их не выравнивают искусственными persistent connections или заменой logger.

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
export STORAGE_ENGINE=pgsql
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

Оба профиля используют один PHP worker. [FPM config](../../load-tests/baseline/php-fpm.conf) включает OPcache; [RR config](../../load-tests/baseline/rr.yaml) включает его для CLI и выбирает свой `roadrunner.php` через `APP_CONFIG`. Общий [Caddyfile](../../load-tests/baseline/Caddyfile) использует один front process для двух протоколов backend. OPcache/JIT и прочие настройки унаследованного PHP ini всё равно нужно сохранить в metadata и проверить на сопоставимость.

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
