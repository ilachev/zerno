<?php

declare(strict_types=1);

namespace SqlGen\Parser;

use Phplrt\Compiler\Compiler;
use Phplrt\Compiler\Runtime\PrintableNode;
use Phplrt\Contracts\Lexer\TokenInterface;
use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertConflictAssignment;
use SqlGen\Ast\InsertConflictClause;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\InsertValueMapping;
use SqlGen\Ast\SelectCaseExpression;
use SqlGen\Ast\SelectCaseWhen;
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

final class PhplrtSqlParser implements SqlQueryParser
{
    private readonly Compiler $compiler;

    public function __construct()
    {
        $grammar = file_get_contents(__DIR__ . '/grammar/sql_subset.pp2');
        if (!is_string($grammar)) {
            throw new \RuntimeException('Unable to load phplrt SQL subset grammar.');
        }

        $this->compiler = new Compiler();
        $this->compiler->load($grammar);
    }

    public function parse(string $sql): SqlQuery
    {
        /** @var PrintableNode $parsed */
        $parsed = $this->compiler->parse($sql);

        $select = $this->findFirstChildNode($parsed, 'SelectStmt');
        if ($select instanceof PrintableNode) {
            return $this->normalizeSelectQuery($select);
        }

        $insert = $this->findFirstChildNode($parsed, 'InsertStmt');
        if ($insert instanceof PrintableNode) {
            return $this->normalizeInsertQuery($insert);
        }

        $update = $this->findFirstChildNode($parsed, 'UpdateStmt');
        if ($update instanceof PrintableNode) {
            return $this->normalizeUpdateQuery($update);
        }

        $delete = $this->findFirstChildNode($parsed, 'DeleteStmt');
        if ($delete instanceof PrintableNode) {
            return $this->normalizeDeleteQuery($delete);
        }

        throw new \RuntimeException('Unsupported SQL statement for sql-gen subset parser.');
    }

    private function normalizeSelectQuery(PrintableNode $select): SelectQuery
    {
        $selectList = $this->findFirstChildNode($select, 'SelectList');
        $from = $this->findFirstChildNode($select, 'TableRef');

        if (!$selectList instanceof PrintableNode || !$from instanceof PrintableNode) {
            throw new \RuntimeException('Parsed SELECT query is missing SelectList or TableRef.');
        }

        $joins = [];
        $where = [];
        $whereOperators = [];
        $orderBy = [];

        foreach ($select->children as $child) {
            if (!$child instanceof PrintableNode) {
                continue;
            }

            if ($child->getState() === 'JoinClause') {
                $joins[] = $this->normalizeJoinClause($child);
                continue;
            }

            if ($child->getState() === 'WhereClause') {
                [$where, $whereOperators] = $this->normalizeWhereClause($child);
                continue;
            }

            if ($child->getState() === 'OrderByClause') {
                $orderBy = $this->normalizeOrderByClause($child);
            }
        }

        return new SelectQuery(
            projections: $this->normalizeSelectList($selectList),
            from: $this->normalizeTableRef($from),
            joins: $joins,
            where: $where,
            whereOperators: $whereOperators,
            orderBy: $orderBy,
        );
    }

    private function normalizeInsertQuery(PrintableNode $insert): InsertQuery
    {
        $tableName = $this->findDirectTokenValueAfter($insert, 'T_INTO', 'T_IDENT');
        if ($tableName === null) {
            throw new \RuntimeException('InsertStmt must contain a table name.');
        }

        $identList = $this->findFirstChildNode($insert, 'IdentList');
        $placeholderList = $this->findFirstChildNode($insert, 'PlaceholderList');

        if (!$identList instanceof PrintableNode || !$placeholderList instanceof PrintableNode) {
            throw new \RuntimeException('InsertStmt must contain IdentList and PlaceholderList.');
        }

        $columns = $this->normalizeIdentList($identList);
        $placeholders = $this->normalizePlaceholderList($placeholderList);

        if (count($columns) !== count($placeholders)) {
            throw new \RuntimeException('Insert column and placeholder counts must match.');
        }

        $values = [];
        foreach ($columns as $index => $column) {
            $values[] = new InsertValueMapping(
                column: $column,
                placeholder: $placeholders[$index],
            );
        }

        $conflictNode = $this->findFirstChildNode($insert, 'ConflictClause');
        $returningNode = $this->findFirstChildNode($insert, 'ReturningClause');

        return new InsertQuery(
            table: $tableName,
            values: $values,
            conflict: $conflictNode instanceof PrintableNode ? $this->normalizeConflictClause($conflictNode) : null,
            returning: $returningNode instanceof PrintableNode ? $this->normalizeReturningClause($returningNode) : [],
        );
    }

