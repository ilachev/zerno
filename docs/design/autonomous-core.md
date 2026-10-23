# Автономная поставка

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-23.

Runtime, генерация, проверки и сборка не устанавливают пакеты и не скачивают dependencies. Только отдельные [security checks](../development/security.md) получают advisory data по сети; обычные verify/build автономны. PHP, расширения, PostgreSQL, RoadRunner 3.x executable и инструменты стенда — внешняя платформа. [Конкретный состав](../project/vision.md) не содержит optional-подсистем старого API.

## Distributions

| Контур | Источники | Когда используется |
| --- | --- | --- |
| Runtime | third_party/runtime | bootstrap приложения |
| SQL generator | third_party/codegen/sql | Отдельный процесс sql-gen/sql-check |
| API contract sources | third_party/codegen/proto | Только компиляция contracts/echo.proto; bundled annotations/http/descriptor.proto |
| Quality/security | third_party/tools | PHPStan, PHPUnit, PHP-CS-Fixer PHAR launchers и govulncheck binaries |

[Главный manifest](../../third_party/manifest.json) содержит hashes distribution manifests; каждый manifest перечисляет файлы и SHA256. [Checker](../../tools/check-third-party.php) проверяет также незаявленные файлы и symlinks без сети. Runtime bootstrap не загружает generator/quality libraries.

Транспорт RR3 зафиксирован: roadrunner/worker 3.8.0, roadrunner/http 4.2.1, roadrunner/goridge 4.6.0, roadrunner/api-dto 2.1.0. Namespaces Spiral/RoadRunner/GPBMetadata не переименовывались. Compatibility metapackage spiral/roadrunner обновлён до v3.0.0: tagged upstream metadata сохраняет старое имя, хотя новые PHP packages также ссылаются на roadrunner/roadrunner. Эти декларации хранят происхождение, package resolution отсутствует. Исполняемый RR 3.x и sockets — отдельная платформа; команды проверки получают установленный executable. CI отдельно подготавливает RR 3.0.1 по release URL с фиксированным SHA-256 перед проверками; это внешняя платформа, не runtime dependency downloader.

Сторонние package trees фиксированы с version/ref/license и исходными notices. Для retained protobuf/polyfill provenance описывает прежний установленный snapshot; transport обновлён из upstream tag/commit archives по HTTPS с локальным archive SHA256. Новая поставка не заявляет независимую проверку подписи издателя; hashes подтверждают локальную целостность, не повторную аутентификацию издателя. Исходные composer metadata внутри upstream packages сохранены как происхождение, не как вход installer.

## Потребители runtime libraries

HTTP-типы и Logger принадлежат ядру. Nyholm/psr7, psr/http-factory, psr/http-message и psr/log удалены вместе с package trees и autoload prefixes. Ядро использует собственные HTTP-типы. RR использует native HttpWorker: Goridge, RR worker/HTTP, polyfill, roadrunner/api-dto и Google protobuf остаются в runtime. API Echo использует обычные readonly DTO без Google Message. GeoIP, psr/simple-cache и RR KV также удалены; contract sources поставляются отдельно в codegen/proto; descriptor reader берётся из уже bundled Google runtime только процессом генерации.

Retained upstream trees сохраняют исходные metadata и неиспользуемые PSR7Worker/Logger классы. Они не загружаются, PSR namespaces не регистрируются и этих packages в поставке нет. Это provenance, не поддерживаемый compatibility API. Крупные quality PHARs изолированы от runtime; их внутренние bundled libraries не являются API приложения.

У RR worker две явные адаптации: Version.php читает pinned compatibility metapackage version без installed registry; Worker.php удаляет неиспользуемые logger property/constructor/factory parameters/getter, которые eagerly создавали PSR logger. Обработка protocol/frames этим patch не меняется. Patch files, upstream hashes и итоговые hashes находятся в [runtime manifest](../../third_party/runtime/manifest.json). При обновлении upstream обе адаптации проверяются заново. Incidental edits bundled files запрещены.

Общая autoload map имеет стоимость; отсутствие этих packages не означает нулевой bootstrap overhead. Переход RR3, дополнительные проверки и состав источников описаны в [отчёте RR3](../history/2026-10-20-roadrunner3.md). Короткое CLI-сравнение и проверки перехода HTTP описаны в [истории собственного HTTP](../history/2026-10-20-native-http.md). Это исторические измерения до выбора RoadRunner единственным HTTP runtime.

## SQL и сборка

SQL inputs — sql/schema.sql и sql/queries/catalog.sql, runtime namespace задаётся аргументом runner, engine — tools/sql-gen. Generated artifacts не редактируются вручную. Nette/Phplrt/Typhoon нужны codegen-процессу, не HTTP. contracts/echo.proto и сохранённый descriptor дают отдельный проверочный API-контур. proto:gen и proto:compile:check требуют platform protoc (проверен 26.1); proto:check/verify сверяют sources/descriptor/PHP без compiler. SDK/OpenAPI не поставляются.

```bash
php tools/project.php third-party:check
php tools/project.php verify:full
php tools/project.php build local
```

Build создаёт dist/zerno-VERSION.tar.gz: bootstrap, src/gen/config/public/bin, runtime distribution, docs/README, конфигурацию RR, каталог с migration/seed и [ручной POST JSON](../../examples/json-echo/README.md) с RR3 конфигурацией, а также [Echo из контракта](../../examples/contract-echo/README.md) и его готовый PHP. SQL/API generators, contracts/descriptors, codegen sources, quality PHARs/security binaries, тесты и нагрузочные scripts исключены. Release manifest ссылается только на runtime distribution. OpenAPI sidecar отсутствует.

CLI migration в распакованном архиве: php bin/migrate.php migrate [migration-directory]. Default — src/Migrations; каталог передаёт examples/document-catalog/migrations. CLI явно собирает Storage/repository/loader/service и подключает файлы директории без HTTP graph. БД не создаётся автоматически, HTTP startup не выполняет DDL.

CI запускает online security gate независимо от полного verify; release packaging/upload зависит от его успеха. CI/release запускают полный gate и build, не регенерируют артефакты при запросе и не устанавливают PHP packages. Реальные HTTP/load checks выполняются явно на установленной платформе. Удаление API-миграций из дерева не удаляет таблицы существующих пользовательских БД; никакой автоматической destructive migration не добавлено.
