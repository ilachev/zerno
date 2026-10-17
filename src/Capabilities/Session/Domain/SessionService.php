<?php

declare(strict_types=1);

namespace App\Capabilities\Session\Domain;

use App\Platform\Logging\Logger;

final readonly class SessionService
{
    public function __construct(
        private SessionRepository $repository,
        private Logger $logger,
    ) {}

    public function createSession(?int $userId, string $payload, int $ttl = 3600): Session
    {
        $now = time();
        $id = $this->generateSessionId();

        $expireTime = $now + $ttl;

        if ($expireTime < 0 || $ttl === PHP_INT_MAX) {
            $expireTime = PHP_INT_MAX;
        }

        $session = new Session(
            id: $id,
            userId: $userId,
            payload: $payload,
            expiresAt: $expireTime,
            createdAt: $now,
            updatedAt: $now,
        );

        return $this->repository->save($session);
    }

    public function validateSession(string $sessionId): ?Session
    {
        $session = $this->repository->findById($sessionId);

        if ($session === null || $session->isExpired()) {
            return null;
        }

        return $session;
    }

    public function refreshSession(string $sessionId, int $ttl = 3600, bool $onlyIfNeeded = true): ?Session
    {
        $session = $this->repository->findById($sessionId);

        if ($session === null || $session->isExpired()) {
            return null;
        }

        $now = time();

        if ($onlyIfNeeded) {
            $remainingTime = $session->expiresAt - $now;
            $halfTtl = $ttl / 2;

            if ($remainingTime > $halfTtl) {
                $this->logger->debug('Session not refreshed (not needed)', [
                    'session_id' => $sessionId,
                    'remaining_seconds' => $remainingTime,
                    'ttl_threshold' => $halfTtl,
                ]);

                return $session;
            }

            $this->logger->debug('Session needs refresh', [
                'session_id' => $sessionId,
                'remaining_seconds' => $remainingTime,
                'ttl_threshold' => $halfTtl,
            ]);
        }

        $expireTime = $now + $ttl;

        if ($expireTime < 0 || $ttl === PHP_INT_MAX) {
            $expireTime = PHP_INT_MAX;
        }

        return $this->repository->refresh($sessionId, $expireTime, $now);
    }

    public function deleteSession(string $sessionId): void
    {
        $this->repository->delete($sessionId);
    }

    public function deleteUserSessions(int $userId): void
    {
        $sessions = $this->repository->findByUserId($userId);

        foreach ($sessions as $session) {
            $this->repository->delete($session->id);
        }
    }

    public function cleanupExpiredSessions(): void
    {
        $this->repository->deleteExpired();
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
