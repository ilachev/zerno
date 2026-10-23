<?php

declare(strict_types=1);

namespace App\Platform\Storage;

final class TransactionRollbackException extends \RuntimeException
{
    public function __construct(
        \Throwable $operationFailure,
        public readonly \Throwable $rollbackFailure,
    ) {
        parent::__construct('Transaction rollback failed; connection state is unknown: ' . $rollbackFailure->getMessage(), 0, $operationFailure);
    }
}
