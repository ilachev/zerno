# Транзакции и миграции

Тип: `design`. Статус: `current`. Сверено с кодом: 2026-10-23.

Владелец текущего контракта Storage.transaction и Migration. [Архитектура](../architecture/overview.md) описывает композицию, [инфраструктура](../development/infrastructure-and-testing.md) — TEST_DB_* и запуск проверок. PostgreSQL — единственный backend приложения.

## Одна операция — одна транзакция

```php
$result = $storage->transaction(function () use ($storage): int {
    $storage->execute('INSERT INTO records (id, value) VALUES (:id, :value)', [
        'id' => 42,
        'value' => 'example',
    ]);

    return 42;
});
```

transaction получает PDO лениво, начинает транзакцию, вызывает callback один раз, делает commit и только затем возвращает результат. Значение callback сохраняет тип через PHPStan template. SQL и зависимости остаются явными; контейнер, retry policy или transaction manager не требуются.

Операция владеет BEGIN/COMMIT/ROLLBACK. В callback не следует исполнять команды управления транзакцией вручную. Вложенный transaction отклоняется LogicException до вызова вложенного callback; savepoints не создаются. Если этот отказ пойман внутри внешнего callback, его исправная транзакция может продолжиться. Поддержка восстановления через ручные savepoints в этом контракте не предоставляется.

Любая PDO-ошибка query/queryOne/execute/lastInsertId во время callback запоминается до конца транзакции. Даже если callback поймал её и вернул значение, transaction выбросит первую StorageException и откатит операцию. Это исключает наблюдавшийся ложный успех PostgreSQL: commit после пойманной SQL-ошибки мог завершить aborted transaction как rollback. Вне transaction ошибка просто передаётся вызывающему коду и не удерживается Storage. lastInsertId теперь также сохраняет PDOException внутри StorageException, как остальные SQL-операции.