    private function normalizeUpdateQuery(PrintableNode $update): UpdateQuery
    {
        $tableName = $this->findDirectTokenValueAfter($update, 'T_UPDATE', 'T_IDENT');
        $assignmentsNode = $this->findFirstChildNode($update, 'UpdateAssignments');
        if ($tableName === null || !$assignmentsNode instanceof PrintableNode) {
            throw new \RuntimeException('UpdateStmt must contain a table name and assignments.');
        }

        $assignments = [];
        foreach ($assignmentsNode->children as $child) {
            if (!$child instanceof PrintableNode || $child->getState() !== 'UpdateAssignment') {
                continue;
            }

            $placeholder = $this->findFirstChildNode($child, 'Placeholder');
            if (!$placeholder instanceof PrintableNode) {
                throw new \RuntimeException('UpdateAssignment must contain a placeholder.');
            }

            $assignments[] = new UpdateAssignment(
                column: $this->firstTokenValue($child),
                placeholder: $this->normalizePlaceholder($placeholder),
            );
        }

        $whereNode = $this->findFirstChildNode($update, 'WhereClause');
        [$where, $operators] = $whereNode instanceof PrintableNode ? $this->normalizeWhereClause($whereNode) : [[], []];
        $returningNode = $this->findFirstChildNode($update, 'ReturningClause');

        return new UpdateQuery(
            table: $tableName,
            assignments: $assignments,
            where: $where,
            whereOperators: $operators,
            returning: $returningNode instanceof PrintableNode ? $this->normalizeReturningClause($returningNode) : [],
        );
    }

    private function normalizeDeleteQuery(PrintableNode $delete): DeleteQuery
    {
        $tableName = $this->findDirectTokenValueAfter($delete, 'T_FROM', 'T_IDENT');
        $whereNode = $this->findFirstChildNode($delete, 'WhereClause');

        if ($tableName === null || !$whereNode instanceof PrintableNode) {
            throw new \RuntimeException('DeleteStmt must contain table name and WhereClause.');
        }

        [$where, $operators] = $this->normalizeWhereClause($whereNode);

        return new DeleteQuery(
            table: $tableName,
            where: $where,
            whereOperators: $operators,
        );
    }

    /**
     * @return list<SelectProjection>
     */
    private function normalizeSelectList(PrintableNode $selectList): array
    {
        $items = [];

        foreach ($selectList->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'SelectItem') {
                $items[] = $this->normalizeSelectItem($child);
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function normalizeIdentList(PrintableNode $identList): array
    {
        $identifiers = [];

        foreach ($identList->children as $child) {
            if ($child instanceof TokenInterface && $child->getName() === 'T_IDENT') {
                $identifiers[] = $child->getValue();
            }
        }

        return $identifiers;
    }

    /**
     * @return list<SelectPlaceholder>
     */
    private function normalizePlaceholderList(PrintableNode $placeholderList): array
    {
        $placeholders = [];

        foreach ($placeholderList->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'Placeholder') {
                $placeholders[] = $this->normalizePlaceholder($child);
            }
        }

        return $placeholders;
    }

    /**
     * @return list<SelectProjection>
     */
    private function normalizeReturningClause(PrintableNode $returning): array
    {
        $selectList = $this->findFirstChildNode($returning, 'SelectList');
        if (!$selectList instanceof PrintableNode) {
            throw new \RuntimeException('ReturningClause must contain SelectList.');
        }

        return $this->normalizeSelectList($selectList);
    }

    private function normalizeConflictClause(PrintableNode $conflict): InsertConflictClause
    {
        $identList = $this->findFirstChildNode($conflict, 'IdentList');
        $assignmentsNode = $this->findFirstChildNode($conflict, 'ConflictAssignments');

        if (!$identList instanceof PrintableNode || !$assignmentsNode instanceof PrintableNode) {
            throw new \RuntimeException('ConflictClause must contain target columns and assignments.');
        }

        $assignments = [];

        foreach ($assignmentsNode->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'ConflictAssignment') {
                $assignments[] = $this->normalizeConflictAssignment($child);
            }
        }

        return new InsertConflictClause(
            targetColumns: $this->normalizeIdentList($identList),
            assignments: $assignments,
        );
    }

