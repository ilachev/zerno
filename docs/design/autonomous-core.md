# Автономная поставка PHP-ядра

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-17.

Runtime, генерация, проверки и сборка используют включённые в репозиторий PHP-исходники и инструменты. Проектных Composer manifests, установки пакетов и Composer ClassLoader нет. Сторонний код сохранён фиксированными distributions с происхождением и hashes. Смысл автономности и границы продукта определены в [видении](../project/vision.md).

## Контуры поставки

| Контур | Назначение и источник загрузки |
|---|---|
| [Bootstrap приложения](../../bootstrap.php), [Autoloader](../../bootstrap/Autoloader.php) | Проверка PHP/PDO; PSR-4 prefixes, classmap и function files без Composer |
| [Runtime distribution](../../third_party/runtime/manifest.json) | Сторонние runtime packages и их локальная карта загрузки |
| [SQL distribution](../../third_party/codegen/sql/manifest.json) | Зависимости SQL-генератора; отдельный bootstrap и процесс |
| [Protobuf distribution](../../third_party/codegen/protobuf/manifest.json) | Зависимости protobuf-генератора; отдельный bootstrap и процесс |
| [Quality tools](../../third_party/tools/manifest.json) | Standalone PHPStan, PHPUnit и PHP-CS-Fixer PHAR с provenance |
| [Индекс distributions](../../third_party/manifest.json) | Актуальный состав и пути manifests |
| [PHP runner](../../tools/project.php) | Проверки, генерация, services и packaging; Task — optional aliases |

Оригинальные upstream `composer.json` внутри package snapshots оставлены как метаданные происхождения. Проект не исполняет их как инструкции установки. Наличие сторонней библиотеки в runtime distribution не включает её автоматически в минимальный request pipeline.

## Загрузка и изоляция

`bootstrap.php` подключает локальный autoloader и runtime distribution. `public/http.php`, `public/index.php` и CLI используют этот bootstrap. `tests/bootstrap.php` добавляет тестовые namespaces и dev bootstrap для типов PHPUnit. Обычный HTTP-запрос не подключает инструменты качества.

SQL и protobuf generators выполняются отдельными процессами со своими bootstrap-файлами и закреплёнными версиями библиотек. Это необходимо, поскольку runtime и генераторы могут использовать разные версии одинаковых namespaces. Не смешивать их autoload maps в одном приложении. Конкретные пути и процессы заданы в `tools/project.php`, `codegen/sql-gen-bootstrap.php` и `codegen/protoc-php-gen-bootstrap.php`.

Автономная загрузка поддерживает function/bootstrap files и ресурсы пакетов, а не только классы. При обновлении distribution сохранять необходимые grammar files, function files и notices.

## Целостность, происхождение и изменения

```bash
php tools/project.php third-party:check
```

Проверка читает manifests, сверяет SHA-256 файлов и отвергает незаявленные файлы и symlinks внутри distributions. Она работает локально, без сети. `.gitattributes` сохраняет точные байты bundled files при Git checkout.

Hash подтверждает соответствие локальному снимку, но сам по себе не аутентифицирует upstream. Для импортированных source trees manifests фиксируют происхождение из ранее установленного окружения и upstream refs без обещания повторной криптографической проверки. Для PHAR сохранены официальные URLs, опубликованные SHA-256 и сведения о проверке подписей в `provenance.json`; разные инструменты имеют разный объём доказательств.

Адаптация `Spiral\RoadRunner\Version::current()` возвращает закреплённую compatibility metapackage version вместо чтения registry установленных пакетов. Patch и исходный hash записаны в runtime manifest. Это не версия исполняемого `rr`.

Обновление стороннего кода — отдельное проверяемое изменение: зафиксировать источник, версию/ref, необходимые зависимости и ресурсы, notices, локальные patches, карту загрузки и новые hashes; затем проверить все затронутые контуры. Не менять hashes только ради устранения ошибки integrity. Политика распространения собственного кода остаётся открытым вопросом: root `LICENSE` пока отсутствует; лицензии upstream и условия включённой GeoIP-базы требуют отдельного учёта.

## Платформа

PHP и расширения, PostgreSQL, выбранный HTTP-сервер и compilers являются требованиями окружения, а не скачиваемыми PHP-зависимостями. Точный список и команды: [README](../../README.md), [инфраструктура и проверки](../development/infrastructure-and-testing.md).

Минимальное приложение работает через SAPI/FPM или RoadRunner. Необязательные protobuf/GeoIP/cache integrations имеют дополнительные требования; генерация protobuf требует `protoc`, OpenAPI — `protoc-gen-openapiv2`. Default `verify` не требует этих компиляторов, внешней БД или Docker. Команды проекта не устанавливают платформу автоматически.

## Проверка и runtime-архив

```bash
php tools/project.php docs:check
php tools/project.php verify
php tools/project.php verify:full
php tools/project.php build local
```

`verify:full` требует настроенной PostgreSQL test database. Runtime-архив в `dist/` содержит приложение, конфигурацию, committed generated artifacts, entrypoints, bootstrap, runtime distribution с manifests/notices, документацию и opt-in пример `examples/document-catalog`. Его namespace регистрирует только собственный bootstrap при выборе конфигурации, основной bootstrap не подключает приложение каталога. Generators, quality tools, load tests и тесты в архив не включаются; команды разработки из документации рассчитаны на исходный checkout. OpenAPI sidecar берётся из committed `docs/api.swagger.json` и относится только к optional API.

Сборщик проверяет third-party integrity перед упаковкой; он не регенерирует protobuf и не скачивает зависимости. Побайтовая стабильность aggregate metadata SDK не гарантируется текущим `protoc`; это не мешает отдельно проверять смысл дескрипторов и синхронизацию остальных артефактов.

## История и дальнейшие задачи

[Сохранённый аудит](../history/2026-10-17-autonomy-audit.md) содержит исходную инвентаризацию, этапы удаления Composer и границы выполненных offline/FPM/RR проверок. Его [JSON snapshot](../history/dependency-inventory-2026-10-17.json) описывает прежний установленный граф, не нынешнюю поставку.

Текущая автономная поставка уже реализована. Сравнительная производительность runtime, воспроизводимая методика обновлений и эксплуатационные процедуры развиваются по [roadmap](../project/roadmap.md); прежние результаты smoke tests не заменяют эти задачи.
