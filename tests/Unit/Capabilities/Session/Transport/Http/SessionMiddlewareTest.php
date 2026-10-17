<?php

declare(strict_types=1);

namespace Tests\Unit\Capabilities\Session\Transport\Http;

use App\Capabilities\Session\Application\SessionPayloadFactory;
use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionConfig;
use App\Capabilities\Session\Domain\SessionPayload;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Capabilities\Session\Domain\SessionService;
use App\Capabilities\Session\Transport\Http\SessionMiddleware;
use App\Capabilities\Session\Transport\Http\SessionResponseHeaders;
use App\Platform\Http\RequestHandler;
use App\Platform\Hydration\JsonFieldAdapter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Unit\Platform\Logging\TestLogger;

final class SessionMiddlewareTest extends TestCase
{
    /**
     * Creates a SessionPayload object for tests.
     */
    private function createTestSessionPayload(
        ?string $userAgent = null,
        string $ip = '127.0.0.1',
        ?string $acceptLanguage = 'en-US',
        ?string $acceptEncoding = 'gzip',
        ?string $xForwardedFor = null,
    ): SessionPayload {
        return new SessionPayload(
            ip: $ip,
            userAgent: $userAgent,
            acceptLanguage: $acceptLanguage,
            acceptEncoding: $acceptEncoding,
            xForwardedFor: $xForwardedFor,
            referer: null,
            origin: null,
            secChUa: null,
            secChUaPlatform: null,
            secChUaMobile: null,
            dnt: null,
            secFetchDest: null,
            secFetchMode: null,
            secFetchSite: null,
        );
    }

    private TestSessionRepository $repository;

    private SessionService $sessionService;

    private TestLogger $logger;

    private SessionMiddleware $middleware;

    private TestRequestHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new TestSessionRepository();
        $this->logger = new TestLogger();
        $this->sessionService = new SessionService($this->repository, $this->logger);

        $config = SessionConfig::fromArray([
            'cookie_name' => 'session',
            'cookie_ttl' => 86400,
            'session_ttl' => 3600,
        ]);

        // Create a deterministic session payload for the middleware flow.
        $sessionPayload = $this->createTestSessionPayload('Test Agent');

        // Use concrete test implementations to keep the flow integration-like.
        $sessionPayloadFactory = new TestSessionPayloadFactoryImpl($sessionPayload);
        $jsonAdapter = new TestJsonFieldAdapterImpl();

