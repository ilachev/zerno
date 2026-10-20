# Публичный каталог документов

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-20.

Небольшой пример: список опубликованных документов → HTML-карточка → скачивание PDF. Все команды ниже выполняются из корня checkout. Установка пакетов не нужна; требуются PHP 8.5 с расширениями ядра и `pdo_pgsql`, PostgreSQL, а для второго способа запуска — executable RoadRunner 3.x с расширением sockets (проверен 3.0.0). Общие требования описаны в [инфраструктуре и тестировании](../../docs/development/infrastructure-and-testing.md).

Подготовьте отдельную БД и роль примера через обычные средства администрирования PostgreSQL. Например, администратор может выполнить в `psql`:

```sql
CREATE ROLE zerno_catalog LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password zerno_catalog
CREATE DATABASE zerno_catalog OWNER zerno_catalog;
```

Пароль вводится интерактивно. Перед командами PHP задайте его в окружении своего терминала; конфигурацию с секретами в репозиторий не добавляйте. Затем явно укажите параметры подключения:

```bash
export DB_HOST=127.0.0.1 DB_PORT=5432
export DB_NAME=zerno_catalog DB_USER=zerno_catalog DB_SCHEMA=public
: "${DB_PASSWORD:?Задайте пароль роли PostgreSQL в DB_PASSWORD}"
export DB_PASSWORD
export CATALOG_FILES_DIR="$PWD/var/document-catalog/files"

php tools/project.php migrate migrate examples/document-catalog/migrations
php examples/document-catalog/seed.php
```

`CATALOG_FILES_DIR` должен находиться вне `public`; без переменной используется `var/document-catalog/files`. Seed создаёт каталог, если его ещё нет. Процесс PHP должен иметь право читать файлы, а оператор seed — создавать их. Для промышленного размещения отдельно назначьте права и владельца, храните БД и файлы согласованно и запрещайте постороннюю запись в storage.

Миграция создаёт таблицу `catalog_documents` и частичный индекс опубликованных записей. Она запускается явно, вне HTTP startup. Текущий общий migration runner выполняет DDL последовательно; при ошибке сначала проверьте фактическое состояние схемы перед повтором. Seed — только CLI-процедура демоданных: в пустой БД добавляет 24 опубликованные записи, один `draft` и один `withdrawn` с небольшими PDF. Повторный запуск сохраняет существующие записи с тем же storage key. Это не публичный upload или редактор.

Для локальной разработки запустите обычный SAPI:

```bash
APP_CONFIG="$PWD/examples/document-catalog/config.php" \
    php -S 127.0.0.1:8080 -t public public/http.php
```

