# Проверка HTTP 205, BIGINT метаданных и длительного RR-сценария

Тип: `history`. Статус: `historical`. Проверено: 2026-10-28.

## Состояние и результат

Завершён следующий этап [ADR](../design/adr-2026-10-28-contract-correctness.md): запрет body для HTTP 205, новая BIGINT-колонка времени миграций и явный upgrade-metadata для существующей истории. [Текущий контракт миграций](../design/transactions-and-migrations.md#bigint-времени-и-явное-обновление-метаданных) владеет порядком обслуживания; HTTP-поведение описывает [lifecycle](../architecture/request-lifecycle.md). Эти исправления не являются оптимизацией с заявленным ускорением.

На настоящем RR пройдены 144 JSON + 144 HTML wire cases. PostgreSQL проверил сохранение INTEGER-истории, отсутствие неявного ALTER, повтор явной команды, PHP_INT_MAX и rollback неудачного преобразования. В распакованном runtime-архиве upgrade-metadata также сохранил старую строку и принял PHP_INT_MAX. verify:full: 316 root unit / 170 SQL tool / 62 PostgreSQL tests.

## Платформа и метод

Собственный код основан на Git 22dfa6d с незакоммиченными изменениями этапов ADR. [JSON](2026-10-28-runtime-soak.json) сохраняет source hashes, runtime manifest hash, SHA-256 RR, полный k6 summary и process samples. За измеренный интервал сохранённые собственные runtime/fixture sources не изменились.

Linux aarch64 в Docker Desktop: PHP 8.5.12, pdo_pgsql/sockets, libpq 17.11, PostgreSQL 16.15; RR 3.0.1, Go 1.27.3; k6 v2.2.0 (commit/devel, go1.26.5, darwin/arm64). Внешняя тестовая PHP-платформа предварительно подготовлена из cached PHP image с libpq-dev/pdo_pgsql; эта подготовка не входит в project checks/build и не меняет bundled dependencies. Docker VM имела 14 CPU и 8 GiB памяти. PHP workers: CLI OPcache on, memory_limit 64M, chunk size 8192, pool size 2, max_jobs 0. Проверочный граф включает оба существующих baseline/wire App и metadata logging; его затраты не приравниваются к минимальному ядру.

Финальный сценарий: 60 s прогрева и 900 s измерения при 10 iterations/s, SQL batches по 4 параллельных запроса, 10 000 подготовленных записей. Остальные операции — JSON, manual/generated Echo, route/query reset, 1 MiB и 16 MiB + 17 байт GET/HEAD с SHA-256, 204/205 и ожидаемые 400/404/405/500. Последовательный пилот в основном выбирал один worker, поэтому окончательная версия использует короткие SQL bursts. [Методика воспроизведения](../development/http-and-baseline.md#смешанный-длительный-прогон) содержит команды подготовки, k6 и process sampling.

## Наблюдения

За 15 минут k6 выполнил **16,564 HTTP requests**, **99,384 успешных checks**, не зарегистрировал failed checks, сетевых/неожиданных HTTP failures и dropped iterations. Ожидаемые HTTP errors проверяются по status/privacy и не приравниваются к transport failure. http_req_duration включает отправку запроса, ожидание и получение response body; клиентская проверка SHA-256 выполняется после ответа и входит в iteration_duration, а не в HTTP latency ([определения k6](https://grafana.com/docs/k6/latest/using-k6/metrics/reference/)).

HTTP latency: p50 **1.96 ms**, p95 **13.70 ms**, p99 **498.52 ms**, max **767.58 ms**. Это смешанный workload с крупными файлами, не максимальная пропускная способность и не сравнение реализаций.

| Процесс | RSS median первых 60 s, MiB | RSS median последних 60 s, MiB | Max sampled RSS, MiB | CPU seconds | FD min–max |
| --- | ---: | ---: | ---: | ---: | ---: |
| RR server | 41.27 | 23.88 | 53.89 | 41.33 | 15–31 |
| PHP worker 16 | 31.58 | 17.29 | 31.77 | 94.89 | 6–7 |
| PHP worker 17 | 31.57 | 17.27 | 31.76 | 94.22 | 6–7 |

PID/start_ticks server и обоих worker сохранялись во всех samples, оба worker расходовали CPU. PHP allocations/peak доступны в k6 telemetry отдельно от RSS. В JSON также записан lifetime kernel peak RSS; sampling раз в 5 s не ловит каждый кратковременный spike. CPU seconds получены из дельты utime+stime при CLK_TCK=100. Суммирование RSS может повторно учитывать shared pages. PostgreSQL и host k6 не входят в таблицу server/workers.

## Границы вывода и ориентиры

Корректность, неизменность worker identities и конечные диапазоны памяти/FD подтверждены только этим прогоном. Данные таблицы и latency — локальный baseline для сравнимого повторения, не универсальный SLO. Явные пределы сценария: 64M Zend memory_limit на worker, два worker, response files до 16 MiB + 17 байт, клиентский timeout 10 s и maxVUs 12. RSS отличается от Zend limit. В первой части измерения на том же host выполнялись verify:full и отдельный archive CLI smoke; фоновые задачи и Docker VM могли влиять на latency/residency.

Пятнадцать минут не доказывают отсутствие утечек на днях работы, поведение произвольных streams, client cancellation, потерю БД или deployment readiness. Online security audit здесь не повторялся; действующее scoped заключение для Linux amd64 не покрывает использованный Linux arm64 executable. Следующий эксплуатационный этап должен выбрать профиль реального приложения, длительность и бюджеты на целевой deployment-платформе. Созданные для проверки процессы, schema и файлы очищаются отдельно от сохранённых результатов.
