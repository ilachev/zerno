# Proto PHP Endpoint Generator

Тип: `tool-guide`. Статус: `current`. Сверено с кодом: 2026-10-17.

`protoc-php-gen` — локальный `protoc` plugin «Зерна» для дополнительного protobuf API-профиля. Обычный HTTP-профиль с явными [PHP routes](../../config/routes.php) его не использует.

## Текущие модули

| Флаг | Модуль | Результат |
| --- | --- | --- |
| `generate_endpoints=true` | `endpoints` | Endpoint interfaces и HTTP handlers |
| `generate_endpoint_validation=true` | `endpoint_validation` | Проверка файла, FQCN и объявления `implements` ручной реализации |
| `generate_operation_manifest=true` | `operation_manifest` | PHP operation registry classes с RPC и HTTP method/path |

Все модули по умолчанию выключены. Полную совместимость сигнатур и поведение реализации проверяют PHP, PHPStan и project tests, а не token-based validator.

Plugin не генерирует message SDK, OpenAPI, ORM или domain mappers. Полное Google HTTP transcoding и streaming RPC не входят в текущий runtime contract.

## Запуск из корня проекта

```bash
php tools/project.php proto:gen:endpoints
php tools/project.php tools:proto:check
```

Полный protobuf pipeline:

```bash
php tools/project.php proto:gen:all
```

Он отдельно запускает PHP SDK generation, OpenAPI и этот plugin. Для генерации нужен локальный `protoc`, для OpenAPI — `protoc-gen-openapiv2`. Project commands их не скачивают. Quality gate инструмента использует bundled PHP tools и synthetic binary protocol tests.

Прямой вызов для текущего project profile:

```bash
protoc -I=protos/proto \
  --plugin=protoc-gen-php-endpoint=tools/protoc-php-gen/bin/protoc-php-gen.php \
  --php-endpoint_out='namespace=App\Generated\Endpoint,output_dir=gen,source_root=src,bootstrap=codegen/protoc-php-gen-bootstrap.php,endpoint_profile_class=ProjectCodegen\Protobuf\BaseApiTemplateEndpointProfile,generate_endpoints=true,generate_endpoint_validation=true,generate_operation_manifest=true:.' \
  protos/proto/app/v1/*.proto
```

## Параметры

| Параметр | Контракт |
| --- | --- |
| `namespace` | Generated base namespace; default `App\Gen` |
| `output_dir` | Путь generated output; default `gen` |
| `source_root` | Корень ручных реализаций; default `src` |
| `bootstrap` | Необязательный PHP-файл, загружающий внешний profile |
| `endpoint_profile_class` | Обязательный загружаемый класс `EndpointProfile` с конструктором без обязательных аргументов |
| `generate_*` | Явное включение соответствующего модуля |

Profile задаёт naming, implementation paths и runtime helper FQCN. Project profile находится в [codegen/Protobuf/BaseApiTemplateEndpointProfile.php](../../codegen/Protobuf/BaseApiTemplateEndpointProfile.php); его историческое имя класса сохранено.

Текущий project output:

- `gen/Generated/Endpoint/...`;
- `gen/Generated/OperationManifest/...`.

Ручные реализации — `src/Platform/Http/Endpoint/...`. Generated output не редактируют вручную. В protobuf-профиле `RoutingServiceProvider` явно связывает эти outputs с Router и DI.

## Автономная поставка и границы

[bootstrap.php](./bootstrap.php) загружает [bundled distribution](../../third_party/codegen/protobuf/autoload.php). Установка пакетов не нужна. [Manifest](../../third_party/codegen/protobuf/manifest.json) содержит версии, source refs, лицензии и file hashes.

Generator и runtime protobuf distributions закреплены независимо; SQL generator также использует собственную версию Nette. Запускать их отдельными PHP-процессами и не смешивать bootstrap-файлы. Generated endpoint runtime использует приложение и protobuf message runtime, но не Nette или plugin-классы.

Известная особенность upstream `protoc 26.1`: SDK `aggregate_metadata` может менять порядок независимых descriptors при одинаковом содержимом. Это относится к `proto:gen:sdk`, а не к собственным endpoint/registry outputs plugin.

Дальнейшие предложения и ограничения — в [product spec](../../docs/design/protoc-php-gen-product.md), правила изменений — в [guidelines](../../docs/design/protoc-php-gen-guidelines.md). Приоритет задаёт [общий roadmap](../../docs/project/roadmap.md).
