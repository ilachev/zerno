# RoadRunner как единственный HTTP runtime

Тип: `history`. Статус: `historical`. Проверено: 2026-10-23.

До этого изменения исходники на Git 5fe818f поддерживали два HTTP-пути: public/http.php с SAPI factory/emitter и public/index.php с native RoadRunner. Поэтому проверки включали FPM. Пользователь уточнил, что запуск предусматривается только через RoadRunner. Его решение заменяет прежние требования AGENTS/архитектуры и не основано на объявлении победителя по старым benchmarks.

Удалены SAPI HTTP entry point, request factory/response emitter, их unit tests, FPM/FastCGI/Caddy ветви HTTP checker, конфигурации ручного JSON-примера и baseline. UploadedFile больше не принимает sapiUpload: moveTo использует rename native RR temporary file. Проверены перемещение, закрытие открытого body и сохранение файла после неудачной попытки. Приложения, передававшие шестой параметр, должны удалить его.

App и RequestHandler остались независимы от worker loop; это поддерживает явную композицию и unit tests. CLI migrations/seed/codegen не являются альтернативным HTTP runtime и сохранены. Состав runtime libraries не менялся; поправлено только platform requirement главного manifest. Команды http:check принимают --rr и --measure-contract; удалённые server flags отклоняются, совместимых aliases нет.

Текущие инструкции запуска, lifecycle, vision/roadmap, examples, LLM onboarding и inventory согласованы с RR-only. Старые отчёты с SAPI/FPM описывают выполненные ранее измерения, а не текущую поддержку. Исходники предыдущего пути доступны через Git 5fe818f; historical source references сохранены в отчётах.

Критерии: verify без внешнего сервиса/компилятора, реальный RR3 HTTP matrix с JSON/HTML errors, uploads, files, input cap и следующим запросом worker; runtime archive без SAPI-кода/конфигураций, запуск core/manual/generated/catalog health через настоящий RR. Измерения текущей итерации не являются доказательством ускорения или длительной устойчивости. Выбор RR не означает полного соответствия HTTP: известный пробел 205 и непроверенные HTTP/2/3 остаются отдельной задачей.

## Результат проверки

- `php tools/project.php verify`: 257 tests / 1122 assertions, PHPStan 10, style, hashes, docs и SQL/API synchronization прошли; внешние сервисы не требовались.
- `php tools/project.php http:check --rr=/path/to/rr3 --measure-contract`: RR 3.0.0 / PHP 8.5.9, по 140 HTTP cases для JSON и HTML. Замер оставлен диагностикой, ускорение не заявляется.
- `php tools/project.php build rr-only`: manifest сохраняет только runtime distribution и требует RR3; архив не содержит SAPI entry point/classes и FPM configs.
- Распакованный archive: 74 assertions, настоящий RR3 запускает core, ручной Echo, generated Echo и health каталога. Проверены ошибки входа/лимиты, HEAD/405/Allow/request IDs, следующий запрос после ошибки; каталог возвращает HTML 500 при недоступной БД и продолжает отвечать /health. DB port=1 исключал обращение к рабочей БД, generators/contracts отсутствовали.
- Старый `http:check --runtime=fpm` отклонён как неизвестная опция; он не выбирает другой сервер и не преобразуется в RR-режим молча.

БД-поведение, SQL inputs и генераторы этой итерацией не менялись. PostgreSQL integration и долгий load test не запускались; проверка отказа БД не заменяет PostgreSQL tests. Исторические результаты полных gates сохранены отдельно.
