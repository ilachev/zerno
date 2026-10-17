<?php

declare(strict_types=1);

namespace SqlGen\Check;

use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertConflictAssignment;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\InsertValueMapping;
use SqlGen\Ast\SelectCaseExpression;
use SqlGen\Ast\SelectColumnReference;
use SqlGen\Ast\SelectComparison;
use SqlGen\Ast\SelectFunctionCall;
use SqlGen\Ast\SelectJoin;
use SqlGen\Ast\SelectOperand;
use SqlGen\Ast\SelectOrderByItem;
use SqlGen\Ast\SelectPlaceholder;
use SqlGen\Ast\SelectProjection;
use SqlGen\Ast\SelectProjectionCase;
use SqlGen\Ast\SelectProjectionColumn;
use SqlGen\Ast\SelectProjectionFunction;
use SqlGen\Ast\SelectProjectionWildcard;
use SqlGen\Ast\SelectQuery;
use SqlGen\Ast\SelectTableReference;
use SqlGen\Ast\SqlQuery;
use SqlGen\Ast\UpdateAssignment;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\ResolvedSqlParameter;
use SqlGen\Model\SqlStatement;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Parser\SqlQueryParser;
use SqlGen\Schema\StatementParameterResolver;

final readonly class PostgreSqlStatementCompiler
{
    private StatementParameterResolver $parameterResolver;

    private SqlQueryParser $sqlParser;

    public function __construct(
        ?StatementParameterResolver $parameterResolver = null,
        ?SqlQueryParser $sqlParser = null,
    ) {
        $this->parameterResolver = $parameterResolver ?? new StatementParameterResolver();
        $this->sqlParser = $sqlParser ?? new PhplrtSqlParser();
    }

    public function compile(SqlStatement $statement, DatabaseSchema $schema): PreparedPostgreSqlStatement
    {
        $parameters = $this->parameterResolver->resolve($statement, $schema);
        $parameterIndexByName = [];

        foreach ($parameters as $index => $parameter) {
            $parameterIndexByName[$parameter->name] = $index + 1;
        }

        $query = $this->sqlParser->parse($statement->sql);
        $this->assertQueryMatchesSchema($query, $schema, $statement->name);

        return new PreparedPostgreSqlStatement(
            name: $statement->name,
            sql: $this->renderQuery($query, $parameterIndexByName, $statement->name),
            parameterTypes: array_map(
                fn(ResolvedSqlParameter $parameter): string => $this->mapParameterType($parameter->sqlType),
                $parameters,
            ),
        );
    }

    private function assertQueryMatchesSchema(SqlQuery $query, DatabaseSchema $schema, string $statementName): void
    {
        if (!$query instanceof InsertQuery || $query->conflict === null) {
            return;
        }

        $table = $schema->getTable(strtolower($query->table));
        if ($table === null) {
            throw new \RuntimeException("Table {$query->table} was not found in schema for query {$statementName}");
        }

        if ($table->supportsConflictTarget($query->conflict->targetColumns)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'ON CONFLICT target (%s) in query %s does not match any PRIMARY KEY or UNIQUE constraint on table %s',
            implode(', ', $query->conflict->targetColumns),
            $statementName,
            $table->name,
        ));
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderQuery(SqlQuery $query, array $parameterIndexByName, string $statementName): string
    {
        if ($query instanceof SelectQuery) {
            return $this->renderSelectQuery($query, $parameterIndexByName, $statementName);
        }

        if ($query instanceof InsertQuery) {
            return $this->renderInsertQuery($query, $parameterIndexByName, $statementName);
        }

        if ($query instanceof UpdateQuery) {
            return $this->renderUpdateQuery($query, $parameterIndexByName, $statementName);
        }

        if (!$query instanceof DeleteQuery) {
            throw new \RuntimeException('Unsupported SQL query type in PostgreSQL statement renderer.');
        }

        return $this->renderDeleteQuery($query, $parameterIndexByName, $statementName);
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderSelectQuery(SelectQuery $query, array $parameterIndexByName, string $statementName): string
    {
        $sql = 'SELECT ' . implode(', ', array_map(
            fn(SelectProjection $projection): string => $this->renderProjection($projection, $parameterIndexByName, $statementName),
            $query->projections,
        ));
        $sql .= ' FROM ' . $this->renderTableReference($query->from);

        foreach ($query->joins as $join) {
            $sql .= ' ' . $this->renderJoin($join, $parameterIndexByName, $statementName);
        }

        if ($query->where !== []) {
            $sql .= ' WHERE ' . $this->renderComparisons($query->where, $query->whereOperators, $parameterIndexByName, $statementName);
        }

        if ($query->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn(SelectOrderByItem $item): string => $this->renderOrderByItem($item),
                $query->orderBy,
            ));
        }

        return $sql;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderInsertQuery(InsertQuery $query, array $parameterIndexByName, string $statementName): string
    {
        $sql = 'INSERT INTO ' . $query->table;
        $sql .= ' (' . implode(', ', array_map(
            static fn(InsertValueMapping $value): string => $value->column,
            $query->values,
        )) . ')';
        $sql .= ' VALUES (' . implode(', ', array_map(
            fn(InsertValueMapping $value): string => $this->renderPlaceholder($value->placeholder, $parameterIndexByName, $statementName),
            $query->values,
        )) . ')';

        if ($query->conflict !== null) {
            $sql .= ' ON CONFLICT (' . implode(', ', $query->conflict->targetColumns) . ')';
            $sql .= ' DO UPDATE SET ' . implode(', ', array_map(
                static fn(InsertConflictAssignment $assignment): string => \sprintf(
                    '%s = EXCLUDED.%s',
                    $assignment->column,
                    $assignment->excludedColumn,
                ),
                $query->conflict->assignments,
            ));
        }

        if ($query->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', array_map(
                fn(SelectProjection $projection): string => $this->renderProjection($projection, $parameterIndexByName, $statementName),
                $query->returning,
            ));
        }

        return $sql;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderUpdateQuery(UpdateQuery $query, array $parameterIndexByName, string $statementName): string
    {
        $sql = 'UPDATE ' . $query->table . ' SET ' . implode(', ', array_map(
            fn(UpdateAssignment $assignment): string => $assignment->column . ' = '
                . $this->renderPlaceholder($assignment->placeholder, $parameterIndexByName, $statementName),
            $query->assignments,
        ));

        if ($query->where !== []) {
            $sql .= ' WHERE ' . $this->renderComparisons($query->where, $query->whereOperators, $parameterIndexByName, $statementName);
        }

        if ($query->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', array_map(
                fn(SelectProjection $projection): string => $this->renderProjection($projection, $parameterIndexByName, $statementName),
                $query->returning,
            ));
        }

        return $sql;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderDeleteQuery(DeleteQuery $query, array $parameterIndexByName, string $statementName): string
    {
        return 'DELETE FROM ' . $query->table
            . ' WHERE '
            . $this->renderComparisons($query->where, $query->whereOperators, $parameterIndexByName, $statementName);
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderProjection(SelectProjection $projection, array $parameterIndexByName, string $statementName): string
    {
        if ($projection instanceof SelectProjectionWildcard) {
            return $projection->table !== null ? $projection->table . '.*' : '*';
        }

        if ($projection instanceof SelectProjectionFunction) {
            $function = $this->renderFunctionCall($projection->function, $parameterIndexByName, $statementName);

            if ($projection->alias === null) {
                return $function;
            }

            return $function . ' AS ' . $projection->alias->value;
        }

        if ($projection instanceof SelectProjectionCase) {
            $expression = $this->renderCaseExpression($projection->expression, $parameterIndexByName, $statementName);

            if ($projection->alias === null) {
                return $expression;
            }

            return $expression . ' AS ' . $projection->alias->value;
        }

        if ($projection instanceof SelectProjectionColumn) {
            $column = $this->renderColumnReference($projection->reference);

            if ($projection->alias === null) {
                return $column;
            }

            return $column . ' AS ' . $projection->alias->value;
        }

        throw new \RuntimeException('Unsupported projection type in PostgreSQL statement renderer.');
    }

    private function renderTableReference(SelectTableReference $tableReference): string
    {
        if ($tableReference->alias === null) {
            return $tableReference->table;
        }

        return $tableReference->table . ' AS ' . $tableReference->alias;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     * @param list<SelectComparison> $comparisons
     * @param list<string> $operators
     */
    private function renderComparisons(array $comparisons, array $operators, array $parameterIndexByName, string $statementName): string
    {
        $parts = [];

        foreach ($comparisons as $index => $comparison) {
            if ($index > 0) {
                $parts[] = strtoupper($operators[$index - 1] ?? 'and');
            }

            $parts[] = $this->renderComparison($comparison, $parameterIndexByName, $statementName);
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderComparison(SelectComparison $comparison, array $parameterIndexByName, string $statementName): string
    {
        return $this->renderOperand($comparison->left, $parameterIndexByName, $statementName)
            . ' ' . $comparison->operator . ' '
            . $this->renderOperand($comparison->right, $parameterIndexByName, $statementName);
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderOperand(SelectOperand $operand, array $parameterIndexByName, string $statementName): string
    {
        if ($operand instanceof SelectColumnReference) {
            return $this->renderColumnReference($operand);
        }

        if (!$operand instanceof SelectPlaceholder) {
            throw new \RuntimeException('Unsupported operand type in PostgreSQL statement renderer.');
        }

        return $this->renderPlaceholder($operand, $parameterIndexByName, $statementName);
    }

    private function renderColumnReference(SelectColumnReference $columnReference): string
    {
        if ($columnReference->table === null) {
            return $columnReference->column;
        }

        return $columnReference->table . '.' . $columnReference->column;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderFunctionCall(SelectFunctionCall $function, array $parameterIndexByName, string $statementName): string
    {
        if ($function->wildcard) {
            return strtoupper($function->name) . '(*)';
        }

        return strtoupper($function->name) . '(' . implode(', ', array_map(
            fn(SelectOperand $argument): string => $this->renderOperand($argument, $parameterIndexByName, $statementName),
            $function->arguments,
        )) . ')';
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderCaseExpression(
        SelectCaseExpression $expression,
        array $parameterIndexByName,
        string $statementName,
    ): string {
        $parts = ['CASE'];

        foreach ($expression->whenClauses as $whenClause) {
            $parts[] = 'WHEN ' . $this->renderComparison($whenClause->condition, $parameterIndexByName, $statementName);
            $parts[] = 'THEN ' . $this->renderOperand($whenClause->result, $parameterIndexByName, $statementName);
        }

        $parts[] = 'ELSE ' . $this->renderOperand($expression->elseResult, $parameterIndexByName, $statementName);
        $parts[] = 'END';

        return implode(' ', $parts);
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderPlaceholder(SelectPlaceholder $placeholder, array $parameterIndexByName, string $statementName): string
    {
        $index = $parameterIndexByName[$placeholder->name] ?? null;
        if (!\is_int($index)) {
            throw new \RuntimeException(
                "Unable to compile PostgreSQL statement {$statementName}: unknown parameter {$placeholder->name}",
            );
        }

        return '$' . $index;
    }

    /**
     * @param array<string, int> $parameterIndexByName
     */
    private function renderJoin(SelectJoin $join, array $parameterIndexByName, string $statementName): string
    {
        $prefix = $join->type !== null ? strtoupper($join->type) . ' JOIN' : 'JOIN';

        return $prefix
            . ' ' . $this->renderTableReference($join->table)
            . ' ON ' . $this->renderComparison($join->condition, $parameterIndexByName, $statementName);
    }

    private function renderOrderByItem(SelectOrderByItem $item): string
    {
        $sql = $this->renderColumnReference($item->column);

        if ($item->direction !== null) {
            $sql .= ' ' . strtoupper($item->direction);
        }

        return $sql;
    }

    private function mapParameterType(string $sqlType): string
    {
        return match (strtoupper($sqlType)) {
            'TEXT' => 'text',
            'JSONB' => 'jsonb',
            'INTEGER', 'SERIAL' => 'integer',
            'BIGINT', 'BIGSERIAL' => 'bigint',
            'REAL' => 'real',
            'DOUBLE' => 'double precision',
            'NUMERIC', 'DECIMAL' => 'numeric',
            'BOOLEAN', 'BOOL' => 'boolean',
            default => throw new \RuntimeException("Unsupported PostgreSQL parameter type: {$sqlType}"),
        };
    }
}
