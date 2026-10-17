<?php

declare(strict_types=1);

namespace Tests\Unit\Capabilities\Session\Application;

use App\Capabilities\Session\Application\ClientConfig;
use App\Capabilities\Session\Application\ClientDetector;
use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Capabilities\Session\Infrastructure\FingerprintClientDetector;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ClientDetectorTest extends TestCase
{
    private TestSessionRepository $repository;

    private ClientConfig $config;

    private ClientDetector $detector;

    protected function setUp(): void
    {
        $this->repository = new TestSessionRepository();
        $this->config = new ClientConfig();
        $this->detector = new FingerprintClientDetector($this->repository, $this->config);
    }

    public function testFindSimilarClientsWithNoSessions(): void
    {
        $request = new ServerRequest('GET', '/');

        $similarClients = $this->detector->findSimilarClients($request);

        self::assertEmpty($similarClients);
    }

    public function testFindSimilarClientsWithNoSession(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->repository->sessions = [
            'test-session' => $this->createSession('test-session', '192.168.1.1', 'Chrome'),
        ];

        $similarClients = $this->detector->findSimilarClients($request);

        self::assertEmpty($similarClients);
    }

    public function testFindSimilarClientsWithMatchingSession(): void
    {
        // Создаем текущую сессию
        $currentSession = $this->createSession('current-session', '192.168.1.1', 'Chrome');

        // Создаем другие сессии с разной степенью схожести
        $this->repository->sessions = [
            'current-session' => $currentSession,
            'similar-session' => $this->createSession('similar-session', '192.168.1.1', 'Chrome'),
            'different-session' => $this->createSession('different-session', '10.0.0.1', 'Firefox'),
        ];

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('session', $currentSession);

        $similarClients = $this->detector->findSimilarClients($request);

        self::assertCount(1, $similarClients);
        self::assertSame('similar-session', $similarClients[0]->id);
    }

    public function testFindSimilarClientsWithMultipleMatches(): void
    {
        // Создаем текущую сессию
        $currentSession = $this->createSession('current-session', '192.168.1.1', 'Chrome');

        // Создаем другие сессии с разной степенью схожести
        $this->repository->sessions = [
            'current-session' => $currentSession,
            'ip-match' => $this->createSession('ip-match', '192.168.1.1', 'Firefox'),
            'full-match' => $this->createSession('full-match', '192.168.1.1', 'Chrome'),
            'no-match' => $this->createSession('no-match', '10.0.0.1', 'Safari'),
        ];

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('session', $currentSession);

        $similarClients = $this->detector->findSimilarClients($request);

        self::assertCount(2, $similarClients);

        // Первым должен быть наиболее похожий клиент (полное совпадение)
        self::assertSame('full-match', $similarClients[0]->id);
        self::assertSame('ip-match', $similarClients[1]->id);
    }

    public function testIsRequestSuspiciousWithNoSession(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertFalse($this->detector->isRequestSuspicious($request));
    }

    public function testIsRequestSuspiciousWithFewSessions(): void
    {
        // Создаем текущую сессию
        $currentSession = $this->createSession('current-session', '192.168.1.1', 'Chrome');

        // Добавляем ещё несколько сессий с тем же IP (меньше порога)
        $this->repository->sessions = [
            'current-session' => $currentSession,
            'session1' => $this->createSession('session1', '192.168.1.1', 'Firefox'),
            'session2' => $this->createSession('session2', '192.168.1.1', 'Safari'),
        ];

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('session', $currentSession);

        self::assertFalse($this->detector->isRequestSuspicious($request));
    }

    public function testIsRequestSuspiciousWithManySessions(): void
    {
        // Создаем текущую сессию
        $currentSession = $this->createSession('current-session', '192.168.1.1', 'Chrome');

        // Добавляем много сессий с тем же IP (больше порога)
        $this->repository->sessions = [
            'current-session' => $currentSession,
        ];

        // Добавляем 6 сессий с тем же IP (превышает порог maxSessionsPerIp=5)
        for ($i = 1; $i <= 6; ++$i) {
            $this->repository->sessions["session{$i}"] = $this->createSession(
                "session{$i}",
                '192.168.1.1',
                'Browser ' . $i,
            );
        }

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('session', $currentSession);

        self::assertTrue($this->detector->isRequestSuspicious($request));
    }

    private function createSession(string $id, string $ip, string $userAgent): Session
    {
        $payload = json_encode([
            'ip' => $ip,
            'userAgent' => $userAgent,
            'acceptLanguage' => 'en-US',
        ]);

        return new Session(
            id: $id,
            userId: null,
            payload: (string) $payload,
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time(),
        );
    }
}

/**
 * In-memory session repository for unit tests.
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

    /**
     * @return array<Session>
     */
    public function findAll(): array
    {
        return array_values($this->sessions);
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
