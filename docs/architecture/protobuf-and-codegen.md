# Protobuf и генерация кода

Тип: `architecture`. Статус: `current`. Сверено с кодом: 2026-10-17.

## Два HTTP-профиля

Protobuf — дополнительный путь описания API в ProtoPHPStack. Обычный сайт или HTTP-приложение может использовать явные PHP-маршруты без `.proto` и генерации endpoint-кода.

| Профиль | Конфигурация | Источник маршрутов |
| --- | --- | --- |
| Минимальное HTTP/PDO-ядро | [config/core.php](../../config/core.php) | Ручной [config/routes.php](../../config/routes.php): сейчас GET и HEAD `/health` |
| Существующий protobuf API с Session и ApiStats | [config/container.php](../../config/container.php) | Generated operation registries через `RoutingServiceProvider` |

`config/core-roadrunner.php` добавляет RoadRunner к минимальному профилю. Выбор сервера не требует перехода на protobuf API. `config/routes.php` существует и поддерживается вручную; protobuf-генератор его не изменяет.

В protobuf-профиле `.proto` задаёт сообщения и RPC-контракты. Бизнес-логика остаётся в ручном PHP-коде.

## Входы, выходы и владельцы

| Что меняется вручную | Какой результат получается |
| --- | --- |
| [protos/proto/app/v1](../../protos/proto/app/v1): сообщения и `service/rpc` | `protos/gen/App/...` — классы protobuf и metadata |
| `google.api.http` и OpenAPI options в тех же `.proto` | `docs/api.swagger.json`; HTTP method/path в operation registries |
| `service/rpc` + внешний endpoint profile | `gen/Generated/Endpoint/...`: `*Endpoint` и `*HttpHandler`; `gen/Generated/OperationManifest/...`: `*OperationRegistry` |
| [codegen/Protobuf/BaseApiTemplateEndpointProfile.php](../../codegen/Protobuf/BaseApiTemplateEndpointProfile.php) | Project conventions: namespaces, пути реализаций и runtime-контракты |
| `src/Platform/Http/Endpoint/...` | Ручные реализации generated endpoint interfaces |

`api.proto`, `session.proto` и `users.proto` содержат transport-модели; `health.proto` и `system.proto` задают действующие RPC. SDK-генерация также создаёт support-классы `Google/...` и `GPBMetadata/Google/...` в `protos/gen`.

Generated-файлы не редактируют вручную. `BaseApiTemplate...` — сохранённое имя PHP-класса, а не актуальное название проекта.

## Действующие команды

Из корня репозитория:

```bash
php tools/project.php proto:gen:sdk
php tools/project.php proto:gen:docs
php tools/project.php proto:gen:endpoints
php tools/project.php proto:gen:all
php tools/project.php tools:proto:check
```

`proto:gen:all` последовательно запускает SDK, OpenAPI и endpoints. SDK создаёт встроенный PHP-генератор `protoc`; endpoints и registries создаёт локальный plugin [tools/protoc-php-gen](../../tools/protoc-php-gen/README.md). Это разные генераторы.

Исходники зависимостей plugin уже находятся в `third_party/codegen/protobuf`. Установка пакетов и Composer не нужны. Для генерации требуется платформенный `protoc`, для OpenAPI — ещё `protoc-gen-openapiv2`. Project runner их не скачивает. SQL и protobuf generators запускаются отдельными PHP-процессами, поскольку используют независимо закреплённые версии зависимостей.

## Как protobuf API подключается к runtime

1. Внешний profile подключается через `codegen/protoc-php-gen-bootstrap.php` и `endpoint_profile_class`.
2. Plugin создаёт interfaces, HTTP handlers и operation registry classes.
3. `GeneratedOperationManifestProvider` загружает registries.
4. `RoutingServiceProvider` явно регистрирует маршруты и соответствия endpoint interface → ручная реализация в DI.

Минимальный `config/core.php` не регистрирует этот provider и не запускает discovery generated endpoints.

Registries хранят RPC-типы, handler, interface, implementation и HTTP method/path. Это не полная реализация Google HTTP transcoding: текущий `AbstractProtobufRpcHandler` читает JSON из тела целиком; автоматическое заполнение message из path/query и семантика `body`/`response_body` не реализованы.

## Что проверяется и где остаются границы

`endpoint_validation` проверяет файл, ожидаемый FQCN класса и объявление ожидаемого `implements`. Он не доказывает корректность сигнатур методов, DI-зависимостей или поведения реализации. Дополнительный контроль дают PHPStan, PHP и [GeneratedTransportSurfaceConsistencyTest](../../tests/Unit/Platform/Http/Endpoint/GeneratedTransportSurfaceConsistencyTest.php).

После изменения protobuf API нужно обновить ручную реализацию, выполнить `proto:gen:all`, проверить diff и запустить `verify` и `tools:proto:check`. Сценарии с БД дополнительно проходят `verify:integration`.

Побайтовая воспроизводимость upstream SDK metadata имеет известное ограничение: локальный `protoc 26.1` с `aggregate_metadata` при повторных запусках меняет порядок независимых `FileDescriptorProto` внутри Health/System metadata. Содержимое каждого дескриптора при проверке совпало; такой diff нужно разбирать отдельно от изменения API.

## Связанные документы

- [Контракт и ограничения plugin](../design/protoc-php-gen-product.md)
- [Правила изменений](../design/protoc-php-gen-guidelines.md)
- [SQL generation](../design/sql-gen-product.md) — самостоятельный путь, не требующий protobuf
- [Направление автономного ядра](../design/autonomous-core.md)
- [Общий roadmap](../project/roadmap.md) — приоритеты развития; component proposals не заменяют его