При обычном исключении callback сохраняется исходный объект. Ошибка begin/commit остаётся исходной PDOException с SQLSTATE. После неудачного commit сервер уже может закончить транзакцию: [inTransaction](https://www.php.net/manual/en/pdo.intransaction.php) проверяется перед попыткой rollback, чтобы вторичное «нет активной транзакции» не затёрло исходную причину. [PDO commit](https://www.php.net/manual/en/pdo.commit.php) не заменяет проверку завершения операции.

Если rollback тоже не удался, выбрасывается TransactionRollbackException: getPrevious() содержит исходный сбой, readonly rollbackFailure — ошибку очистки. Этот Storage нужно отбросить и явно собрать новый; автоматического переподключения/повтора нет. При разрыве связи во время commit результат записи может требовать отдельной проверки перед ручным повтором. После завершения любого пути внутренние флаги/ссылка на ошибку очищаются в finally; успешный rollback позволяет следующей операции использовать то же соединение.

## Явные SQL-списки миграции

```php
final readonly class Migration20300101001_Records extends AbstractMigration
{
    public function up(): array
    {
        return [
            'CREATE TABLE records (id BIGSERIAL PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE INDEX records_value_idx ON records (value)',
        ];
    }

    public function down(): array
    {
        return ['DROP TABLE records'];
    }
}
```

Здесь AbstractMigration — App\Platform\Storage\Migration\AbstractMigration, как в [миграции каталога](../../examples/document-catalog/migrations/Migration20261017001_CreateDocuments.php). getVersion() этой базовой реализации возвращает имя класса без namespace. Собственная реализация Migration может задавать версию явно.

up()/down() возвращают `list<string>`, каждый элемент — один полный SQL statement. Пустой список допустим: меняется только запись версии. Точка с запятой внутри литерала, комментария или dollar-quoted тела остаётся частью statement. MigrationService не разбивает SQL по `;`; несколько отдельных команд нужно поместить в отдельные элементы. Параметры runtime из HTTP сюда не поступают: это доверенный код миграции, загруженный CLI из явно выбранной директории.

Это несовместимое изменение прежнего контракта `up(): string` / `down(): string`. Собственные миграции нужно перевести на массивы отдельных SQL. Совместимый string fallback, SQL parser и второй режим выполнения не добавлены. Уже применённые версии не запускаются заново только из-за обновления движка; DDL каталога и имя его версии сохранены.

## Атомарность версии и восстановление

MigrationService проверяет уникальность версий до выполнения up/down; дубли — LogicException вместо прежнего пропуска одной реализации. Для migrate применяется лексикографический порядок версий, уже зарегистрированные пропускаются. rollback выполняет зарегистрированные версии в обратном порядке.

Каждая версия имеет собственную транзакцию: все её statements и INSERT/DELETE строки в migrations либо завершаются вместе, либо откатываются вместе. Исключение останавливает проход. Успешно завершённые предыдущие версии сохраняются; вся серия не является одной общей транзакцией. При неудачном down восстанавливаются удалённые этой версией таблицы/данные и сохраняется её версия. Проверена также ошибка deferred constraint на commit после добавления версии.

После исправления миграции оператор явно повторяет ту же CLI-команду:

```bash
php bin/migrate.php migrate examples/document-catalog/migrations
php bin/migrate.php rollback examples/document-catalog/migrations
```

Неисправная версия и следующие действия сами не повторяются. Инициализация служебной таблицы migrations выполняется отдельно в MigrationRepository constructor; она может остаться после первой неудачной миграции. HTTP startup и services:start не запускают DDL. Остатки частичных миграций прежнего движка требуют отдельной сверки фактической схемы и версий; новый движок не удаляет их автоматически.

Все миграции транзакционные. `CREATE INDEX CONCURRENTLY` и другие команды, запрещённые внутри transaction, приводят к ошибке PostgreSQL и откату текущей версии. Non-transactional флага нет. Запуски миграций оператор выполняет последовательно: механизма сериализации нескольких CLI-процессов или distributed lock проект не предоставляет.

## Цена и проверка

Путь разработчика — один обычный callback либо список SQL в up/down; бизнес-правила, выбор запросов и ручной повтор остаются у приложения. Проверка пойманной ошибки хранит одну ссылку только на время активного callback и не добавляет SQL probes. Обычный transaction сохраняет BEGIN/COMMIT; migrate/rollback теперь добавляют BEGIN и COMMIT на каждую версию. Для каталога up состоит из двух DDL и одной записи версии, плюс две команды границы транзакции. Это структура SQL-вызовов, не измерение полного протокола prepare или latency. Ускорение этим изменением не заявляется.

Старое разбиение строк удалено. Миграции не являются частью HTTP hot path. Новые библиотеки, downloads, генераторы или обходы manifest не требуются. Без вызова transaction/миграций их SQL не выполняется; стоимость загрузки классов не объявляется нулевой.

```bash
php tools/project.php verify
php tools/project.php verify:integration
php tools/project.php verify:full
```

[TransactionRecoveryTest](../../tests/Integration/Storage/TransactionRecoveryTest.php) проверяет пойманные SQL-ошибки всех путей Storage, nested calls, deferred commit и потерю соединения при rollback, исходные причины и следующий callback. [MigrationRecoveryTest](../../tests/Integration/Storage/MigrationRecoveryTest.php) проверяет DDL/данные/версии, корректировку и повтор, SQL-литералы/DO, дубли и запрещённый transactional DDL. [MigrationCliRecoveryTest](../../tests/Integration/Storage/MigrationCliRecoveryTest.php) исправляет реальные PHP-файлы между отдельными CLI-процессами. Автономные [unit tests](../../tests/Unit/Platform/Storage/Migration/MigrationServiceTest.php) используют настоящий SQLite fixture и не заменяют PostgreSQL checks.

[Данные ревизии от 2026-10-23](../history/2026-10-23-transaction-recovery.json) сохраняют наблюдавшиеся сбои до изменения и исправленные результаты. Исторический probe привязан к указанному Git-состоянию; старые string-сигнатуры из него не являются текущей инструкцией.

Проверка этапа: verify:full — 265 unit / 167 SQL-tool / 58 PostgreSQL tests; распакованный архив — 68 HTTP-сценариев на SAPI/RR3 с PostgreSQL и явными CLI миграциями, плюс 5 HTTP-сценариев source baseline. Это проверки корректности, не длительный load test.
