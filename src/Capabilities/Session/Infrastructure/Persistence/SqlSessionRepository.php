<?php

declare(strict_types=1);

namespace App\Capabilities\Session\Infrastructure\Persistence;

use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Generated\Sql\Session\DeleteExpiredSessionsQuery;
use App\Generated\Sql\Session\DeleteSessionByIdQuery;
use App\Generated\Sql\Session\FindAllSessionsQuery;
use App\Generated\Sql\Session\FindSessionByIdQuery;
use App\Generated\Sql\Session\FindSessionsByUserIdQuery;
use App\Generated\Sql\Session\RefreshSessionQuery;
use App\Generated\Sql\Session\SessionRow;
use App\Generated\Sql\Session\UpsertSessionQuery;
use App\Platform\Storage\Sql\SqlExecutor;

final readonly class SqlSessionRepository implements SessionRepository
{
    public function __construct(
        private SqlExecutor $sqlExecutor,
    ) {}

    public function findById(string $id): ?Session
    {
        $row = $this->sqlExecutor->fetchOneRow(
            FindSessionByIdQuery::create(id: $id),
        );

        if ($row === null) {
            return null;
        }

        return $this->createSessionFromRow($row);
    }

    public function findByUserId(int $userId): array
    {
        $rows = $this->sqlExecutor->fetchAllRows(
            FindSessionsByUserIdQuery::create(userId: $userId),
        );

        return array_map(
            fn(SessionRow $row): Session => $this->createSessionFromRow($row),
            $rows,
        );
    }

    public function findAll(): array
    {
        $rows = $this->sqlExecutor->fetchAllRows(
            FindAllSessionsQuery::create(),
        );

        return array_map(
            fn(SessionRow $row): Session => $this->createSessionFromRow($row),
            $rows,
        );
    }

    public function save(Session $session): Session
    {
        $row = $this->sqlExecutor->fetchOneRow(
            UpsertSessionQuery::create(
                id: $session->id,
                userId: $session->userId,
                payload: $session->payload,
                expiresAt: $session->expiresAt,
                createdAt: $session->createdAt,
                updatedAt: $session->updatedAt,
            ),
        );

        if ($row === null) {
            throw new \LogicException('UpsertSessionQuery must return the persisted session row.');
        }

        return $this->createSessionFromRow($row);
    }

    public function delete(string $id): void
    {
        $this->sqlExecutor->execute(
            DeleteSessionByIdQuery::create(id: $id),
        );
    }

    public function refresh(string $id, int $expiresAt, int $updatedAt): ?Session
    {
        $row = $this->sqlExecutor->fetchOneRow(
            RefreshSessionQuery::create(
                id: $id,
                expiresAt: $expiresAt,
                updatedAt: $updatedAt,
                now: $updatedAt,
            ),
        );

        return $row === null ? null : $this->createSessionFromRow($row);
    }

    public function deleteExpired(): void
    {
        $this->sqlExecutor->execute(
            DeleteExpiredSessionsQuery::create(now: time()),
        );
    }

    private function createSessionFromRow(SessionRow $row): Session
    {
        return new Session(
            id: $row->id,
            userId: $row->userId,
            payload: $row->payload,
            expiresAt: $row->expiresAt,
            createdAt: $row->createdAt,
            updatedAt: $row->updatedAt,
        );
    }
}
