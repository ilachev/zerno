# `sql-gen` как продукт

Этот документ фиксирует целевую модель `sqlc-like` слоя для проекта.

## Зачем это нужно

Проект уже использует code generation как основную идею для transport слоя:

- `.proto` является источником истины для endpoint contracts;
- генератор производит PHP-код вокруг protobuf descriptors;
- runtime остаётся тонким.

Для persistence слой должен развиваться по похожему принципу:

- SQL остаётся источником истины;
- генератор производит typed PHP-код вокруг SQL queries;
- runtime остаётся тонким;
- проект не скатывается в ORM-heavy модель.

## Главный принцип

`sql-gen` не должен строить архитектуру поверх SQL.

Он должен делать только одно: брать явный SQL-запрос и генерировать вокруг него удобный typed PHP-контур.

То есть правильная модель здесь такая:

- не query builder как основной путь;
- не ORM как основной путь;
- не попытка описывать бизнес-сценарии абстракциями;
- а прямой SQL `1 в 1`, как в `sqlc`.

Если проекту нужен один запрос, значит в кодовой базе должен существовать один явный SQL-запрос.
Если проекту нужен миллион запросов, значит в кодовой базе будет миллион запросов. Это нормально и честно для сложного backend-а.

## Что считается хорошим результатом

Хороший `sql-gen` путь:

- сохраняет SQL читаемым и явным;
- не скрывает SQL за fluent API;
- проверяет запросы against PostgreSQL;
- генерирует typed params и, когда это уже реализовано, typed rows;
- оставляет runtime исполнение предельно простым.

## Что не нужно делать

- не строить ORM;
- не строить Active Record;
- не строить generic multi-database abstraction как главную цель;
- не пытаться описывать persistence policy через сложные PHP builders;
- не строить “SQL architecture” поверх реального SQL.

## Целевой input

Канонический input должен жить отдельно от runtime-кода, например:

- `sql/schema.sql`
- `sql/queries/session.sql`
- `sql/queries/api_stats.sql`

Формат должен быть близок к `sqlc`:

```sql
-- name: FindSessionById :one
SELECT id, user_id, payload, expires_at, created_at, updated_at
FROM sessions
WHERE id = :id;

-- name: FindSessionsByUserId :many
SELECT id, user_id, payload, expires_at, created_at, updated_at
FROM sessions
WHERE user_id = :user_id
ORDER BY created_at DESC;

-- name: DeleteExpiredSessions :exec
DELETE FROM sessions
WHERE expires_at < :now;
```

Для проекта предпочтительны именованные параметры `:param`, а не позиционные placeholders.

Типы для generated row classes должны резолвиться из SQL schema source, а не дублироваться в ручных PHP metadata files.

## Целевой output

Генератор должен создавать typed PHP-код, а не runtime arrays.

Целевая модель output:

- `*Params`
- `*Row` для `:one` и `:many`
- `*Query`
- `*Queries` facade для группы запросов из одного SQL-файла

Пример дерева:

- `gen/Generated/Sql/Session/FindSessionByIdParams.php`
- `gen/Generated/Sql/Session/SessionRow.php`
- `gen/Generated/Sql/Session/FindSessionByIdQuery.php`
- `gen/Generated/Sql/Session/SessionQueries.php`

### MVP первого этапа

На первом этапе допускается более узкий generated output:

- `*Params`
- `*Query`
- `*Queries`

То есть initial MVP не обязан сразу генерировать `*Row`.

Главное требование первого этапа:

- SQL уже становится source of truth;
- generated PHP перестаёт быть shape-массивами;
- runtime получает executable typed query objects.

### Следующий этап

Следующий обязательный шаг после MVP:

- `sql/schema.sql` становится SQL source of truth для column types;
- генератор начинает резолвить row fields из `SELECT` + schema SQL;
- `:one` и `:many` queries получают generated `*Row`.

## Runtime слой

Runtime должен оставаться тонким.

Достаточно небольшого набора reusable blocks:

- `SqlExecutor`
- опционально `TransactionManager`
- опционально маленький helper для row mapping

Runtime не должен заново интерпретировать SQL.
Он должен только исполнять generated query classes.

При этом `sql-gen` не должен быть жёстко пришит к одному runtime namespace.

Правильная модель:

- generated namespace настраивается через config/CLI;
- runtime contract FQCN (`DatabaseRow`, `ExecutableQuery`, `OneRowQuery`, `ManyRowsQuery`, `RowReturningQuery`) идут через typed runtime-contract layer;
- CLI должен позволять менять runtime namespace без патча генератора.

