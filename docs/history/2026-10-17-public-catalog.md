# Публичный каталог: реализация, проверки и замеры

Тип: `history`. Статус: `historical`. Проверено: 2026-10-17.

Публичный этап работает: HTML-список → карточка → скачивание опубликованного PDF, без JavaScript, сессий и установки пакетов. Это отдельный пример, а не CMS в ядре. [README](../../examples/document-catalog/README.md) содержит полный запуск; редактор и публикационный workflow остаются [планом](../project/document-catalog-example.md).

Измерено состояние после `30866ad` с изменениями этой итерации. [JSON отчёта](2026-10-17-public-catalog.json) содержит SHA-256 исходников, окружение, конфигурации серверов, SQL-планы, все 12 k6 summaries и файловые проверки. Исторический JSON/SELECT baseline не переписывался: работа приложения здесь другая, прямое сравнение чисел не доказывает ускорение.

## Результат по трём целям

| Цель | Реализовано | Цена и граница |
| --- | --- | --- |
| Простота | Отдельные config/routes, три handler, один repository с явным SQL, небольшой Html и обычные PHP-шаблоны | Оператор задаёт БД и private directory, явно выполняет migration/seed и выбирает SAPI либо RR; ORM, DSL и frontend build не нужны |
| Полезная функциональность | GET/HEAD списка, карточки и PDF; HTML 400/404/405/500, keyset navigation, hidden-state checks и понятные download metadata | Редактор, формы, upload, вход, CSRF и audit ещё не реализованы; PDF и no-store — политика примера |
| Производительность | Один SELECT на полезный запрос, SQL LIMIT 21, до 20 отображаемых строк, поток regular file; измерены реальные FPM/RR | Нет COUNT или per-row SQL; FPM открывает PDO заново, RR сохраняет его. Выбор runtime и числовых бюджетов остаётся открытым |

Путь разработчика — выбрать конфигурацию, добавить RouteEntry, handler и явный запрос/шаблон. Document и CatalogRepository нужны трём работающим handlers; общей иерархии domain/repository/mappers здесь нет. SQL generator не расширялся ради этого этапа: нужный LIMIT выражен непосредственно в подготовленном SQL. Partial index опубликованных ID принадлежит миграции приложения.

Основной bootstrap не регистрирует namespace примера; это делает только его собственный bootstrap. Не выбранный пример не выполняет свой SQL, не открывает files и не подключает middleware. Его исходники входят в runtime-архив как opt-in приложение; tests, load tests и quality tools туда не входят. Общая загрузка bundled runtime сохраняет прежние затраты — абсолютный нулевой overhead optional-кода не заявляется.

## Изменение общего RR-контракта

`RoadRunnerServiceProvider(chunkSize: 8192)` явно включает bundled streaming path. Default 0 сохранён для остальных конфигураций; отрицательное значение отвергается. Каталог использует seekable regular files. Эта версия upstream streaming требует seekable body и не поддерживает informational 1xx; обобщённый контракт произвольного потока не добавлялся.

RoadRunnerRuntime не читает исходный body для HEAD/1xx/204/304; для 1xx/204 удаляет Content-Length. После respond() закрывает response streams в finally, включая ошибку транспорта. Handler должен создавать новый stream на каждый запрос. Прямой App::handle() возвращает response без отправки/закрытия. SAPI emitter сохраняет прежнее поведение.

Настройка и новая ветка завершения response имеют стоимость; сравнения старой и новой реализации runtime не проводилось. В этой итерации измерен законченный новый сценарий, не объявлено ускорение самого router или RR adapter. Bundled код не менялся.

## Функциональные проверки

