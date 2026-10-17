# Контракт и развитие `protoc-php-gen`

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-17.

## Текущая роль

[tools/protoc-php-gen](../../tools/protoc-php-gen/README.md) — локальный `protoc` plugin «Зерна». Он нужен приложениям, выбирающим protobuf API; минимальный HTTP-профиль с явными PHP-маршрутами работает без него.

Plugin читает binary `CodeGeneratorRequest`, строит typed descriptor model и возвращает `CodeGeneratorResponse`. `TypeResolver` разрешает protobuf-типы в PHP-классы. Сам plugin не генерирует PHP message classes или OpenAPI: это отдельные команды `protoc` и `protoc-gen-openapiv2`.

## Реализованные модули

| Модуль | Флаг | Результат |
| --- | --- | --- |
| `endpoints` | `generate_endpoints=true` | `*Endpoint` interface и `*HttpHandler` для RPC |
| `endpoint_validation` | `generate_endpoint_validation=true` | Проверка существования и объявления ручной реализации; файлов не создаёт |
| `operation_manifest` | `generate_operation_manifest=true` | PHP `*OperationRegistry` с определениями RPC и HTTP method/path |

Флаги по умолчанию выключены. Это проверяется в [PluginOptionsTest](../../tools/protoc-php-gen/tests/Unit/Plugin/PluginOptionsTest.php). `endpoint_profile_class` должен указывать загружаемый класс, реализующий `EndpointProfile`; встроенного project profile по умолчанию нет. Необязательный `bootstrap` загружает внешний profile.

В «Зерне» profile находится в [codegen/Protobuf/BaseApiTemplateEndpointProfile.php](../../codegen/Protobuf/BaseApiTemplateEndpointProfile.php). Сохранённый FQCN не требует переименования вместе с проектом.

## Текущий output contract

- Generated interface принимает typed protobuf request и PSR-7 request, возвращает typed protobuf response.
- Generated handler делегирует decode/response общему runtime adapter и вызывает ручную реализацию.
- Registry содержит RPC operation ID, request/response classes, handler, endpoint interface/implementation и HTTP bindings.
- Ручная реализация располагается по соглашению profile в `src/Platform/Http/Endpoint/...`.
- В protobuf-профиле `RoutingServiceProvider` явно подключает registry к Router и DI. Generic DI и минимальный HTTP-профиль не должны зависеть от discovery этих файлов.

Namespaces, implementation paths и runtime helper FQCN задаёт `EndpointProfile`. Бизнес-правила, persistence и преобразование domain-моделей остаются за его пределами.

## Действующие проверки и ограничения

[EndpointImplementationValidator](../../tools/protoc-php-gen/src/Generator/EndpointImplementationValidator.php) проверяет наличие файла, ожидаемое имя класса и явно объявленный interface через разбор PHP tokens. Это не полная проверка PHP-контракта: методы, совместимость сигнатур, наследование interfaces и произвольные формы PHP-объявлений требуют отдельной проверки.

HTTP binding parser извлекает method/path, в том числе `additional_bindings`. Generated runtime сейчас декодирует JSON body целиком. Полное сопоставление path/query/body по Google transcoding rules, streaming RPC и gRPC server не являются реализованными возможностями этого plugin.

Проверки компонента:

```bash
php tools/project.php tools:proto:check
```

Они включают style, PHPStan, unit tests и binary plugin protocol tests. Согласованность реального project surface дополнительно проверяется основным `verify`. Наличие этих проверок не является заявлением о готовности для произвольного production API.

## Граница поставки

Зависимости уже включены в [third_party/codegen/protobuf](../../third_party/codegen/protobuf); manifest фиксирует версии, refs, лицензии и hashes. Generator bootstrap не загружает runtime distribution. SQL и protobuf tools используют отдельные процессы; смешивать их bootstrap-файлы или runtime protobuf в одном процессе нельзя.

`protoc` остаётся внешним платформенным executable. Подробности команд и параметров — в [README инструмента](../../tools/protoc-php-gen/README.md).

## Предложения, ещё не контракт

Ниже — возможные направления, а не утверждённый следующий этап. Приоритет определяется [общим roadmap](../project/roadmap.md).

- Более строгая диагностика ручных реализаций и связности generated artifacts.
- Новые transport/client helpers только при подтверждённом потребителе.
- Дополнительные модули только с явным флагом, отдельным output contract и тестами.

Mapping, ORM и hydrator framework сейчас не входят в scope. Если отдельный mapping module когда-либо потребуется, он должен иметь собственный контракт и не включаться скрыто в endpoint path. Внутренние type/rendering libraries допустимы, если не становятся зависимостью generated runtime.

Практические правила изменений — в [protoc-php-gen-guidelines.md](./protoc-php-gen-guidelines.md).
