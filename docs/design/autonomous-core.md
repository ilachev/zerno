# Автономная поставка

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-20.

Runtime, генерация, проверки и сборка не устанавливают пакеты и не скачивают dependencies. PHP, расширения, PostgreSQL, выбранный HTTP executable и инструменты стенда — внешняя платформа. [Конкретный состав](../project/vision.md) не содержит optional-подсистем старого API.

## Distributions

| Контур | Источники | Когда используется |
| --- | --- | --- |
| Runtime | third_party/runtime | bootstrap приложения |
| SQL generator | third_party/codegen/sql | Отдельный процесс sql-gen/sql-check |
| Quality | third_party/tools | PHPStan, PHPUnit, PHP-CS-Fixer PHAR launchers |

[Главный manifest](../../third_party/manifest.json) содержит hashes distribution manifests; каждый manifest перечисляет файлы и SHA256. [Checker](../../tools/check-third-party.php) проверяет также незаявленные файлы и symlinks без сети. Runtime bootstrap не загружает generator/quality libraries.

Сторонние package trees фиксированы с version/ref/license и исходными notices. Provenance описывает локальный импорт прежнего установленного snapshot; hashes подтверждают локальную целостность, не повторную аутентификацию издателя. Исходные composer metadata внутри upstream packages сохранены как происхождение, не как вход installer.

## Потребители runtime libraries

Nyholm PSR-7 и PSR HTTP contracts обслуживают оба транспорта; psr/log — logging/tests; Goridge, RR worker/HTTP и polyfill входят в текущую RR-поставку. Зафиксированный RR HTTP требует roadrunner-api-dto и Google protobuf. Эти транспортные исходники сохраняются полными, хотя приложение не предоставляет собственного protobuf API. GeoIP, psr/simple-cache и RR KV удалены; protobuf generator distribution удалён целиком.

Общая autoload map регистрируется при bootstrap и имеет стоимость. Удаление исходников не объявляется доказанным ускорением HTTP. Изменения состава записаны в [истории очистки](../history/2026-10-20-core-scope.md).

Bundled RR worker Version.php содержит единственную зафиксированную адаптацию: pinned compatibility metapackage version читается без installed registry. Patch, upstream hash и итоговые hashes остаются в runtime manifest. Это не версия executable rr. Других incidental edits bundled files не делать.

## SQL и сборка

SQL inputs — sql/schema.sql и sql/queries/catalog.sql, runtime namespace задаётся аргументом runner, engine — tools/sql-gen. Generated artifacts не редактируются вручную. Nette/Phplrt/Typhoon нужны codegen-процессу, не HTTP. Собственных .proto/SDK/OpenAPI и protoc compiler requirements больше нет.

```bash
php tools/project.php third-party:check
php tools/project.php verify:full
php tools/project.php build local
```

Build создаёт dist/zerno-VERSION.tar.gz: bootstrap, src/gen/config/public/bin, runtime distribution, docs/README, конфигурацию RR и каталог с migration/seed. SQL generator, quality PHARs, тесты и нагрузочные scripts исключены. Release manifest ссылается только на runtime distribution. OpenAPI sidecar отсутствует.

CLI migration в распакованном архиве: php bin/migrate.php migrate [migration-directory]. Default — src/Migrations; каталог передаёт examples/document-catalog/migrations. CLI явно собирает Storage/repository/loader/service и подключает файлы директории без HTTP graph. БД не создаётся автоматически, HTTP startup не выполняет DDL.

CI/release запускают полный gate и build, не регенерируют артефакты при запросе и не устанавливают PHP packages. Реальные HTTP/load checks выполняются явно на установленной платформе. Удаление API-миграций из дерева не удаляет таблицы существующих пользовательских БД; никакой автоматической destructive migration не добавлено.
