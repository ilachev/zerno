<?php

declare(strict_types=1);

namespace App\Capabilities\Session\Domain;

interface SessionRepository
{
    public function findById(string $id): ?Session;

    /**
     * @return array<Session>
     */
    public function findByUserId(int $userId): array;

    /**
     * @return array<Session>
     */
    public function findAll(): array;

    public function save(Session $session): Session;

    /**
     * Updates timestamps only while the session exists and has not expired.
     */
    public function refresh(string $id, int $expiresAt, int $updatedAt): ?Session;

    public function delete(string $id): void;

    public function deleteExpired(): void;
}