        $this->middleware = new SessionMiddleware(
            $this->sessionService,
            $this->logger,
            $config,
            $sessionPayloadFactory,
            $jsonAdapter,
        );
        $this->handler = new TestRequestHandler();
    }

    public function testCreatesNewSessionWhenNoSessionIdInRequest(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->handler->response = new Response();

        $response = $this->middleware->process($request, $this->handler);

        // Ensure a new session was created.
        self::assertCount(1, $this->repository->sessions);

        // Ensure the request contains the session attribute.
        $processedRequest = $this->handler->lastRequest;
        self::assertNotNull($processedRequest);

        $session = $processedRequest->getAttribute('session');
        self::assertInstanceOf(Session::class, $session);
        self::assertNull($session->userId);

        // Ensure session creation was logged.
        self::assertGreaterThanOrEqual(1, \count($this->logger->logs));

        $sessionCreatedMessage = false;
        foreach ($this->logger->logs as $log) {
            if (strpos($log['message'], 'Created new session') !== false) {
                $sessionCreatedMessage = true;
                break;
            }
        }

        self::assertTrue($sessionCreatedMessage, 'Log should contain "Created new session" message');

        // Ensure the response contains a session cookie.
        self::assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('session=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
    }

    public function testUsesExistingSessionFromCookie(): void
    {
        // Seed an existing session in the repository.
        $existingSession = new Session(
            id: 'existing-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );
        $this->repository->sessions['existing-session-id'] = $existingSession;

        // Build a request that carries the session cookie.
        $request = new ServerRequest('GET', '/');
        $request = $request->withCookieParams(['session' => 'existing-session-id']);

        $this->handler->response = new Response();

        $response = $this->middleware->process($request, $this->handler);

        // Ensure the existing session was reused without creating a new one.
        self::assertCount(1, $this->repository->sessions);

        // Ensure request attributes contain the existing session.
        $processedRequest = $this->handler->lastRequest;
        self::assertNotNull($processedRequest);

        $session = $processedRequest->getAttribute('session');
        self::assertSame($existingSession, $session);

        // Ensure the response cookie points to the reused session.
        self::assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('session=existing-session-id', $cookie);
    }

    public function testUsesExistingSessionFromBearerToken(): void
    {
        // Seed an existing session in the repository.
        $existingSession = new Session(
            id: 'token-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );
        $this->repository->sessions['token-session-id'] = $existingSession;

        // Build a request with a bearer token.
        $request = new ServerRequest('GET', '/');
        $request = $request->withHeader('Authorization', 'Bearer token-session-id');

        $this->handler->response = new Response();

        $response = $this->middleware->process($request, $this->handler);

        // Ensure the existing session was reused without creating a new one.
        self::assertCount(1, $this->repository->sessions);

        // Ensure request attributes contain the existing session.
        $processedRequest = $this->handler->lastRequest;
        self::assertNotNull($processedRequest);

        $session = $processedRequest->getAttribute('session');
        self::assertSame($existingSession, $session);

        // Ensure the response cookie points to the reused session.
        self::assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('session=token-session-id', $cookie);
    }

    public function testCreatesNewSessionWhenExistingSessionIsInvalid(): void
    {
        // Seed an expired session in the repository.
        $expiredSession = new Session(
            id: 'invalid-session-id',
            userId: 1,
            payload: '{}',
            expiresAt: time() - 100, // Already expired.
            createdAt: time() - 200,
            updatedAt: time() - 100,
        );
        $this->repository->sessions['invalid-session-id'] = $expiredSession;

        // Build a request that carries the expired session cookie.
        $request = new ServerRequest('GET', '/');
        $request = $request->withCookieParams(['session' => 'invalid-session-id']);

        $this->handler->response = new Response();

        $response = $this->middleware->process($request, $this->handler);

        // Ensure a fresh session was created.
        self::assertCount(2, $this->repository->sessions); // Expired + new.

        // Ensure request attributes contain the fresh session.
        $processedRequest = $this->handler->lastRequest;
        self::assertNotNull($processedRequest);

        $session = $processedRequest->getAttribute('session');
        self::assertInstanceOf(Session::class, $session);
        self::assertNotSame($expiredSession, $session);
        self::assertNull($session->userId);

        // Ensure the response cookie points to the new session.
        self::assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('session=', $cookie);
        self::assertStringNotContainsString('session=invalid-session-id', $cookie);
    }

    public function testDoesNotRefreshSessionForErrorResponses(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->handler->response = new Response(500);
        $startedAt = time();

        $response = $this->middleware->process($request, $this->handler);

        // Ensure a session was created.
        self::assertCount(1, $this->repository->sessions);

        // Error responses must not refresh session cookies.
        self::assertFalse($response->hasHeader('Set-Cookie'));

        $session = $this->handler->lastRequest?->getAttribute('session');
        self::assertInstanceOf(Session::class, $session);
        self::assertGreaterThanOrEqual($startedAt + 3600, $session->expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $session->expiresAt);
    }

    public function testMatchingFingerprintNeverRestoresSessionWithoutCredentials(): void
    {
        $existingSession = new Session(
            id: 'existing-fingerprint-session-id',
            userId: 42,
            payload: '{"ip":"127.0.0.1","userAgent":"Test Agent"}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );
        $this->repository->sessions['existing-fingerprint-session-id'] = $existingSession;

        $request = new ServerRequest('GET', '/');
        $this->handler->response = new Response();

        $firstResponse = $this->middleware->process($request, $this->handler);
        $firstSession = $this->handler->lastRequest?->getAttribute('session');
        self::assertInstanceOf(Session::class, $firstSession);
        self::assertNull($firstSession->userId);
        self::assertNotSame($existingSession->id, $firstSession->id);
        self::assertSame($existingSession->payload, $firstSession->payload);
        self::assertStringContainsString('session=' . $firstSession->id, $firstResponse->getHeaderLine('Set-Cookie'));

        $secondResponse = $this->middleware->process($request, $this->handler);
        $secondSession = $this->handler->lastRequest?->getAttribute('session');
        self::assertInstanceOf(Session::class, $secondSession);
        self::assertNull($secondSession->userId);
        self::assertNotSame($existingSession->id, $secondSession->id);
        self::assertNotSame($firstSession->id, $secondSession->id);
        self::assertStringContainsString('session=' . $secondSession->id, $secondResponse->getHeaderLine('Set-Cookie'));
        self::assertCount(3, $this->repository->sessions);
        self::assertSame(0, $this->repository->findAllCalls);
    }

    public function testUsesActiveSessionIdFromResponseHeaders(): void
    {
        $currentSession = new Session(
            id: 'current-session-id',
            userId: null,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );
        $targetSession = new Session(
            id: 'target-session-id',
            userId: 77,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );

        $this->repository->sessions[$currentSession->id] = $currentSession;
        $this->repository->sessions[$targetSession->id] = $targetSession;

        $request = new ServerRequest('GET', '/');
        $request = $request->withCookieParams(['session' => $currentSession->id]);

        $this->handler->response = (new Response())
            ->withHeader(SessionResponseHeaders::ACTIVE_SESSION_ID, $targetSession->id);

        $response = $this->middleware->process($request, $this->handler);

        self::assertFalse($response->hasHeader(SessionResponseHeaders::ACTIVE_SESSION_ID));
        self::assertTrue($response->hasHeader('Set-Cookie'));
        self::assertStringContainsString('session=target-session-id', $response->getHeaderLine('Set-Cookie'));
    }

    public function testDestroysSessionCookieWhenResponseRequestsDeletion(): void
    {
        $existingSession = new Session(
            id: 'session-to-destroy',
            userId: 42,
            payload: '{}',
            expiresAt: time() + 3600,
            createdAt: time() - 100,
            updatedAt: time() - 50,
        );
        $this->repository->sessions[$existingSession->id] = $existingSession;

        $request = new ServerRequest('GET', '/');
        $request = $request->withCookieParams(['session' => $existingSession->id]);

        $this->handler->response = (new Response())
            ->withHeader(SessionResponseHeaders::DESTROY_SESSION, '1');

        $response = $this->middleware->process($request, $this->handler);

        self::assertFalse($response->hasHeader(SessionResponseHeaders::DESTROY_SESSION));
        self::assertTrue($response->hasHeader('Set-Cookie'));
        self::assertStringContainsString('session=deleted', $response->getHeaderLine('Set-Cookie'));
    }

    public function testExpiresCookieWhenSessionIsRevokedDuringHandler(): void
    {
        $existingSession = $this->sessionService->createSession(42, '{}');
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['session' => $existingSession->id]);
        $handler = new readonly class ($this->sessionService, $existingSession->id) implements RequestHandler {
            public function __construct(
                private SessionService $sessionService,
                private string $sessionId,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->sessionService->deleteSession($this->sessionId);

                return new Response();
            }
        };

        $response = $this->middleware->process($request, $handler);

        self::assertNull($this->sessionService->validateSession($existingSession->id));
        self::assertStringContainsString('session=deleted', $response->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString('session=' . $existingSession->id, $response->getHeaderLine('Set-Cookie'));
    }
}

final class TestRequestHandler implements RequestHandler
{
    public ?ServerRequestInterface $lastRequest = null;

    public ResponseInterface $response;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->response;
    }
}

/**
 * Test repository for SessionMiddleware.
 */
final class TestSessionRepository implements SessionRepository
{
    /** @var array<string, Session> */
    public array $sessions = [];

    public int $findAllCalls = 0;

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
        ++$this->findAllCalls;

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

/**
 * Test implementation of SessionPayloadFactory.
 */
final readonly class TestSessionPayloadFactoryImpl implements SessionPayloadFactory
{
    public function __construct(
        private SessionPayload $sessionPayload,
    ) {}

    public function createFromRequest(ServerRequestInterface $request): SessionPayload
    {
        return $this->sessionPayload;
    }

    public function createDefault(): SessionPayload
    {
        return $this->sessionPayload;
    }
}

/**
 * Test JsonFieldAdapter implementation.
 */
final readonly class TestJsonFieldAdapterImpl implements JsonFieldAdapter
{
    public function serialize(object $object): string
    {
        return '{"ip":"127.0.0.1","userAgent":"Test Agent"}';
    }

    /**
     * Deserializes JSON into the requested object type.
     */
    public function deserialize(string $jsonValue, string $targetClass): object
    {
        // Always return a SessionPayload object for tests
        $result = new SessionPayload(
            ip: '127.0.0.1',
            userAgent: 'Test Agent',
            acceptLanguage: 'en-US',
            acceptEncoding: 'gzip',
            xForwardedFor: null,
            referer: null,
            origin: null,
            secChUa: null,
            secChUaPlatform: null,
            secChUaMobile: null,
            dnt: null,
            secFetchDest: null,
            secFetchMode: null,
            secFetchSite: null,
        );

        return $result;
    }

    /**
     * Deserializes JSON and falls back to the provided default value on failure.
     */
    public function tryDeserialize(string $jsonValue, string $targetClass, object $defaultValue): object
    {
        try {
            return $this->deserialize($jsonValue, $targetClass);
        } catch (\Throwable) {
            return $defaultValue;
        }
    }

    public function trySerialize(object $object, string $defaultJson = '{}'): string
    {
        return $this->serialize($object);
    }
}
