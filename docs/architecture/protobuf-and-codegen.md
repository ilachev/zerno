# Protobuf и генерация кода

## Почему это важно

В этом проекте `protobuf` — не декоративный артефакт, а основной источник истины для публичного API. Именно из `.proto` строятся:

- PHP protobuf classes;
- OpenAPI/Swagger;
- runtime route configuration;
- generated server-side endpoint handlers and endpoint contracts;

Любая реструктуризация должна сохранять этот поток или осознанно заменить его чем-то другим.

## Где лежат proto-файлы

### Core API

`protos/proto/app/v1`

Содержит:

- `api.proto` — общие transport structures;
- `health.proto` — neutral health-check endpoint for the core runtime;
- `system.proto` — neutral POST runtime description endpoint for the core runtime;
- `session.proto` — session-related transport models;
- `users.proto` — user transport model.

## Что генерируется

### 1. PHP protobuf classes

Команда:

```bash
php tools/project.php proto:gen:sdk
```

Результат:

- `protos/gen/App/...`

Эти файлы нельзя редактировать вручную.

### 2. Core OpenAPI

Команда:

```bash
php tools/project.php proto:gen:docs
```

Результат:

- `docs/api.swagger.json`

### 3. Runtime routing metadata

Результат:

- `gen/Generated/OperationManifest/...`

Маршруты больше не строятся отдельным descriptor-parser в runtime и больше не компилируются в отдельный `config/routes.php`. Runtime читает generated operation registry classes напрямую.

### 4. Server-side endpoint generation

Команда:

```bash
php tools/project.php proto:gen:endpoints
```

Результат:

- `gen/Generated/Endpoint`
- `gen/Generated/OperationManifest`

Генератор создаёт:

- endpoint interfaces для каждого `service/rpc`;
- generic HTTP handlers поверх `AbstractProtobufRpcHandler`;
- operation registry classes с полной metadata по каждому `service/rpc`, включая `google.api.http` bindings.

Это handwritten business logic не заменяет. Разработчик по-прежнему пишет endpoint implementation, а runtime резолвит её через generated operation registries как каноническую metadata surface.

## Какие generated артефакты считаются каноническими

В default runtime path и в репозитории считаются нормальными только такие generated outputs:

- `protos/gen/App/...` — protobuf message classes и metadata для core API;
- `protos/gen/Google/...` и `protos/gen/GPBMetadata/Google/...` — runtime support для `google.api.http`;
- `gen/Generated/Endpoint/...` — generated server-side endpoint contracts и HTTP handlers.
- `gen/Generated/OperationManifest/...` — generated operation registry classes with RPC metadata.

## Текущий flow генерации

Полный цикл:

```bash
php tools/project.php proto:gen:all
```

Состав:

1. `proto:gen:sdk`
2. `proto:gen:docs`
3. `proto:gen:endpoints`

## Как устроен runtime routing

Теперь generation path линейный:

1. `php tools/project.php proto:gen:endpoints` через `protoc-php-gen` генерирует endpoint contracts и operation registry classes в `gen/Generated/...`
2. runtime использует operation registry classes как канонический endpoint metadata source и по ним строит routes и endpoint resolution

Это важно: `service/rpc + google.api.http` теперь проходят через тот же основной toolchain, что и generated endpoints, а не через отдельную runtime-ветку parsing logic.

Project-specific endpoint profile больше не зашит внутрь `protoc-php-gen`. Основной проект передаёт его извне через `bootstrap=codegen/protoc-php-gen-bootstrap.php` и `endpoint_profile_class=ProjectCodegen\Protobuf\BaseApiTemplateEndpointProfile`.

Сейчас core surface уже содержит `HealthService.Check` и `SystemService.Describe`, поэтому transport pipeline покрывается и `GET`, и `POST` сценарием с body, а `docs/api.swagger.json` остаётся непустым.

## `tools/protoc-php-gen`

Это локальный генератор проекта. Он не является временным скриптом; это часть архитектуры toolchain.

Его текущая production-grade роль:

- генерировать server-side endpoint contracts из protobuf `service/rpc`;
- валидировать наличие и объявление handwritten endpoint implementations;
- генерировать operation registry classes как каноническую metadata surface для RPC;
- поддерживать protobuf-first HTTP surface без ручного endpoint boilerplate в runtime.

При этом инструмент надо понимать шире, чем один текущий generator module: `protoc-php-gen` рассматривается как отдельная modular codegen platform, а основной шаблон сейчас использует три стабильных модуля: `endpoints`, `endpoint_validation` и `operation_manifest`. Отдельно это зафиксировано в `docs/design/protoc-php-gen-product.md`.

Важно: project-specific namespace conventions и runtime bindings теперь живут не в тулзе, а во внешнем profile-классе проекта в [BaseApiTemplateEndpointProfile.php](/Users/ilya/dev/PhpstormProjects/base-api-template/codegen/Protobuf/BaseApiTemplateEndpointProfile.php).

При реструктуризации нельзя просто "спрятать" этот каталог. Нужно решить:

- остаётся ли генератор внутренним инструментом репозитория;
- выносится ли он в отдельный пакет;
- как держать его endpoint-oriented и не превращать обратно в общий mapper framework.

## Практические правила для изменений

### Если меняется публичный API

1. Править `.proto` в `protos/proto/app/v1`.
2. Перегенерировать артефакты.
3. Добавить endpoint implementation в `App\Platform\Http\Endpoint\...` с тем же относительным путём, который ожидает generated endpoint contract.
4. Проверить, что core артефакты согласованы с кодом.

## Текущие проблемы codegen-потока

- endpoint implementation теперь валидируется на этапе generation, но generator пока не проверяет полноту реализации интерфейса глубже, чем наличие файла и корректное объявление класса;
- verify по-прежнему остаётся второй линией контроля для generated `*Endpoint` и дополнительно проверяет согласованность operation registries, generated handlers и endpoint implementations как единого endpoint surface.

## Что важно сохранить при реструктуризации

- Один явный источник истины для API.
- Явную границу между generated code и handwritten code.
- Предсказуемый pipeline генерации.
- Возможность LLM определить: "что нужно регенерировать после этого изменения".