Неправильная модель:

- держать `App\Platform\Storage\Sql\...` хардкоженным прямо в генераторе;
- считать runtime namespace “по сути фиксированным”, даже если generated namespace уже конфигурируется.

## Profile model

`sql-gen` должен развиваться как generic engine с внешним project profile.

Правильная модель:

- внутри `tools/sql-gen` живут только generic contracts, default profile и profile resolver;
- project-specific SQL generation profile живёт снаружи тулзы;
- CLI поддерживает `bootstrap + profile-class`;
- основной проект подключает свой profile adapter через `codegen/`, а не через встроенное знание тулзы.

Неправильная модель:

- держать `base-api-template` SQL profile внутри самой тулзы;
- зашивать project naming/runtime conventions в generic `sql-gen` core.

## Как использовать type libraries

В `sql-gen` допустимы небольшие type-system библиотеки вроде `typhoon/type`, но только как внутренний слой генератора.

Правильное использование:

- рендерить через них PHPDoc shapes и другие generated type strings;
- уменьшать ручную сборку type-строк внутри тулзы;
- держать `Typhoon\Type` canonical type model внутри самого `sql-gen`, а не строки вида `'int'` / `'string'`;
- не тащить эти зависимости в runtime проекта;
- не делать их новым source of truth поверх SQL schema.

Неправильное использование:

- делать `typhoon/type` частью runtime path;
- генерировать код, который требует эту библиотеку при исполнении;
- вводить отдельный PHP metadata layer рядом с `sql/schema.sql` и `sql/queries/*.sql`.

## Проверка запросов

Проект PostgreSQL-first, поэтому `sql-gen` должен ориентироваться именно на PostgreSQL.

Правильная модель валидации:

- `php tools/project.php sql:gen` — генерация PHP-кода из SQL;
- `php tools/project.php sql:check` — автономная проверка синхронизации generated SQL artifacts с `sql/schema.sql` и `sql/queries/*`;
- `php tools/project.php sql:check:pg` — PostgreSQL-backed проверка самих запросов через `PREPARE` against real database schema;
- default `php tools/project.php verify` не обязан требовать поднятую БД;
- более строгий full-gate должен включать `sql:check:pg`.

Это согласуется с текущим разделением между обычным локальным gate и PostgreSQL-dependent профилями.

## Как это соотносится с текущим storage слоем

Старый `QueryBuilder` path уже не является поддерживаемой моделью проекта.

Целевое recommended направление:

- `sql/schema.sql` как SQL source of truth для column types;
- raw SQL в `sql/queries/*.sql`;
- generated typed query classes;
- тонкий executor;
- repository как тонкий адаптер над generated queries.

## Первый практический slice

Первым кандидатом должен быть `Session`.

Почему:

- это reusable capability, а не продуктовая фича;
- у него уже есть понятные SQL-сценарии;
- он хорошо подходит как эталонный migration path.

Минимальный MVP должен уметь покрыть:

- `findById`
- `findByUserId`
- `findAll`
- `deleteExpired`

## Критерий качества

`sql-gen` развивается правильно, если:

- SQL остаётся основным языком persistence;
- generated PHP уменьшает boilerplate, а не прячет запрос;
- PostgreSQL проверяется как реальный target;
- developer и LLM видят один явный source of truth: SQL файл.

## Parser strategy

Regex-based parsing больше не должен быть production path для query analysis.

Для долгосрочного развития `sql-gen` parser-front-end должен эволюционировать в сторону собственного subset parser-а, а не в сторону бесконечного наращивания regex-эвристик.

Правильная целевая модель:

- `sql-gen` не пытается реализовать весь PostgreSQL parser;
- `sql-gen` поддерживает только свой документированный SQL subset;
- grammar этого subset-а может быть реализована на `phplrt`;
- parser строит внутренний AST `sql-gen`, а не тянет чужую AST-модель сквозь весь кодогенератор;
- PostgreSQL-backed `sql:check:pg` остаётся обязательным safety net даже после перехода на AST.

Текущее production состояние должно быть именно таким:

- поддерживаемый subset SQL парсится через `phplrt`;
- row resolution и parameter resolution работают поверх internal AST;
- schema SQL тоже разбирается через `phplrt`-based parser path;
- named SQL file format разбирается структурно, без regex-эвристик в production path;
- regex больше не должен быть частью `sql-gen` parsing/analysis semantics.

### Type model strategy

Внутренний type model `sql-gen` должен быть единым.