Откройте [список документов](http://127.0.0.1:8080/documents). Встроенный сервер PHP предназначен для разработки; эту же конфигурацию можно передать FPM через `APP_CONFIG`.

Для RoadRunner в другом терминале с теми же переменными БД и storage:

```bash
rr serve -w "$PWD" -c examples/document-catalog/rr.yaml
```

RR слушает `127.0.0.1:8081`, запускает один PHP worker с CLI OPcache и выбирает RR_HTTP_CHUNK_SIZE=8192 в server env и ту же config.php функцию, что SAPI/FPM. Список доступен по [адресу RR](http://127.0.0.1:8081/documents). `Ctrl+C` останавливает выбранный сервер.

| GET и HEAD | Результат |
| --- | --- |
| `/health` | Проверка ядра без подключения к БД |
| `/documents` | До 20 опубликованных записей, последние добавленные первыми |
| `/documents?before=24` | Следующая страница по идентификатору, без общего `COUNT` |
| `/documents/{id}` | Название, текстовое описание, дата UTC и сведения о PDF |
| `/documents/{id}/download` | Опубликованный PDF как вложение |

[CatalogRepository](src/CatalogRepository.php) использует прямой SQL с литералом published и параметром cursor; это сохраняет predicate частичного индекса даже при generic prepared plans. Список делает один ограниченный SQL-запрос с `LIMIT 21`: дополнительная запись определяет наличие следующей страницы. Cursor `before` — каноническая десятичная запись положительного ID в пределах `PHP_INT_MAX`, без знака, пробелов и начальных нулей; некорректный cursor даёт HTML `400`. Неверный ID, отсутствующий или непубличный документ дают одинаковую HTML `404`. Неподдерживаемый метод получает HTML `405` с `Allow`, внутренняя ошибка — HTML `500` без деталей реализации.

[`application.php`](application.php) явно создаёт Html/Errors, repository/files и handlers из Logger/Storage/private directory. Объект [`Errors`](src/Errors.php) передаётся в общий ErrorHandlerMiddleware и RoutingMiddleware; [`config.php`](config.php) выбирает только параметры этой сборки. Контейнера, bindings и class-name lookup нет. HTML создаётся сразу, без промежуточного JSON; готовые ответы handlers не переписываются. Core `ErrorHandlerMiddleware` пишет полное исключение в выбранный logger, возвращая только публичное сообщение. Контракт, границы и стоимость подключения — в [жизненном цикле HTTP](../../docs/architecture/request-lifecycle.md#представление-http-ошибок).

Каталог работает без JavaScript и сессий, не создаёт `Set-Cookie`. HTML и download используют `Cache-Control: no-store`; `/health` сохраняет JSON-контракт ядра. HEAD возвращает метаданные GET без тела. В download он проверяет файл и размер, затем закрывает поток без чтения содержимого.

Выдача документов и файлов проверяет published-состояние через БД. Файл выбирается по непрозрачному ключу из записи; произвольный путь из URL не принимается. Проверяются нахождение внутри storage, обычный тип файла и соответствие размера через `fstat`. Недоступный файл даёт ту же публичную `404`, путь в ответе не раскрывается. PDF выдаётся через общий [`AttachmentResponse`](../../src/Platform/Http/AttachmentResponse.php) с `application/pdf`, `attachment`, ASCII/UTF-8 именами и `nosniff`; FileStore и вся проверка доступа/файла остаются локальными. [Контракт и владение потоком](../../docs/architecture/request-lifecycle.md#файловый-attachment-и-владение-потоком) описывают также ошибки и закрытие transport. Range пока не поддерживается: запрос с `Range` получает полный ответ `200`, без обещания частичной выдачи.

Опубликованные данные и файлы предполагаются неизменяемыми; seed создаёт готовые файлы до записи ссылок в БД. При каждом скачивании содержимое не хешируется и не анализируется на безопасность. Контролируемая подготовка PDF, доступность его содержимого и защита storage остаются задачами приложения и оператора. Проверка HTML-страницы не устанавливает доступность самого PDF.

Исходники примера подключаются явно через [bootstrap.php](bootstrap.php) из выбранной конфигурации. Обычное ядро не регистрирует namespace примера и не выполняет его SQL. PostgreSQLStorage создаётся заранее без PDO; handlers/repository/FileStore также не обращаются к данным при сборке. Runtime-архив содержит приложение, его миграцию и CLI seed; development tools, тесты и нагрузочные сценарии в него не входят.

В распакованном runtime-архиве вместо development runner выполняйте `php bin/migrate.php migrate examples/document-catalog/migrations`. Команды seed и запуска серверов остаются теми же; проверки ниже выполняются только из исходного checkout.

Проверки из checkout:

```bash
php tools/project.php verify
php tools/project.php verify:integration
```

Для второго запуска настройте отдельные `TEST_DB_*`, как описано в [инфраструктуре](../../docs/development/infrastructure-and-testing.md): они независимы от application `DB_*`. Unit-тесты не требуют сервиса БД. Фактические HTTP-проверки и замеры должны учитывать весь путь через выбранный сервер; наличие PSR-7 stream само по себе не доказывает ограниченную память.

Входа, редакторских прав, CSRF, upload UI, изменения и публикации через HTTP пока нет. [Границы примера](../../docs/project/document-catalog-example.md) не задают backlog редактора: пример проверяет развитие общих механизмов ядра по [roadmap](../../docs/project/roadmap.md). Html/FileStore/Errors остаются локальными реализациями; общий контракт представления ошибок уже находится в core, общая механика attachment и прозрачное логирование также реализованы в core. Подключённая HTTP-диагностика пишет metadata, без payload; её [проверки и стоимость](../../docs/history/2026-10-18-file-responses.md) не меняют default pipeline каталога.
