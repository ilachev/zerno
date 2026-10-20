# Переход на RoadRunner 3

Тип: `history`. Статус: `historical`. Проверено: 2026-10-20.

Состояние до перехода — `a0d0b12037dc804957144a469b81755ba1a06900`. Локальная платформа использовала RR2025.1.15. Целевой транспорт обновлён до RR3; общий App, функции композиции, явные handlers, SQL и SAPI остаются теми же. Текущая спецификация — [lifecycle](../architecture/request-lifecycle.md), поставка — [autonomous core](../design/autonomous-core.md). [Данные проверки](2026-10-20-roadrunner3.json) содержат upstream refs, provenance и результаты.

## Состав и происхождение

| Компонент | До | После |
| --- | --- | --- |
| Server executable | 2025.1.15 | 3.0.0, darwin/arm64, Go 1.27.2 |
| Worker | spiral/roadrunner-worker 3.6.2 | roadrunner/worker 3.8.0 |
| HTTP | spiral/roadrunner-http 4.1.0 | roadrunner/http 4.2.1 |
| Goridge | spiral/goridge 4.2.1 | roadrunner/goridge 4.6.0 |
| API DTO | roadrunner-php/roadrunner-api-dto 1.14.1 | roadrunner/api-dto 2.1.0 |
| Compatibility metadata | spiral/roadrunner 2025.1.11 | spiral/roadrunner 3.0.0 |

Четыре PHP source packages получены из официальных GitHub tag/commit archives через withproxy, без package manager. Archive SHA-256, commit refs, notices и hashes файлов включены в runtime manifest. Полные upstream trees сохранены; число bundled files выросло с 1614 до 2098 во всех трёх distributions. Это стоимость статической поставки, не число загружаемых на запрос файлов. Google protobuf 5.34.0 и polyfill 1.32.0 не обновлялись.

Tagged server composer.json ещё называет metapackage spiral/roadrunner, новые transport metadata также требуют roadrunner/roadrunner. Оригинальные декларации сохранены как provenance; package resolution не выполняется. PHP namespaces Spiral/RoadRunner/GPBMetadata остались upstream-именами, пути packages теперь roadrunner/*. Набор capabilities ядра не расширялся.

Бинарник 3.0.0 загружен отдельно в локальный ignored var/rr3/bin/rr. SHA-256 архива совпал с digest официального GitHub release asset. Это сверка с издательским HTTPS-источником, без независимой проверки подписи. Бинарник не включён в runtime archive и не скачивается при startup/build/check. Установленный Homebrew rr не заменялся; RR3 выбирается через PATH либо --rr у http:check.

## Адаптации и наблюдаемое поведение

Обе адаптации worker перебазированы на 3.8.0 и представлены patches с upstream/final hashes. Version::current возвращает pinned compatibility 3.0.0 без Composer InstalledVersions; это не определение версии executable. Неиспользуемые eager logger property/arguments/getter удалены без изменения frames. Native HttpWorker по-прежнему переводится в concrete HTTP-типы ядра. PSR/Nyholm/Composer runtime не возвращались; неиспользуемые upstream adapters остаются только источниками происхождения.

HTTP 4.2 сохраняет числовые имена полей: PHP преобразует имя 123 в integer array key. Собственные Headers и SAPI/RR boundaries теперь явно учитывают этот случай. Реальные запрос/ответ сохраняют поле, multivalue headers и отдельные Set-Cookie остаются проверенными.

RR configs сохраняют version: '3' — формат YAML. В используемых конфигурациях нет серверного http.middleware, поэтому изменение upstream-порядка не требует перестановки. PHP Pipeline порядок не менялся. Root logger остаётся ERROR, канал server установлен INFO/production, чтобы сохранять PHP stderr, включая ошибки приложения. Проверка подтверждает JSON envelope с time/INFO, metadata и приватную ошибку в логе. Формат собственного PHP Logger не менялся. Очередь вывода upstream асинхронна; надёжный аудит событий под перегрузкой этим не заявляется.

## Проверка и ограничения

Проверены root unit tests, PHPStan 10, style, docs, bundled integrity, SQL synchronization, SQL tool и PostgreSQL integration. Реальные FPM/RR3 проходят 204 HTTP-сценария с JSON/HTML ошибками, raw JSON/form/multipart uploads, числовыми/повторными полями, worker reuse, HEAD/204/304 и файлами до 16 MiB + 17 bytes при PHP memory_limit 32M. Дополнительный автономный тест передаёт бинарные кадры через настоящие Worker/StreamRelay и проверяет version без Composer/PSR logger. Пройден verify:full: root 231 tests / 923 assertions, SQL tool 167 / 625, PostgreSQL integration 32 / 272. Архив прошёл 43 HTTP-проверки с явными migrate/seed/rollback/reapply и 21 generated typed row; чистый runtime bootstrap создаёт настоящий Worker без Composer/PSR. tools/project.php run с локальным RR3 вернул health без БД. Счётчики и archive smoke записаны в JSON после полного gate.

Миграция меняет платформенный executable и bundled transport; пользовательский путь — предоставить RR3 в PATH и запустить прежнюю функцию композиции. SAPI не требует RR3 или sockets; код приложения и DI-граф не меняются. Transport source trees выросли, добавлены явные правила логирования и ответственность за обновление двух patches. CPU/RSS, p95/p99 и throughput на сопоставимой нагрузке не измерялись; ускорение и production readiness не заявляются. Ограниченный memory smoke не доказывает долгосрочную устойчивость и полный охват client abort.

Первичные источники: [релиз RR3](https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.0), [migration guide](https://github.com/roadrunner-server/docs/blob/release/v3/intro/v3-migration.md), [worker](https://github.com/roadrunner-php/worker/releases/tag/v3.8.0), [HTTP](https://github.com/roadrunner-php/http/releases/tag/v4.2.1). Проверка проекта выполнялась локально, результаты upstream не подменяют её.
