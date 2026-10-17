<?php

declare(strict_types=1);

namespace Tests\Unit\Capabilities\Session\Domain;

use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Capabilities\Session\Domain\SessionService;
use App\Platform\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Logging\TestLogger;

final class SessionServiceTest extends TestCase
{
    private TestSessionRepository $repository;

    private SessionService $service;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->repository = new TestSessionRepository();
        $this->logger = new TestLogger();
        $this->service = new SessionService($this->repository, $this->logger);
    }

    public function testCreateSessionGeneratesNewSession(): void
    {
        $session = $this->service->createSession(1, '{"test":true}', 3600);

        self::assertSame(1, $session->userId);
        self::assertSame('{"test":true}', $session->payload);
        self::assertGreaterThan(time(), $session->expiresAt);
        self::assertLessThanOrEqual(time(), $session->createdAt);
        self::assertLessThanOrEqual(time(), $session->updatedAt);

        // Проверяем, что сессия сохранена в репозитории
        self::assertCount(1, $this->repository->sessions);
        self::assertSame($session, $this->repository->sessions[$session->id]);
    }

    public function testCreateAnonymousSessionWithNullUserId(): void
    {
        $session = $this->service->createSession(null, '{"anonymous":true}', 3600);

        self::assertNull($session->userId);
        self::assertSame('{"anonymous":true}', $session->payload);

        // Проверяем, что сессия сохранена в репозитории
        self::assertCount(1, $this->repository->sessions);
        self::assertSame($session, $this->repository->sessions[$session->id]);
    }

    public function testValidateSessionReturnsNullForNonExistentSession(): void
    {
        $result = $this->service->validateSession('non-existent-id');

        self::assertNull($result);
    }

    public function testValidateSessionReturnsNullForExpiredSession(): void
    {
        $expiredSession = new Session(
            id: 'expired-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() - 100, // в прошлом
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );

        $this->repository->sessions['expired-session-id'] = $expiredSession;

        $result = $this->service->validateSession('expired-session-id');

        self::assertNull($result);
    }

    public function testValidateSessionReturnsValidSession(): void
    {
        $validSession = new Session(
            id: 'valid-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() + 100, // в будущем
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );

        $this->repository->sessions['valid-session-id'] = $validSession;

        $result = $this->service->validateSession('valid-session-id');

        self::assertSame($validSession, $result);
    }

    public function testRefreshSessionReturnsNullForNonExistentSession(): void
    {
        $result = $this->service->refreshSession('non-existent-id');

        self::assertNull($result);
    }

    public function testRefreshSessionReturnsNullForExpiredSession(): void
    {
        $expiredSession = new Session(
            id: 'expired-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() - 100, // в прошлом
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );

        $this->repository->sessions['expired-session-id'] = $expiredSession;

        $result = $this->service->refreshSession('expired-session-id');

        self::assertNull($result);
    }

    public function testRefreshSessionUpdatesAndReturnsValidSession(): void
    {
        $now = time();
        $validSession = new Session(
            id: 'valid-session-id',
            userId: 1,
            payload: '{"test":true}',
            expiresAt: $now + 100, // в будущем
            createdAt: $now - 200,
            updatedAt: $now - 100,
        );

        $this->repository->sessions['valid-session-id'] = $validSession;

        $result = $this->service->refreshSession('valid-session-id');

        self::assertNotNull($result);
        self::assertSame('valid-session-id', $result->id);
        self::assertSame(1, $result->userId);
        self::assertSame('{"test":true}', $result->payload);
        self::assertGreaterThan($validSession->expiresAt, $result->expiresAt);
        self::assertSame($validSession->createdAt, $result->createdAt);
        self::assertGreaterThan($validSession->updatedAt, $result->updatedAt);
    }

    public function testDeleteSessionRemovesSessionFromRepository(): void
    {
        $session = new Session(
            id: 'session-to-delete',
            userId: 1,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time(),
        );

        $this->repository->sessions['session-to-delete'] = $session;

        $this->service->deleteSession('session-to-delete');

        self::assertCount(0, $this->repository->sessions);
        self::assertArrayNotHasKey('session-to-delete', $this->repository->sessions);
    }

    public function testDeleteUserSessionsFindsAndDeletesSessionsForUser(): void
    {
        $session1 = new Session(
            id: 'session-1',
            userId: 10,
            payload: '{}',
            expiresAt: time() + 100,
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );

        $session2 = new Session(
            id: 'session-2',
            userId: 10,
            payload: '{}',
            expiresAt: time() + 100,
            createdAt: time() - 150,
            updatedAt: time() - 50,
        );

        $session3 = new Session(
            id: 'session-3',
            userId: 20, // Другой пользователь
            payload: '{}',
            expiresAt: time() + 100,
            createdAt: time() - 150,
            updatedAt: time() - 50,
        );

        $this->repository->sessions['session-1'] = $session1;
        $this->repository->sessions['session-2'] = $session2;
        $this->repository->sessions['session-3'] = $session3;

        $this->service->deleteUserSessions(10);

        // Проверяем, что сессии пользователя удалены, а сессия другого пользователя осталась
        self::assertCount(1, $this->repository->sessions);
        self::assertArrayNotHasKey('session-1', $this->repository->sessions);
        self::assertArrayNotHasKey('session-2', $this->repository->sessions);
        self::assertArrayHasKey('session-3', $this->repository->sessions);
    }

    public function testCleanupExpiredSessionsRemovesExpiredSessions(): void
    {
        $validSession = new Session(
            id: 'valid-session',
            userId: 1,
            payload: '{}',
            expiresAt: time() + 100, // Действительная
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );

        $expiredSession = new Session(
            id: 'expired-session',
            userId: 2,
            payload: '{}',
            expiresAt: time() - 100, // Просроченная
            createdAt: time() - 300,
            updatedAt: time() - 200,
        );

        $this->repository->sessions['valid-session'] = $validSession;
        $this->repository->sessions['expired-session'] = $expiredSession;

        $this->service->cleanupExpiredSessions();

        // Проверяем, что просроченная сессия удалена, а действительная осталась
        self::assertCount(1, $this->repository->sessions);
        self::assertArrayHasKey('valid-session', $this->repository->sessions);
        self::assertArrayNotHasKey('expired-session', $this->repository->sessions);
    }
}

/**
 * Тестовый репозиторий для тестирования SessionService.
 */
final class TestSessionRepository implements SessionRepository
{
    /** @var array<string, Session> */
    public array $sessions = [];

    public function findById(string $id): ?Session
    {
        return $this->sessions[$id] ?? null;
    }

    /**
     * @return array<Session>
     */
    public function findByUserId(int $userId): array
    {
        return array_filter(
            $this->sessions,
            static fn(Session $session) => $session->userId === $userId,
        );
    }

    public function save(Session $session): Session
    {
        $this->sessions[$session->id] = $session;

        return $session;
    }

    public function refresh(string $id, int $expiresAt, int $updatedAt): ?Session
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null || $session->expiresAt < $updatedAt) {
            return null;
        }

        return $this->sessions[$id] = new Session(
            id: $session->id,
            userId: $session->userId,
            payload: $session->payload,
            expiresAt: $expiresAt,
            createdAt: $session->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function delete(string $id): void
    {
        unset($this->sessions[$id]);
    }

    /**
     * @return array<Session>
     */
    public function findAll(): array
    {
        return array_values($this->sessions);
    }

    public function deleteExpired(): void
    {
        $now = time();

        foreach ($this->sessions as $id => $session) {
            if ($session->expiresAt < $now) {
                unset($this->sessions[$id]);
            }
        }
    }
}