- Root verify: 329 unit tests / 1158 assertions; PHPStan level 10, стиль, bundled integrity, docs и SQL synchronization прошли.
- PostgreSQL verify:integration: 38 tests / 361 assertions. Из них 12 / 174 относятся к каталогу: 45 опубликованных + две скрытые записи, страницы 20/20/5, предел SQL-результата 21, один SELECT на страницу/карточку/download, escaping, неправильные cursor/ID, headers и файлы. Повторная миграция и ограничения published metadata проверены на настоящей БД.
- Снятие записи с публикации между запросами того же App немедленно прекращает новую выдачу. Отсутствующие и повреждённые по размеру файлы дают публичную 404. Это не отзыв уже переданного клиенту файла или остановка ранее разрешённого скачивания.
- Unit composition с недоступным storage engine подтверждает, что health и routing errors не требуют БД. Обычный document handler разрешает Storage через конструктор; отсутствие SELECT для плохого ID не означает отсутствия стоимости подключения.
- Общий http:check: по 24 случая настоящего FPM/RR. Дополнительно финальный catalog preflight: 30 случаев через общий Caddy, включая HTML, HEAD, ошибки, закрытые состояния и малый PDF. Большие файлы впервые читались после fresh restart в измеряемой файловой фазе.
- Настоящий RR отдельно передал файл 16 MiB + 17 bytes с правильным SHA-256, чередуя GET/HEAD/204/304/GET в одном worker. Unit-проверка реального PSR7Worker ограничивает payload chunks значением 8192 и проверяет полное содержимое.
- Runtime-архив собран и распакован без tools/tests/load-tests/codegen distributions. Из него выполнены native migration CLI, HTML-список на 20 строк, PDF с правильным hash и health при недоступном storage engine. Временный checkout удалён.

В браузере проверены title, main/h1, 20 записей и следующая страница, отсутствие next на последней странице, видимый Tab focus, skip link с переводом фокуса в main и переход в карточку с клавиатуры. Download link раскрывает название, PDF и размер. Это частичная ручная проверка: screen reader, полный WCAG AA и доступность содержимого PDF не оценивались.

## Стенд и воспроизведение HTML

macOS 26.6.1 / arm64, Apple M3 Max 14 cores, 36 GiB RAM; PHP CLI/FPM 8.5.9, RR 2025.1.15, Caddy 2.11.4, k6 2.2.0. PostgreSQL 16.15 работает в отдельном временном Docker container, VM 14 CPU / около 7.82 GiB RAM. OPcache включён для FPM и RR CLI, JIT отключён. Выбранные FPM ini settings сняты после серии; ini во время задачи не менялся. В JSON сохранены отдельные settings и pool overrides.

Один PHP worker на профиль; общий native Caddy: `:18281` → FastCGI/FPM `:19201`, `:18282` → HTTP/RR `:19202`. На одной машине работают также load client и БД в Docker; host CPU не изолирован. К штатным 24 published / draft / withdrawn записям seed добавлены два самостоятельных published PDF: 1 MiB и 64 MiB. Всего 28 записей, 26 опубликованных. Их размеры, hashes и metadata сохранены в JSON. Малый набор не подтверждает стоимость запросов на большом каталоге.

Для повторного HTML-замера подготовьте отдельную БД/файлы по README примера. Начальный seed позволяет воспроизвести list/detail сценарии; для строгого сравнения нужно также согласовать fixture, включая две дополнительные записи. Записанные server configs можно сохранить из JSON:

```bash
php -r '$d = json_decode(file_get_contents("docs/history/2026-10-17-public-catalog.json"), true, 512, JSON_THROW_ON_ERROR); if (!is_dir("var/catalog-benchmark")) { mkdir("var/catalog-benchmark", 0700, true); } foreach ($d["native_configs"] as $name => $contents) { file_put_contents("var/catalog-benchmark/" . $name, $contents); }'
export APP_CONFIG="$PWD/examples/document-catalog/config.php"
export ZERNO_ROOT="$PWD"
```

В отдельных терминалах с теми же DB_* и CATALOG_FILES_DIR:

```bash
php-fpm -p "$PWD" -y var/catalog-benchmark/php-fpm.conf -F
rr serve -w "$PWD" -c var/catalog-benchmark/rr.yaml
caddy run --config var/catalog-benchmark/Caddyfile
```

Для каждого profile/case — отдельный прогрев, затем измерение:

```bash
BASE_URL=http://127.0.0.1:18281 CASE=list VUS=4 DURATION=2s SUMMARY_PATH=var/list-warmup.json \
php tools/project.php perf:catalog --quiet

BASE_URL=http://127.0.0.1:18281 CASE=list VUS=4 DURATION=5s SUMMARY_PATH=var/fpm-list-1.json \
php tools/project.php perf:catalog --quiet
```

CASE=detail использует DOCUMENT_ID=1 и EXPECTED_TITLE="Документ №1" по умолчанию; для другой контролируемой записи задайте их явно. Для RR замените порт на 18282. В отчёте три повтора на каждую пару: JSON summaries прогрева исключены, порядок FPM→RR в повторах 1/3 и RR→FPM в повторе 2, list перед detail. Во время финальной серии проверки проекта и браузер не запускались.

## HTML: задержки и процессы