Правильная модель:

- SQL schema определяет source types;
- `SqlSchemaParser` маппит их в `Typhoon\Type`;
- `SchemaColumn`, `RowField`, `ResolvedSqlParameter` хранят именно `Typhoon\Type`, а не строковые PHP-типы;
- генерация native type-hints, PHPDoc shapes и value-casting идёт через единый Typhoon-backed renderer layer.

Неправильная модель:

- хранить canonical type как строку и потом отдельно собирать PHPDoc;
- допускать `string|Type` как постоянный публичный контракт внутренних моделей;
- размазывать `stringify()` и ручные `match ('int'|'string')` по generator коду.

Неправильная модель:

- обещать поддержку произвольного PostgreSQL SQL;
- продолжать масштабировать regex-parser для `JOIN`, `RETURNING`, `CASE`, `COALESCE`, nested expressions и других конструкций;
- превращать parser spike сразу в production path без ограничения subset-а.

### Что считать первым поддерживаемым subset-ом

Первый осмысленный subset для `phplrt`-parser-а:

- `SELECT ... FROM ...`
- `JOIN ... ON ...`
- `WHERE`
- `INSERT INTO ... VALUES ...`
- `ON CONFLICT ... DO UPDATE`
- `RETURNING`
- column references
- aliases
- placeholders `:param`
- простые function/expression nodes как структурированные AST-элементы

Что не нужно поддерживать на первом этапе:

- CTE
- `UNION`
- nested subqueries
- window functions
- полный expression grammar PostgreSQL

### Архитектурный принцип

Если `phplrt` будет использоваться в `sql-gen`, то только так:

```text
SQL text
-> phplrt grammar/parser
-> internal sql-gen AST
-> schema/type resolution
-> PHP generation
-> PostgreSQL-backed validation
```

То есть `phplrt` нужен не как “готовый SQL parser”, а как основа для нашего subset parser-а.

## Roadmap

Ниже зафиксирован предпочтительный порядок развития `sql-gen`.

### Что уже считается устойчивым ядром

- `sql-gen` работает как generic engine с внешним project profile;
- query SQL и schema SQL разбираются через `phplrt`-based AST path, а не через regex-эвристики;
- canonical PHP type model внутри тулзы уже Typhoon-based;
- runtime contracts и artifact naming вынесены в typed profile;
- `sql:check` и `sql:check:pg` считаются обязательной частью quality model;
- `Session` и `ApiStats` уже подтверждают жизнеспособность SQL-first persistence path на реальных consumers.

### Ближайшие шаги

1. Усиливать expression typing поверх AST.

- продолжать расширять typed inference для `SELECT`/`RETURNING` expressions;
- после `COUNT`, `MIN`, `MAX`, `COALESCE`, `CASE` идти в более богатую совместимость типов, а не в string-based special cases;
- учить inference понимать placeholder-driven expressions и mixed-nullability более точно.

2. Усиливать relation-aware schema semantics.

- развивать validation `JOIN` не только для одиночных foreign key columns, но и для составных ключей;
- аккуратно усиливать проверки вокруг relation metadata, не превращая их в ложноположительные жёсткие запреты там, где схема ничего не говорит.

3. Расширять поддерживаемый SQL subset по реальным кейсам.

- добавлять новые конструкции только когда они нужны реальным query files проекта;
- предпочитать small, explicit AST nodes вместо “универсального expression kitchen sink”;
- каждое расширение должно проходить через parser, semantic inference и PostgreSQL-backed validation, а не только через generator rendering.

4. Улучшать diagnostics как product feature.

- source-aware ошибки должны включать query name, file path и понятный semantic context;
- ошибки parser-а, schema resolution и type inference должны оставаться короткими, но достаточно точными, чтобы по ним было легко чинить SQL source.

### Что делать не нужно

- не пытаться превратить `sql-gen` в ORM;
- не пытаться поддержать “почти весь PostgreSQL” заранее;
- не плодить отдельные metadata files рядом с SQL;
- не дублировать schema truth в PHP-классы или атрибуты;
- не заменять typed inference ручными string rules, если задачу можно решить через AST + Typhoon model.

### Критерий хорошего следующего шага

Новый шаг в развитии `sql-gen` считается хорошим, если он одновременно:

- расширяет поддерживаемый SQL-first workflow на реальный кейс;
- усиливает AST + semantic inference model, а не размывает её;
- не требует возвращаться к regex или stringly-typed contracts;
- сохраняет PostgreSQL как реальный target, а не абстрактный диалект.
