<?php

declare(strict_types=1);

namespace SqlGen\Schema;

use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\SelectColumnReference;
use SqlGen\Ast\SelectJoin;
use SqlGen\Ast\SelectQuery;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\SqlStatement;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Parser\SqlQueryParser;

final class StatementTableMapResolver
{
    private SqlQueryParser $sqlParser;

    public function __construct(
        ?SqlQueryParser $sqlParser = null,
    ) {
        $this->sqlParser = $sqlParser ?? new PhplrtSqlParser();
    }

    public function resolve(SqlStatement $statement, DatabaseSchema $schema): StatementTableMap
    {
        $visibleTables = [];
        $query = $this->sqlParser->parse($statement->sql);
        $primaryTable = null;

        if ($query instanceof SelectQuery) {
            $primaryTable = $this->resolveVisibleTable($query->from->table, $query->from->alias, $schema, $statement->name);
            $visibleTables[] = $primaryTable;

            foreach ($query->joins as $join) {
                $joinedTable = $this->resolveVisibleTable(
                    $join->table->table,
                    $join->table->alias,
                    $schema,
                    $statement->name,
                );
                $this->validateJoinRelation($join, $visibleTables, $joinedTable, $statement->name);
                $visibleTables[] = $joinedTable;
            }
        }

        if ($query instanceof InsertQuery || $query instanceof UpdateQuery) {
            $primaryTable = $this->resolveVisibleTable($query->table, null, $schema, $statement->name);
            $visibleTables[] = $primaryTable;
        }

        if ($query instanceof DeleteQuery) {
            $primaryTable = $this->resolveVisibleTable($query->table, null, $schema, $statement->name);
            $visibleTables[] = $primaryTable;
        }

        if (!$primaryTable instanceof StatementVisibleTable) {
            throw new \RuntimeException("Unable to resolve table name for query {$statement->name}");
        }

        return new StatementTableMap(
            visibleTables: $visibleTables,
            primaryTable: $primaryTable,
            queryName: $statement->name,
        );
    }

    private function resolveVisibleTable(
        string $tableName,
        ?string $alias,
        DatabaseSchema $schema,
        string $queryName,
    ): StatementVisibleTable {
        $normalizedTableName = strtolower($tableName);
        $table = $schema->getTable($normalizedTableName);
        if ($table === null) {
            throw new \RuntimeException("Table {$normalizedTableName} was not found in schema for query {$queryName}");
        }

        return new StatementVisibleTable($table, $alias);
    }

    /**
     * @param list<StatementVisibleTable> $visibleTables
     */
    private function validateJoinRelation(
        SelectJoin $join,
        array $visibleTables,
        StatementVisibleTable $joinedTable,
        string $queryName,
    ): void {
        $condition = $join->condition;
        if (
            $condition->operator !== '='
            || !$condition->left instanceof SelectColumnReference
            || !$condition->right instanceof SelectColumnReference
        ) {
            return;
        }

        $leftColumn = $this->resolveJoinColumn($condition->left, [...$visibleTables, $joinedTable], $queryName);
        $rightColumn = $this->resolveJoinColumn($condition->right, [...$visibleTables, $joinedTable], $queryName);

        if ($leftColumn->table->name === $rightColumn->table->name) {
            return;
        }

        $leftTable = $leftColumn->table;
        $rightTable = $rightColumn->table;
        $tablesAreRelated = $leftTable->referencesTable($rightTable->name)
            || $rightTable->referencesTable($leftTable->name);

        if (!$tablesAreRelated) {
            return;
        }

        $matchesRelation = $leftTable->matchesForeignKeyReference(
            $rightTable->name,
            [$leftColumn->name],
            [$rightColumn->name],
        ) || $rightTable->matchesForeignKeyReference(
            $leftTable->name,
            [$rightColumn->name],
            [$leftColumn->name],
        );

        if ($matchesRelation) {
            return;
        }

        throw new \RuntimeException(
            sprintf(
                'JOIN condition %s.%s %s %s.%s does not match any schema foreign key relation in query %s',
                $leftTable->name,
                $leftColumn->name,
                $condition->operator,
                $rightTable->name,
                $rightColumn->name,
                $queryName,
            ),
        );
    }
    /**
     * @param list<StatementVisibleTable> $visibleTables
     */
    private function resolveJoinColumn(
        SelectColumnReference $columnReference,
        array $visibleTables,
        string $queryName,
    ): ResolvedSchemaColumn {
        foreach ($visibleTables as $visibleTable) {
            if (
                $columnReference->table !== null
                && $columnReference->table !== ''
                && !$visibleTable->matchesQualifier($columnReference->table)
            ) {
                continue;
            }

            $column = $visibleTable->resolveColumn($columnReference->column);
            if ($column !== null) {
                return $column;
            }
        }

        $qualifiedColumn = $columnReference->table !== null && $columnReference->table !== ''
            ? $columnReference->table . '.' . $columnReference->column
            : $columnReference->column;

        throw new \RuntimeException(
            "Unable to resolve JOIN column {$qualifiedColumn} in query {$queryName}",
        );
    }
}
