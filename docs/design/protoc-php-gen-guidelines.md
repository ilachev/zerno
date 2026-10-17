# Правила работы с `protoc-php-gen`

Тип: `instructions`. Статус: `current`. Сверено с кодом: 2026-10-17.

## Когда применять

Эти правила относятся к protobuf API «Зерна». Обычные HTTP endpoints минимального профиля задаются в [config/routes.php](../../config/routes.php) и не требуют protobuf, generated registries или этого plugin.

Перед изменением определить выбранный профиль: [config/core.php](../../config/core.php) или [config/container.php](../../config/container.php). Текущий контракт plugin описан в [protoc-php-gen-product.md](./protoc-php-gen-product.md); приоритет новых возможностей задаёт [общий roadmap](../project/roadmap.md).

## Источники и владельцы

| Задача | Менять вручную |
| --- | --- |
| Message/RPC/HTTP binding protobuf API | `protos/proto/app/v1/*.proto` |
| Логика RPC | `src/Platform/Http/Endpoint/...` и соответствующие application/domain services |
| Project naming и runtime contracts | `codegen/Protobuf/BaseApiTemplateEndpointProfile.php` |
| Общий механизм генерации | `tools/protoc-php-gen/src` |
| Явные маршруты минимального HTTP-профиля | `config/routes.php` |

Не исправлять вручную `protos/gen`, `gen/Generated/Endpoint`, `gen/Generated/OperationManifest` или `docs/api.swagger.json`. Причину изменения искать в исходнике и генерировать заново. Сохранённые имена `BaseApiTemplate...` — имена существующих классов; не переименовывать их попутно.

## Изменение protobuf API

1. Изменить `.proto`.
2. Добавить или обновить ручную endpoint implementation по соглашению profile. Валидатор требует существующий файл и объявление generated interface.
3. Из корня выполнить `php tools/project.php proto:gen:all`.
4. Проверить diff SDK, handlers, registries и OpenAPI; убедиться, что удалённые RPC не оставили используемый ручной код.
5. Выполнить `php tools/project.php verify` и `php tools/project.php tools:proto:check`. Для затронутых DB-сценариев — также `php tools/project.php verify:integration`.

Установщик зависимостей не нужен: исходники включены в `third_party`. Для генерации нужны локальные `protoc` и `protoc-gen-openapiv2`; проверки компонента не должны их скачивать. Не подключать SQL/protobuf generator bootstrap и runtime distribution в один PHP-процесс.

## Граница механизма

Генератор отвечает за descriptors, interfaces, HTTP adapters, registries и ограниченную проверку объявлений реализаций. Project conventions должны поступать через внешний `EndpointProfile` и bootstrap.

Бизнес-логика, persistence policy и domain mapping остаются в ручном коде. Новую transport-семантику нельзя считать поддержанной только потому, что parser прочитал соответствующее поле: нужны runtime implementation и тесты.

Текущая проверка `endpoint_validation` не анализирует сигнатуры методов полностью. PHPStan, загрузка классов PHP и project consistency tests остаются обязательными линиями проверки.

## Правила расширения

Применять [три цели проекта](../project/principles.md): максимальная простота, максимальная функциональность и максимальная производительность. Порядок работ определяет [общий roadmap](../project/roadmap.md); наличие идеи в component spec не делает её обязательным следующим этапом.

Новый module допускается при реальной задаче, отдельном явном флаге, определённом output contract и тестах. До добавления сравнить его с ручным handler и существующими contracts: новая возможность должна уменьшать общую сложность сценария, а runtime-стоимость проверяется на сопоставимой нагрузке, если она меняется. Не включать новые модули по умолчанию, не переносить discovery в минимальный HTTP-профиль и не смешивать их с существующим endpoint path.

Усиление endpoint validator начинается с воспроизводимого пробела или задачи дать более раннюю и понятную диагностику. Сравнить суммарную пользу и стоимость с PHP, PHPStan и project tests; улучшение сообщения может быть полезно даже для уже обнаруживаемой ошибки. Не дублировать анализ только ради полноты собственного parser-а. Исправление существующего контракта имеет приоритет перед появлением ещё одного helper/module.

Внутренние type/rendering helpers допустимы, если они не протекают в generated runtime. `.proto` остаётся источником transport-контракта выбранного protobuf API; для обычного HTTP-профиля источником остаются его PHP-маршруты и handlers.

## Проверка воспроизводимости

Записывать используемые версии платформенных generators и сравнивать артефакты с исходниками. У локального `protoc 26.1` замечена перестановка независимых descriptors в `aggregate_metadata`; это ограничение upstream SDK-генерации, а не повод менять API или принимать любой generated diff без разбора.

При изменении самого plugin проверять binary protocol, enabled-module combinations, type resolution и project consistency. Будущие идеи хранить как предложения в component spec, а общий порядок работ — в [roadmap](../project/roadmap.md).
