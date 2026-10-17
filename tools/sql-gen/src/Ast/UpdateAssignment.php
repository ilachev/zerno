<?php

declare(strict_types=1);

namespace SqlGen\Ast;

final readonly class UpdateAssignment
{
    public function __construct(
        public string $column,
        public SelectPlaceholder $placeholder,
    ) {}
}
