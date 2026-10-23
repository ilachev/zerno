# Генерация приложения из protobuf-контракта

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-23.

Первый сквозной этап реализован: [echo.proto](../../contracts/echo.proto) задаёт DTO, unary RPC и стандартный google.api.http binding. [Генератор](../../tools/proto-gen/Generator.php) создаёт обычные PHP DTO, endpoint interface и HTTP adapter с явным RouteEntry. [Ручная реализация](../../examples/contract-echo/EchoEndpoint.php) подключается конструктором в [composition root](../../examples/contract-echo/config.php). Это проверочный API, а не прикладная подсистема.

```text
.proto → protoc → descriptor → DTO + endpoint interface + HTTP adapter/route
                                   ↑ ручная реализация и явный new
                                 общий App → RoadRunner 3.x
```

SQL, доступ, транзакции и бизнес-политика остаются в приложении. Генератор не создаёт repositories, domain models, контейнер, registry или service providers. Обычный RequestHandler остаётся самостоятельным способом добавить маршрут.

## Путь разработчика

1. Изменить contracts/echo.proto: messages и RPC с POST binding.
2. Выполнить `php tools/project.php proto:gen`. Нужен установленный protoc; проверена версия 26.1. Ничего не скачивается.
3. Реализовать generated EchoSendEndpoint, явно передать реализацию и предел тела в EchoSendHttp, подключить `$adapter->route()` к Router. Поля и HTTP path повторять вручную не требуется; изменение имени RPC потребует явной правки реализации и композиции.
4. Выполнить verify и реальные RR3 HTTP checks. Несовместимая сигнатура реализации отклоняется PHP/PHPStan.

```bash
php tools/project.php proto:gen
php tools/project.php proto:check
php tools/project.php proto:compile:check
php tools/project.php verify
php tools/project.php http:check --rr=/path/to/rr3
```

`proto:check` входит в verify и не требует compiler executable: он сверяет хеши .proto и bundled imports, хеш сохранённого [descriptor](../../contracts/echo.pb), заново генерирует PHP и сравнивает весь каталог output. Отсутствующий, лишний или изменённый файл означает ошибку. Эта проверка сама не доказывает происхождение descriptor из источника: полную сверку выполняет `proto:compile:check` через protoc. [echo.inputs.json](../../contracts/echo.inputs.json) — generated snapshot, не вручную поддерживаемый lockfile.

Компиляция использует argv array, предел 10 секунд, 64 КиБ diagnostics и 4 МиБ descriptor. Проверка модели и синтаксиса PHP выполняется до замены output. Генератор владеет только gen/Generated/Echo: новый набор сначала пишется в соседний временный каталог, затем заменяет прежний; файлы удалённых RPC исчезают. Descriptor/snapshot сохраняются после PHP; при прерывании между действиями check обнаружит рассогласование, повторный proto:gen его исправит. Одновременные запуски генерации не поддерживаются. Произвольные файлы в generated-каталог помещать нельзя.

Runner намеренно собирает один конкретный контракт. Generator принимает descriptor отдельно; plugin registry, поиск сервисов и профили не вводились. Другому consumer потребуется явный вход/output в runner и соответствующая namespace-директория, а не runtime discovery.

## Поддержанный контракт

| Объявление | Текущая граница |
| --- | --- |
| Файл | Один proto3 file, единственный import google/api/annotations.proto; только file option php_namespace под App\Generated |
| Message | Верхний уровень, singular string с implicit presence; имя поля начинается со строчной буквы, состоит из ASCII букв/цифр и совпадает с JSON name |
| RPC | Unary, вход/выход из этого файла; interface handle(RequestDto): ResponseDto |
| Binding | Только google.api.http: literal POST path из букв/цифр/`/_-`, body: "*" |
| Результат | final readonly DTO с public string properties, fromJson/toJson; interface и RequestHandler с route() |

Числа, bytes, enum, repeated/map, optional/oneof, nested types, произвольные imports/options, GET, path/query transcoding, response_body, additional bindings и streaming не поддержаны. Они отклоняются при генерации; поддержка не имитируется потерей полей. Полный ProtoJSON, binary protobuf и gRPC не заявлены. PHP reserved words и коллизии generated type names также отклоняются.

## JSON и HTTP

