# Документация проекта

Документация специально сведена к короткому набору канонических файлов, чтобы человек и LLM не тратили время на чтение пересекающихся описаний одной и той же идеи.

## Канонический набор

1. [LLM Onboarding](./llm-onboarding.md) — главный обзор проекта: цель, структура, инварианты, зоны риска.
2. [Карта reusable-блоков](./reusable-blocks.md) — что именно является reusable core шаблона, а что является optional или example.
3. [Поток запроса](./architecture/request-lifecycle.md) — как запрос проходит через runtime и middleware.
4. [Protobuf и генерация кода](./architecture/protobuf-and-codegen.md) — как устроен transport-contract слой и codegen.
5. [Инфраструктура и тестирование](./development/infrastructure-and-testing.md) — PostgreSQL, Redis, миграции, quality gates и test profiles.
6. [Рекомендации по `protoc-php-gen`](./design/protoc-php-gen-guidelines.md) — правила для endpoint-oriented protobuf codegen.
7. [`protoc-php-gen` как продукт](./design/protoc-php-gen-product.md) — текущий supported scope и целевая modular model генератора.
8. [`sql-gen` как продукт](./design/sql-gen-product.md) — целевая `sqlc-like` модель для typed SQL generation.
9. [`Platform/Http/Client` как продукт](./design/http-client-product.md) — целевая модель outbound HTTP client с reliability policy.
10. [Автономное веб-ядро](./design/autonomous-core.md) — аудит текущих зависимостей, границы самостоятельной поставки и план перехода; это целевая модель, ещё не реализованный способ запуска.

## Как читать

- Если нужен общий контекст, начинать с `llm-onboarding.md`.
- Если нужно понять, какие части проекта реально составляют шаблон, читать `reusable-blocks.md`.
- Если нужно понять runtime, читать `architecture/request-lifecycle.md`.
- Если меняется API surface, читать `architecture/protobuf-and-codegen.md`.
- Если меняется persistence direction и SQL toolchain, читать `design/sql-gen-product.md`.
- Если меняется outbound HTTP policy или внешние интеграции, читать `design/http-client-product.md`.
- Если меняется storage, cache, миграции или тестовый контур, читать `development/infrastructure-and-testing.md`.

## Базовые правила

- Источник истины для core API template — `protos/proto/app/v1`.
- Сгенерированные артефакты нельзя редактировать вручную.
- `Platform` должен оставаться маленьким runtime core.
- `Capabilities` должны оставаться простыми reusable-блоками.
- Нейтральный `HealthCheck` остаётся минимальным живым примером protobuf-first endpoint flow.
- Для persistence целевым направлением считается explicit SQL с typed generation поверх него, а не усложнение ORM-like abstractions.