Здесь медиана трёх значений каждой метрики; медиана перцентилей отдельных запусков не равна перцентилю объединённой выборки. RPS — наблюдаемый closed-loop rate при 4 VU, не доказанный предел сервера.

| Профиль / сценарий | RPS | p50, ms | p95, ms | p99, ms | HTTP errors |
| --- | ---: | ---: | ---: | ---: | ---: |
| FPM / list | 81.85 | 48.547 | 56.556 | 61.727 | 0 |
| FPM / detail | 84.93 | 46.092 | 55.225 | 61.842 | 0 |
| RR / list | 1120.01 | 3.406 | 4.819 | 5.770 | 0 |
| RR / detail | 1224.66 | 3.161 | 4.346 | 5.303 | 0 |

Все восемь checks на каждый ответ прошли: status, content type, title/h1, ожидаемое содержимое, cache policy, nosniff и отсутствие cookies. SQL/request=1 объявлен по handlers и подтверждён отдельными integration tests, а не внешним tracer этого нагрузочного прогона; соединение, SET search_path и prepare в счёт не входят. FPM создаёт PDO/statement и логирует создание storage на каждом запросе; RR переиспользует их. Разницу нельзя приписывать только HTML-renderer или исполнению одного SELECT.

| Профиль / сценарий | CPU pool, s | CPU Caddy, s | Медиана sampled peak sum RSS pool, MiB |
| --- | ---: | ---: | ---: |
| FPM / list | 1.48 | 0.28 | 54.64 |
| FPM / detail | 1.52 | 0.27 | 54.66 |
| RR / list | 2.88 | 1.86 | 103.12 |
| RR / detail | 2.88 | 1.76 | 105.17 |

CPU/RSS снимались через ps каждые 250 ms для parent и descendants: FPM master+child либо RR server+PHP worker. CPU — разность process CPU time за запуск команды клиента, не нормализованная на запрос. Общий Caddy учитывается один раз для выбранного профиля; неактивный runtime, k6 и PostgreSQL/Docker в активную сумму не включены. RSS может повторно учитывать shared pages, не равен PHP heap или уникальной RAM, короткий peak может быть пропущен.

## Скачивание и память

Отдельный клиент читает не более 64 KiB за раз и вычисляет SHA-256 без полного буфера файла. Последовательность для каждого runtime: 608 bytes → 1 MiB → 64 MiB → 1 MiB → 608 bytes, HEAD после каждого размера, затем 100 малых GET в том же PHP worker. Размеры/хеши всех GET совпали, HEAD передали ноль байтов с Content-Length файла. Проверочный клиент намеренно ждал 2 ms после чтения блока большого файла, чтобы ресурсные samples успевали фиксироваться; длительность такого скачивания не используется как throughput benchmark.

| Профиль | RSS pool до / peak / после, KiB | RSS PHP worker до / peak / после, KiB |
| --- | --- | --- |
| FPM | 55968 / 55968 / 55968 | 27824 / 27824 / 27824 |
| RR | 107792 / 111392 / 111392 | 43792 / 43792 / 43792 |

Caddy sampled peak — 51824 KiB. Наблюдавшийся рост RR pool около 3.5 MiB относится к Go server; RSS PHP worker не изменился. Эти результаты согласуются с потоковой выдачей в проверенной конфигурации при росте файла на 63 MiB. Они не доказывают абсолютную неизменность памяти, отсутствие утечек или устойчивость длительной работы. PHP heap и файловые descriptors отдельно не измерялись; полная временная серия RSS не сохранялась, в JSON остались агрегаты и количество samples.

Постоянный k6-сценарий покрывает только HTML. Файловая часть — отдельный разовый probe по описанному протоколу и сохранённым fixture metadata, не новая команда проекта. Для повторения требуется контролируемая пара файлов и потоковый клиент с теми же размерами блоков/пейсингом; нельзя подменять его k6 с полным PDF в response RAM и сравнивать память клиента с памятью сервера.

## Следующий этап

Публичное чтение завершено в указанной области. Редактор требует отдельного решения об identity, проверок миграций при сбое и восстановлении, явных прав, CSRF и согласованности БД/файлов. До deployment ещё нужны длительный worker run и проверка целевой платформы. Этот отчёт не является security audit, полной оценкой доступности или выбором основного runtime.

После проверок собственные native servers, временная схема, контейнер PostgreSQL и созданные для стенда файлы удалены. Исходные summaries и логи остались в игнорируемом var, сведения для отчёта скопированы в JSON.