    private function normalizeConflictAssignment(PrintableNode $assignment): InsertConflictAssignment
    {
        $column = null;
        $excludedColumn = null;

        foreach ($assignment->children as $child) {
            if ($child instanceof TokenInterface && $child->getName() === 'T_IDENT') {
                $column ??= $child->getValue();
                continue;
            }

            if ($child instanceof PrintableNode && $child->getState() === 'ExcludedRef') {
                $excludedColumn = (new ExcludedReferenceTokens($this->tokens($child)))->column();
            }
        }

        if (!is_string($column) || !is_string($excludedColumn)) {
            throw new \RuntimeException('ConflictAssignment must contain target and excluded columns.');
        }

        return new InsertConflictAssignment(
            column: $column,
            excludedColumn: $excludedColumn,
        );
    }

    private function normalizeSelectItem(PrintableNode $item): SelectProjection
    {
        $wildcard = $this->findFirstChildNode($item, 'WildcardSelection');
        if ($wildcard instanceof PrintableNode) {
            return $this->normalizeWildcardSelection($wildcard);
        }

        $case = $this->findFirstChildNode($item, 'AliasedCase');
        if ($case instanceof PrintableNode) {
            return $this->normalizeCaseProjection($case);
        }

        $function = $this->findFirstChildNode($item, 'AliasedFunction');
        if ($function instanceof PrintableNode) {
            return $this->normalizeFunctionProjection($function);
        }

        $column = $this->findFirstChildNode($item, 'AliasedColumn');
        if (!$column instanceof PrintableNode) {
            throw new \RuntimeException('SelectItem must contain AliasedColumn, AliasedCase, AliasedFunction or WildcardSelection.');
        }

        $columnRef = $this->findFirstChildNode($column, 'ColumnRef');
        if (!$columnRef instanceof PrintableNode) {
            throw new \RuntimeException('AliasedColumn must contain ColumnRef.');
        }

        return new SelectProjectionColumn(
            reference: $this->normalizeColumnRef($columnRef),
            alias: (new SelectAliasTokens($this->tokens($column)))->toAlias(),
        );
    }

    private function normalizeCaseProjection(PrintableNode $case): SelectProjectionCase
    {
        $caseExpr = $this->findFirstChildNode($case, 'CaseExpr');
        if (!$caseExpr instanceof PrintableNode) {
            throw new \RuntimeException('AliasedCase must contain CaseExpr.');
        }

        return new SelectProjectionCase(
            expression: $this->normalizeCaseExpression($caseExpr),
            alias: (new SelectAliasTokens($this->tokens($case)))->toAlias(),
        );
    }

    private function normalizeCaseExpression(PrintableNode $caseExpr): SelectCaseExpression
    {
        $whenClauses = [];
        $elseClause = $this->findFirstChildNode($caseExpr, 'ElseClause');

        foreach ($caseExpr->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'WhenClause') {
                $whenClauses[] = $this->normalizeCaseWhen($child);
            }
        }

        if ($whenClauses === [] || !$elseClause instanceof PrintableNode) {
            throw new \RuntimeException('CaseExpr must contain at least one WhenClause and one ElseClause.');
        }

