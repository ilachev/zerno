<?php

declare(strict_types=1);

namespace SqlGen\Schema;

use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\SelectProjection;
use SqlGen\Ast\SelectProjectionCase;
use SqlGen\Ast\SelectProjectionColumn;
use SqlGen\Ast\SelectProjectionFunction;
use SqlGen\Ast\SelectProjectionWildcard;
use SqlGen\Ast\SelectQuery;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\RowField;
use SqlGen\Model\SqlResultKind;
use SqlGen\Model\SqlStatement;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Parser\SqlQueryParser;

final class StatementRowResolver
{
    private StatementTableMapResolver $tableMapResolver;
    private SqlQueryParser $sqlParser;
    private SelectProjectionTypeInferrer $projectionTypeInferrer;
    private StatementParameterResolver $parameterResolver;

    public function __construct(
        ?StatementTableMapResolver $tableMapResolver = null,
        ?SqlQueryParser $sqlParser = null,
        ?SelectProjectionTypeInferrer $projectionTypeInferrer = null,
        ?StatementParameterResolver $parameterResolver = null,
    ) {
        $this->tableMapResolver = $tableMapResolver ?? new StatementTableMapResolver();
        $this->sqlParser = $sqlParser ?? new PhplrtSqlParser();
        $this->projectionTypeInferrer = $projectionTypeInferrer ?? new SelectProjectionTypeInferrer();
        $this->parameterResolver = $parameterResolver ?? new StatementParameterResolver($this->tableMapResolver, $this->sqlParser);
    }

    /**
     * @return list<RowField>
     */
    public function resolve(SqlStatement $statement, DatabaseSchema $schema): array
    {
        if ($statement->resultKind === SqlResultKind::Exec) {
            return [];
        }

        $tableMap = $this->tableMapResolver->resolve($statement, $schema);
        $parametersByName = [];
        foreach ($this->parameterResolver->resolve($statement, $schema) as $parameter) {
            $parametersByName[$parameter->name] = $parameter;
        }

        $projections = $this->resolveSelectedColumns($statement, $tableMap, $parametersByName);
        $seenResultColumns = [];

        foreach ($projections as $projection) {
            if (isset($seenResultColumns[$projection->resultColumn])) {
                throw new \RuntimeException(
                    "Duplicate result column {$projection->resultColumn} in query {$statement->name}",
                );
            }

            $seenResultColumns[$projection->resultColumn] = true;
        }

        return array_map(
            fn(ResolvedProjectionField $projection): RowField => new RowField(
                sourceColumnName: $projection->sourceName,
                resultColumnName: $projection->resultColumn,
                propertyName: $this->snakeToCamel($projection->resultColumn),
                phpType: $projection->phpType,
                nullable: $projection->nullable,
            ),
            $projections,
        );
    }

    /**
     * @return list<ResolvedProjectionField>
     * @param array<string, \SqlGen\Model\ResolvedSqlParameter> $parametersByName
     */
    private function resolveSelectedColumns(SqlStatement $statement, StatementTableMap $tableMap, array $parametersByName): array
    {
        $query = $this->sqlParser->parse($statement->sql);

        if ($query instanceof SelectQuery) {
            return $this->resolveProjections($query->projections, $statement->name, $tableMap, $parametersByName);
        }

        if (!$query instanceof InsertQuery && !$query instanceof UpdateQuery) {
            throw new \RuntimeException("SQL statement {$statement->name} does not expose row projections.");
        }

        return $this->resolveProjections($query->returning, $statement->name, $tableMap, $parametersByName);
    }

    /**
     * @param list<SelectProjection> $projections
     * @return list<ResolvedProjectionField>
     * @param array<string, \SqlGen\Model\ResolvedSqlParameter> $parametersByName
     */
    private function resolveProjections(array $projections, string $queryName, StatementTableMap $tableMap, array $parametersByName): array
    {
        $resolved = [];

        foreach ($projections as $projection) {
            if ($projection instanceof SelectProjectionWildcard) {
                array_push($resolved, ...$this->expandWildcardColumns($tableMap, $projection->table));
                continue;
            }

            if ($projection instanceof SelectProjectionColumn) {
                $resolvedColumn = $tableMap->resolveColumn(
                    $projection->reference->table !== null ? strtolower($projection->reference->table) : null,
                    $projection->reference->column,
                );
                $resolved[] = new ResolvedProjectionField(
                    sourceName: $projection->reference->column,
                    resultColumn: $projection->alias !== null ? $projection->alias->value : $projection->reference->column,
                    phpType: $resolvedColumn->column->phpType,
                    nullable: $resolvedColumn->column->nullable,
                );
                continue;
            }

            if ($projection instanceof SelectProjectionCase) {
                $resolved[] = $this->projectionTypeInferrer->inferCaseProjection(
                    expression: $projection->expression,
                    resultColumn: $projection->alias !== null ? $projection->alias->value : 'case',
                    tableMap: $tableMap,
                    queryName: $queryName,
                    parametersByName: $parametersByName,
                );
                continue;
            }

            if ($projection instanceof SelectProjectionFunction) {
                $resolved[] = $this->projectionTypeInferrer->inferFunctionProjection(
                    function: $projection->function,
                    resultColumn: $projection->alias !== null
                        ? $projection->alias->value
                        : strtolower($projection->function->name),
                    tableMap: $tableMap,
                    queryName: $queryName,
                    parametersByName: $parametersByName,
                );
                continue;
            }

            throw new \RuntimeException("Unsupported projection type in query {$queryName}");
        }

        return $resolved;
    }

    /**
     * @return list<ResolvedProjectionField>
     */
    private function expandWildcardColumns(StatementTableMap $tableMap, ?string $qualifier): array
    {
        $resolved = [];

        foreach ($tableMap->expandWildcard($qualifier) as $column) {
            $resolved[] = new ResolvedProjectionField(
                sourceName: $column->name,
                resultColumn: $column->name,
                phpType: $column->column->phpType,
                nullable: $column->column->nullable,
            );
        }

        return $resolved;
    }

    private function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }
}
