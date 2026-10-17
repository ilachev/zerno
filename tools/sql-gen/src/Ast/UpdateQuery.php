<?php

declare(strict_types=1);

namespace SqlGen\Ast;

final readonly class UpdateQuery implements SqlQuery
{
    /**
     * @param list<UpdateAssignment> $assignments
     * @param list<SelectComparison> $where
     * @param list<string> $whereOperators
     * @param list<SelectProjection> $returning
     */
    public function __construct(
        public string $table,
        public array $assignments,
        public array $where,
        public array $whereOperators,
        public array $returning,
    ) {}
}
