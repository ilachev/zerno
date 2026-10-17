<?php

declare(strict_types=1);

namespace Tests\Integration\Session;

use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Capabilities\Session\Domain\SessionService;
use App\Capabilities\Session\Infrastructure\Persistence\SqlSessionRepository;
use App\Platform\Logging\Logger;
use App\Platform\Storage\Sql\SqlExecutor;
use App\Platform\Storage\StorageFactory;
use Tests\Integration\IntegrationTestCase;

final class SessionRevocationTest extends IntegrationTestCase
{
    private SqlSessionRepository $firstRepository;

    private SqlSessionRepository $secondRepository;

    private SessionService $firstWorker;

    private SessionService $secondWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->container->get(StorageFactory::class);
        $logger = $this->container->get(Logger::class);
        $this->firstRepository = new SqlSessionRepository(new SqlExecutor($factory->createStorage()));
        $this->secondRepository = new SqlSessionRepository(new SqlExecutor($factory->createStorage()));
        $this->firstWorker = new SessionService($this->firstRepository, $logger);
        $this->secondWorker = new SessionService($this->secondRepository, $logger);
    }

    public function testRuntimeUsesAuthoritativeSessionRepository(): void
    {
        self::assertInstanceOf(SqlSessionRepository::class, $this->container->get(SessionRepository::class));
    }

    public function testRevocationIsVisibleToAnotherWorkerAfterARead(): void
    {
        $session = $this->firstWorker->createSession(77, '{}', 3600);
        self::assertNotNull($this->secondWorker->validateSession($session->id));

        $this->firstWorker->deleteSession($session->id);

        self::assertNull($this->secondWorker->validateSession($session->id));
        self::assertNull($this->secondWorker->refreshSession($session->id));
        self::assertNull($this->firstRepository->findById($session->id));
    }

    public function testRefreshCannotReinsertSessionDeletedAfterRead(): void
    {
        $session = $this->firstWorker->createSession(77, '{}', 3600);
        $readBeforeRevocation = $this->secondRepository->findById($session->id);
        self::assertNotNull($readBeforeRevocation);

        $this->firstRepository->delete($session->id);

        self::assertNull($this->secondRepository->refresh($readBeforeRevocation->id, time() + 7200, time()));
        self::assertNull($this->firstRepository->findById($session->id));
    }

    public function testRefreshPreservesConcurrentChangesToUserAndPayload(): void
    {
        $session = $this->firstWorker->createSession(77, '{"version":1}', 3600);
        $readBeforeUpdate = $this->secondRepository->findById($session->id);
        self::assertNotNull($readBeforeUpdate);

        $this->firstRepository->save(new Session(
            id: $session->id,
            userId: 88,
            payload: '{"version":2}',
            expiresAt: $session->expiresAt,
            createdAt: $session->createdAt,
            updatedAt: time(),
        ));

        $now = time();
        $refreshed = $this->secondRepository->refresh($readBeforeUpdate->id, $now + 7200, $now);

        self::assertNotNull($refreshed);
        self::assertSame(88, $refreshed->userId);
        self::assertJsonStringEqualsJsonString('{"version":2}', $refreshed->payload);
        self::assertSame($session->createdAt, $refreshed->createdAt);
        self::assertSame($now + 7200, $refreshed->expiresAt);
        self::assertSame($now, $refreshed->updatedAt);
    }

    public function testRefreshDoesNotExtendExpiredSession(): void
    {
        $session = $this->firstWorker->createSession(77, '{}', 3600);
        $expiredSession = new Session(
            id: $session->id,
            userId: $session->userId,
            payload: $session->payload,
            expiresAt: time() - 1,
            createdAt: $session->createdAt,
            updatedAt: $session->updatedAt,
        );
        $this->firstRepository->save($expiredSession);

        self::assertNull($this->secondRepository->refresh($session->id, time() + 7200, time()));
        self::assertSame($expiredSession->expiresAt, $this->firstRepository->findById($session->id)?->expiresAt);
    }

    public function testRefreshMissingSessionDoesNotInsertIt(): void
    {
        $id = bin2hex(random_bytes(16));

        self::assertNull($this->secondRepository->refresh($id, time() + 3600, time()));
        self::assertNull($this->firstRepository->findById($id));
    }

    public function testServiceRefreshUpdatesExistingSession(): void
    {
        $session = $this->firstWorker->createSession(77, '{}', 120);

        $refreshed = $this->secondWorker->refreshSession($session->id, 3600);

        self::assertNotNull($refreshed);
        self::assertGreaterThan($session->expiresAt, $refreshed->expiresAt);
        self::assertSame(77, $refreshed->userId);
        self::assertSame($refreshed->expiresAt, $this->firstRepository->findById($session->id)?->expiresAt);
    }
}