Для string subset взяты [правила ProtoJSON](https://protobuf.dev/programming-guides/json/) об implicit defaults, null и неизвестных полях. DTO не реализуют Google Message и не сохраняют protobuf unknown binary fields.

| Вход | Ответ Echo |
| --- | --- |
| `{"message":"Привет"}` | 200, тот же JSON |
| `{}`, `{"message":null}`, `{"message":""}` | 200, `{}`: default — пустая строка, default не выводится |
| Неизвестное поле, включая null; неверный тип поля | 400 Invalid JSON request |
| Пустое тело, повреждённый JSON/UTF-8, array/scalar/null root | 400 Invalid JSON request |
| Повтор одного JSON key | Последнее значение, как у builtin json_decode; aliases запрещены |
| Более 65 536 байтов в примере | 413 Request body too large |
| Не application/json, отсутствующий/неоднозначный Content-Type или Content-Encoding кроме identity | 415 Unsupported media type |
| Другой HTTP method | Общий routing 405/Allow: POST; adapter также проверяет method при прямом вызове |
| I/O, exception реализации или ошибка output encoding | Общий ErrorResponse 500; подробности только в logger |

Content-Type сравнивается без регистра до `;`, параметры не меняют UTF-8 decoding. Глубина JSON ограничена 32. Ошибки decode преобразуются в 400 только вокруг fromJson; InvalidArgumentException реализации не выдаётся за ошибку клиента. Adapter закрывает входной Body в finally, transport закрывает response body. Adapter не хранит request/DTO между вызовами.

Body::readLimited проверяет реально прочитанные байты от текущего cursor без доверия Content-Length. Он ограничивает последующий decode, а не уже выполненную буферизацию входа в RR. Серверный ingress cap — отдельная настройка; профили и ограничения описаны в [ручном JSON-примере](../../examples/json-echo/README.md).

## Runtime и поставка

Запрос загружает только готовый PHP. Контракты, descriptors, tools/proto-gen и third_party/codegen/proto отсутствуют в runtime archive; там есть generated PHP и [пример](../../examples/contract-echo/README.md). Новых runtime libraries нет. Google protobuf уже поставлялся для RR; descriptor reader используется процессом генерации, API DTO его не загружают. Bundled annotations/http/descriptor.proto и upstream licenses/hashes записаны в [manifest](../../third_party/codegen/proto/manifest.json).

Объекты adapter/endpoint создаются при сборке приложения. Interface/type declarations могут загружаться при подключении маршрута; DTO/codec загружаются при API-вызове. /health и обычный маршрут не запускают генератор, не читают contract files и не открывают БД. Ядро и router не знают protobuf descriptors.

## Выбор DTO и стоимость

Обычные readonly DTO выбраны для проверенного string subset: прямая PHP-сигнатура, builtin JSON и отсутствие descriptor pool при API-запросе. Стандартные protobuf messages дают существенно более широкий контракт и binary codec; новые типы потребуют отдельно оценить их преимущество, а не расширять собственный codec без проверки.

Исторические [данные первого этапа](../history/2026-10-23-contract-generation.json), полученные до выбора RR единственным runtime, содержат пять отдельных процессов на вариант, по 20 000 операций, PHP 8.5.9, CLI OPcache off, одинаковый непустой JSON 254 байта. Варианты: прямые DTO, стандартные messages от protoc 26.1 с bundled Google runtime, ручной и generated HTTP handlers. Для protobuf сравниваются только те же messages, без HTTP annotations/services. Для handler-сравнения вход находится в общей поддержанной области; ручной пример требует message и выводит пустую строку явно, поэтому поведение за её пределами различается.

Измеряются холодная первая операция, число загруженных классов, PHP memory и steady decode/encode/handler. Это не native RSS и не доказательство отсутствия утечек. `http:check --measure-contract --rr=...` отдельно сравнивает по 100 последовательных запросов после прогрева через RR, чередуя порядок. В него входят сокет, routing и metadata logging. Короткая серия не выбирает основной runtime и не доказывает выигрыш в throughput. Генерация сокращает дублирование контракта; сама по себе она не ускоряет ручной handler.

## Проверки и дальнейшая граница

Root tests проверяют воспроизводимость, отказ на неподдержанных объявлениях, удаление устаревших файлов, типовую несовместимость, timeout compiler, JSON и предел тела. HTTP matrix проверяет JSON/HTML ErrorResponse, 400/405/413/415/500, request ID и следующий запрос того же RR worker. Runtime archive запускается отдельно без генератора/contract files. Команды и результаты сохранены в данных этапа.

Будущие типы, presence и transcoding добавляются законченными сценариями с consumer и совместимостью wire contract. SDK, общая validation/mapping framework и генерация SQL не следуют из Echo-этапа. Старый генератор на Git f7b28ecad141a64bb7d0cfcb622256ccbde952c1 изучен как исторический источник; registry, profiles, старые handlers и API-модель не восстановлены.
