<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

final class DocumentId
{
    public static function parse(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!\is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (\strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