        return new SelectCaseExpression(
            whenClauses: $whenClauses,
            elseResult: $this->normalizeCaseElse($elseClause),
        );
    }

    private function normalizeCaseWhen(PrintableNode $whenClause): SelectCaseWhen
    {
        $comparison = $this->findFirstChildNode($whenClause, 'ComparisonExpr');
        $operand = $this->findFirstChildNode($whenClause, 'Operand');

        if (!$comparison instanceof PrintableNode || !$operand instanceof PrintableNode) {
            throw new \RuntimeException('WhenClause must contain ComparisonExpr and Operand.');
        }

        return new SelectCaseWhen(
            condition: $this->normalizeComparison($comparison),
            result: $this->normalizeOperand($operand),
        );
    }

    private function normalizeCaseElse(PrintableNode $elseClause): SelectOperand
    {
        $operand = $this->findFirstChildNode($elseClause, 'Operand');
        if (!$operand instanceof PrintableNode) {
            throw new \RuntimeException('ElseClause must contain Operand.');
        }

        return $this->normalizeOperand($operand);
    }

    private function normalizeFunctionProjection(PrintableNode $function): SelectProjectionFunction
    {
        $functionCall = $this->findFirstChildNode($function, 'FunctionCall');
        if (!$functionCall instanceof PrintableNode) {
            throw new \RuntimeException('AliasedFunction must contain FunctionCall.');
        }

        return new SelectProjectionFunction(
            function: $this->normalizeFunctionCall($functionCall),
            alias: (new SelectAliasTokens($this->tokens($function)))->toAlias(),
        );
    }

    private function normalizeFunctionCall(PrintableNode $functionCall): SelectFunctionCall
    {
        $functionName = $this->firstTokenValue($functionCall);
        $argumentList = $this->findFirstChildNode($functionCall, 'OperandList');

        if ($argumentList instanceof PrintableNode) {
            $arguments = [];

            foreach ($argumentList->children as $child) {
                if ($child instanceof PrintableNode && $child->getState() === 'Operand') {
                    $arguments[] = $this->normalizeOperand($child);
                }
            }

            return new SelectFunctionCall(
                name: strtolower($functionName),
                arguments: $arguments,
                wildcard: false,
            );
        }

        foreach ($functionCall->children as $child) {
            if ($child instanceof TokenInterface && $child->getName() === 'T_STAR') {
                return new SelectFunctionCall(
                    name: strtolower($functionName),
                    arguments: [],
                    wildcard: true,
                );
            }
        }

        throw new \RuntimeException('FunctionCall must contain OperandList or wildcard argument.');
    }

    private function normalizeWildcardSelection(PrintableNode $wildcard): SelectProjectionWildcard
    {
        return (new WildcardSelectionTokens($this->tokens($wildcard)))->toProjectionWildcard();
    }

    private function normalizeTableRef(PrintableNode $tableRef): SelectTableReference
    {
        return (new TableReferenceTokens($this->tokens($tableRef)))->toTableReference();
    }

    private function normalizeJoinClause(PrintableNode $join): SelectJoin
    {
        $joinTypeNode = $this->findFirstChildNode($join, 'JoinType');
        $tableNode = $this->findNthChildNode($join, 'TableRef', 0);
        $comparisonNode = $this->findFirstChildNode($join, 'ComparisonExpr');

        if (!$tableNode instanceof PrintableNode || !$comparisonNode instanceof PrintableNode) {
            throw new \RuntimeException('JoinClause must contain TableRef and ComparisonExpr.');
        }

        return new SelectJoin(
            type: $joinTypeNode instanceof PrintableNode ? strtolower($this->firstTokenValue($joinTypeNode)) : null,
            table: $this->normalizeTableRef($tableNode),
            condition: $this->normalizeComparison($comparisonNode),
        );
    }

    /**
     * @return array{0: list<SelectComparison>, 1: list<string>}
     */
    private function normalizeWhereClause(PrintableNode $where): array
    {
        $comparisons = [];
        $operators = [];

        foreach ($where->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'ComparisonExpr') {
                $comparisons[] = $this->normalizeComparison($child);
                continue;
            }

            if ($child instanceof TokenInterface && in_array($child->getName(), ['T_AND', 'T_OR'], true)) {
                $operators[] = strtolower($child->getValue());
            }
        }

        return [$comparisons, $operators];
    }

    private function normalizeComparison(PrintableNode $comparison): SelectComparison
    {
        $operands = [];
        $operator = null;

        foreach ($comparison->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'Operand') {
                $operands[] = $this->normalizeOperand($child);
                continue;
            }

            if ($child instanceof PrintableNode && $child->getState() === 'CompareOp') {
                $operator = $this->firstTokenValue($child);
            }
        }

        if (count($operands) !== 2 || !is_string($operator)) {
            throw new \RuntimeException('ComparisonExpr must contain two operands and one operator.');
        }

        return new SelectComparison(
            left: $operands[0],
            operator: $operator,
            right: $operands[1],
        );
    }

    /**
     * @return list<SelectOrderByItem>
     */
    private function normalizeOrderByClause(PrintableNode $orderBy): array
    {
        $items = [];

        foreach ($orderBy->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === 'OrderItem') {
                $items[] = $this->normalizeOrderItem($child);
            }
        }

        return $items;
    }

    private function normalizeOrderItem(PrintableNode $orderItem): SelectOrderByItem
    {
        $columnRef = $this->findFirstChildNode($orderItem, 'ColumnRef');
        if (!$columnRef instanceof PrintableNode) {
            throw new \RuntimeException('OrderItem must contain ColumnRef.');
        }

        $direction = null;

        foreach ($orderItem->children as $child) {
            if (!$child instanceof TokenInterface) {
                continue;
            }

            if (in_array($child->getName(), ['T_ASC', 'T_DESC'], true)) {
                $direction = strtolower($child->getValue());
            }
        }

        return new SelectOrderByItem(
            column: $this->normalizeColumnRef($columnRef),
            direction: $direction,
        );
    }

    private function normalizeOperand(PrintableNode $operand): SelectOperand
    {
        $column = $this->findFirstChildNode($operand, 'ColumnRef');
        if ($column instanceof PrintableNode) {
            return $this->normalizeColumnRef($column);
        }

        $placeholder = $this->findFirstChildNode($operand, 'Placeholder');
        if (!$placeholder instanceof PrintableNode) {
            throw new \RuntimeException('Operand must contain ColumnRef or Placeholder.');
        }

        return $this->normalizePlaceholder($placeholder);
    }

    private function normalizePlaceholder(PrintableNode $placeholder): SelectPlaceholder
    {
        return (new PlaceholderTokens($this->tokens($placeholder)))->toPlaceholder();
    }

    private function normalizeColumnRef(PrintableNode $columnRef): SelectColumnReference
    {
        return (new ColumnReferenceTokens($this->tokens($columnRef)))->toColumnReference();
    }

    private function firstTokenValue(PrintableNode $node): string
    {
        $tokens = new TokenValueSequence($this->tokens($node));
        if ($tokens->isEmpty()) {
            throw new \RuntimeException('Node does not contain direct tokens.');
        }

        return $tokens->first();
    }

    /**
     * @return list<string>
     */
    private function tokens(PrintableNode $node): array
    {
        $tokens = [];

        foreach ($node->children as $child) {
            if ($child instanceof TokenInterface) {
                $tokens[] = $child->getValue();
            }
        }

        return $tokens;
    }

    private function findDirectTokenValueAfter(PrintableNode $node, string $afterTokenName, string $targetTokenName): ?string
    {
        $seenAfter = false;

        foreach ($node->children as $child) {
            if (!$child instanceof TokenInterface) {
                continue;
            }

            if ($child->getName() === $afterTokenName) {
                $seenAfter = true;
                continue;
            }

            if ($seenAfter && $child->getName() === $targetTokenName) {
                return $child->getValue();
            }
        }

        return null;
    }

    private function findFirstChildNode(PrintableNode $node, string $state): ?PrintableNode
    {
        foreach ($node->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === $state) {
                return $child;
            }
        }

        return null;
    }

    private function findNthChildNode(PrintableNode $node, string $state, int $index): ?PrintableNode
    {
        $matches = [];

        foreach ($node->children as $child) {
            if ($child instanceof PrintableNode && $child->getState() === $state) {
                $matches[] = $child;
            }
        }

        return $matches[$index] ?? null;
    }
}
