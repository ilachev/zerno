<?php

declare(strict_types=1);

namespace SqlGen\Schema;

use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\SelectColumnReference;
use SqlGen\Ast\SelectComparison;
use SqlGen\Ast\SelectPlaceholder;
use SqlGen\Ast\SelectProjectionCase;
use SqlGen\Ast\SelectProjectionFunction;
use SqlGen\Ast\SelectQuery;
use SqlGen\Ast\SqlQuery;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\ResolvedSqlParameter;
use SqlGen\Model\SqlStatement;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Parser\SqlQueryParser;
use SqlGen\Type\PhpTypeFactory;

final class StatementParameterResolver
{
    private StatementTableMapResolver $tableMapResolver;
    private SqlQueryParser $sqlParser;

    public function __construct(
        ?StatementTableMapResolver $tableMapResolver = null,
        ?SqlQueryParser $sqlParser = null,
    ) {
        $this->tableMapResolver = $tableMapResolver ?? new StatementTableMapResolver();
        $this->sqlParser = $sqlParser ?? new PhplrtSqlParser();
    }

    /**
     * @return list<ResolvedSqlParameter>
     */
    public function resolve(SqlStatement $statement, DatabaseSchema $schema): array
    {
        $tableMap = $this->tableMapResolver->resolve($statement, $schema);
        $query = $this->sqlParser->parse($statement->sql);
        $limit = $query instanceof SelectQuery && $query->limit instanceof SelectPlaceholder ? $query->limit : null;
        $resolvedByName = [];

        foreach ($this->extractParameterMappings($query, $statement->name) as $mapping) {
            $resolvedColumn = $tableMap->resolveColumn($mapping->qualifier, $mapping->column);
            if ($mapping->parameterName === $limit?->name && !in_array($resolvedColumn->column->sqlType, ['INTEGER', 'BIGINT', 'BIGSERIAL', 'SERIAL'], true)) {
                throw new \RuntimeException("LIMIT parameter {$mapping->parameterName} must be compatible with an integer column in query {$statement->name}");
            }

            $resolvedByName[$mapping->parameterName] = new ResolvedSqlParameter(
                name: $mapping->parameterName,
                propertyName: $this->snakeToCamel($mapping->parameterName),
                sqlType: $resolvedColumn->column->sqlType,
                phpType: $resolvedColumn->column->phpType,
                nullable: $resolvedColumn->column->nullable,
            );
        }

        if ($limit !== null) {
            $this->assertLimitIsNotUsedInProjections($query, $limit, $statement->name);
            // NULL means unbounded in PostgreSQL; a LIMIT parameter must always be a non-null integer.
            $resolvedByName[$limit->name] = new ResolvedSqlParameter(
                name: $limit->name,
                propertyName: $this->snakeToCamel($limit->name),
                sqlType: 'BIGINT',
                phpType: PhpTypeFactory::fromNativeType('int'),
                nullable: false,
            );
        }

        $parameters = [];

        foreach ($statement->parameters as $parameter) {
            $resolved = $resolvedByName[$parameter->name] ?? null;
            if ($resolved === null) {
                throw new \RuntimeException(
                    "Unable to resolve SQL parameter type for {$parameter->name} in query {$statement->name}",
                );
            }

            $parameters[] = $resolved;
        }

        return $parameters;
    }

    private function assertLimitIsNotUsedInProjections(SelectQuery $query, SelectPlaceholder $limit, string $statementName): void
    {
        foreach ($query->projections as $projection) {
            $operands = [];
            if ($projection instanceof SelectProjectionFunction) {
                $operands = $projection->function->arguments;
            } elseif ($projection instanceof SelectProjectionCase) {
                $operands[] = $projection->expression->elseResult;
                foreach ($projection->expression->whenClauses as $when) {
                    array_push($operands, $when->condition->left, $when->condition->right, $when->result);
                }
            }

            foreach ($operands as $operand) {
                if ($operand instanceof SelectPlaceholder && $operand->name === $limit->name) {
                    // Native PostgreSQL prepare may infer a projection type before seeing LIMIT.
                    throw new \RuntimeException("LIMIT parameter {$limit->name} cannot be reused in projection expressions in query {$statementName}");
                }
            }
        }
    }

    /**
     * @return list<ResolvedParameterMapping>
     */
    private function extractParameterMappings(SqlQuery $query, string $statementName): array
    {
        if ($query instanceof SelectQuery) {
            $comparisons = [];

            foreach ($query->joins as $join) {
                $comparison = $this->comparisonToParameterMapping($join->condition);
                if ($comparison !== null) {
                    $comparisons[] = $comparison;
                }
            }

            foreach ($query->where as $comparisonNode) {
                $comparison = $this->comparisonToParameterMapping($comparisonNode);
                if ($comparison !== null) {
                    $comparisons[] = $comparison;
                }
            }

            return $comparisons;
        }

        if ($query instanceof InsertQuery) {
            $mappings = [];

            foreach ($query->values as $valueMapping) {
                $mappings[] = new ResolvedParameterMapping(
                    qualifier: strtolower($query->table),
                    column: $valueMapping->column,
                    parameterName: $valueMapping->placeholder->name,
                );
            }

            return $mappings;
        }

        if (!$query instanceof DeleteQuery && !$query instanceof UpdateQuery) {
            throw new \RuntimeException("Unsupported SQL query type for parameter resolution in {$statementName}");
        }

        $comparisons = [];

        if ($query instanceof UpdateQuery) {
            foreach ($query->assignments as $assignment) {
                $comparisons[] = new ResolvedParameterMapping(
                    qualifier: strtolower($query->table),
                    column: $assignment->column,
                    parameterName: $assignment->placeholder->name,
                );
            }
        }

        foreach ($query->where as $comparisonNode) {
            $comparison = $this->comparisonToParameterMapping($comparisonNode, strtolower($query->table));
            if ($comparison !== null) {
                $comparisons[] = $comparison;
            }
        }

        return $comparisons;
    }

    /**
     * @return ResolvedParameterMapping|null
     */
    private function comparisonToParameterMapping(SelectComparison $comparison, ?string $defaultQualifier = null): ?ResolvedParameterMapping
    {
        if ($comparison->left instanceof SelectColumnReference && $comparison->right instanceof SelectPlaceholder) {
            return new ResolvedParameterMapping(
                qualifier: $comparison->left->table !== null ? strtolower($comparison->left->table) : $defaultQualifier,
                column: $comparison->left->column,
                parameterName: $comparison->right->name,
            );
        }

        if ($comparison->left instanceof SelectPlaceholder && $comparison->right instanceof SelectColumnReference) {
            return new ResolvedParameterMapping(
                qualifier: $comparison->right->table !== null ? strtolower($comparison->right->table) : $defaultQualifier,
                column: $comparison->right->column,
                parameterName: $comparison->left->name,
            );
        }

        return null;
    }

    private function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }
}
