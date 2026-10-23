# Зерно

Тип: `navigation`. Статус: `current`. Сверено с кодом: 2026-10-23.

**Зерно** (`zerno`) — небольшое автономное PHP HTTP/PDO-ядро для сайтов и веб-приложений. Муниципальные и государственные проекты — приоритетная аудитория; назначение остаётся универсальным. Цели: [простота, полезная функциональность и производительность](docs/project/principles.md).

## Что поставляется

- Собственные Request/Response/Body/Uri/UploadedFile, явные маршруты и middleware без PSR/Nyholm.
- Сборка зависимостей обычными PHP-конструкторами и функциями, без контейнера/autowiring.
- JSON/прикладные HTML-ошибки через ErrorResponse, AttachmentResponse, ограниченное чтение входного Body.
- Request ID, время обработки и metadata logging без чтения payload.
- PostgreSQL PDO, явный параметризованный SQL, query/queryOne, транзакции и CLI-миграции.
- SQL generation и первый `.proto → DTO/endpoint/HTTP adapter/route` этап.
- **RoadRunner 3.x — единственный HTTP runtime.** App/RequestHandler отделены от worker loop для явной композиции и тестов.

Сессии, GeoIP/fingerprints, аналитика, cache-платформа, старый API/SDK, hydration/mapping и outgoing HTTP client удалены. Состав и границы описаны в [видении](docs/project/vision.md). [Echo из контракта](examples/contract-echo/README.md) использует обычные readonly DTO и builtin JSON; [поддержанный subset](docs/design/protobuf-contract-generation.md) ограничен string/unary POST.

## Запуск

Для HTTP нужны PHP 8.5.12+, PDO, sockets и установленный RoadRunner 3.0.1+ в ветке 3.x. Для БД дополнительно нужны pdo_pgsql и PostgreSQL. PHP libraries и quality/codegen tools поставляются статически, установка пакетов не требуется.

```bash
rr --version
php tools/project.php run
```

[GET /health](http://127.0.0.1:8080/health) возвращает `{"status":"ok"}`; HEAD зарегистрирован отдельно. Запрос не открывает БД. При нескольких установках выберите RR3 через PATH: команды проекта executable не скачивают. `version: '3'` в YAML обозначает формат конфигурации.

Единственная HTTP-точка входа — [public/index.php](public/index.php). [.rr.yaml](.rr.yaml) выбирает [config/core.php](config/core.php): `Closure(Logger): App`. APP_CONFIG задаёт другую явную функцию сборки. Logger и transport создаются в точке входа, routes получают готовые RequestHandler objects. Примеры — [ручной POST JSON](examples/json-echo/README.md), [Echo из .proto](examples/contract-echo/README.md), [каталог документов](examples/document-catalog/README.md).

## База данных и генерация

[config/storage.php](config/storage.php) читает DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD и DB_SCHEMA. Поддерживается PostgreSQL. PDO открывается при первой SQL-операции/транзакции. HTTP startup не запускает миграции.

```bash
php tools/project.php migrate migrate
php tools/project.php sql:gen
```

Default migrations — пустой src/Migrations; второй аргумент задаёт каталог, например examples/document-catalog/migrations. [SQL inputs](sql/queries/catalog.sql) и [schema](sql/schema.sql) проверяют генератор отдельно от приложения, которое сохраняет прямой SQL. Подробности — в [SQL-контракте](docs/design/sql-gen-product.md).

## Проверки и поставка

```bash
php tools/project.php help
php tools/project.php verify
php tools/project.php verify:full
php tools/project.php http:check --rr=/path/to/rr3
php tools/project.php security:audit
php tools/project.php security:platform --rr=/path/to/rr3
php tools/project.php build local
```

verify не требует внешних сервисов или protoc. verify:full добавляет SQL-tool checks и PostgreSQL через TEST_DB_*. Отдельные [security checks](docs/development/security.md) читают advisory data без скачивания пакетов; CI выполняет их ежедневно и блокирует релиз при ошибке. Текущий RR finding требует разрешения перед релизом. http:check запускает только настоящий RR3 и проверяет HTTP, ресурсы и состояние следующего запроса. [Инфраструктура](docs/development/infrastructure-and-testing.md) описывает требования и изоляцию. Runtime archive содержит готовый PHP и SQL/API-artifacts без генераторов, contracts и quality tools.

Bundled RR использует protobuf/DTO libraries для своего транспорта; API Echo их не загружает как codec. Notices, происхождение и hashes описаны в [автономной поставке](docs/design/autonomous-core.md).

Для агентов: [AGENTS.md](AGENTS.md), [правила кода](docs/development/coding-guidelines.md), [onboarding](docs/llm-onboarding.md), [индекс](docs/README.md). [Roadmap](docs/project/roadmap.md) задаёт ограниченные задачи; лицензия и политика публичных релизов собственного кода остаются открытыми.
