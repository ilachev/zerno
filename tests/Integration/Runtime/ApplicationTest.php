<?php

declare(strict_types=1);

namespace Tests\Integration\Runtime;

use App\Capabilities\Session\Domain\SessionConfig;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Platform\Storage\Storage;
use Psr\Http\Message\ResponseInterface;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\TestAppFactory;

final class ApplicationTest extends IntegrationTestCase
{
    public function testDirectRequestsPreserveHealthSessionAndStatistics(): void
    {
        $app = TestAppFactory::getApp();
        $config = $this->container->get(SessionConfig::class);
        $sessions = $this->container->get(SessionRepository::class);
        $storage = $this->container->get(Storage::class);
        $firstResponse = $app->handle($this->createRequest(
            'GET',
            '/api/v1/health',
            ['User-Agent' => 'RuntimeIntegration/1.0', 'X-Request-ID' => 'runtime-first-request'],
        ));

        $this->assertHealthyResponse($firstResponse, 'runtime-first-request');
        $sessionId = $this->sessionIdFromResponse($firstResponse, $config->cookieName);
        $session = $sessions->findById($sessionId);
        self::assertNotNull($session);
        self::assertNull($session->userId);
        self::assertCount(1, $this->requestStats($storage, $sessionId));

        $secondResponse = $app->handleRequest($this->createRequest(
            'GET',
            '/api/v1/health',
            ['User-Agent' => 'RuntimeIntegration/1.0', 'X-Request-ID' => 'runtime-second-request'],
            cookies: [$config->cookieName => $sessionId],
        ));

        $this->assertHealthyResponse($secondResponse, 'runtime-second-request');
        self::assertSame($sessionId, $this->sessionIdFromResponse($secondResponse, $config->cookieName));
        self::assertNotNull($sessions->findById($sessionId));
        $stats = $this->requestStats($storage, $sessionId);
        self::assertCount(2, $stats);
        foreach ($stats as $stat) {
            self::assertSame('/api/v1/health', $stat['route']);
            self::assertSame('GET', $stat['method']);
            self::assertSame(200, $stat['status_code']);
        }
    }

    private function assertHealthyResponse(ResponseInterface $response, string $requestId): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($requestId, $response->getHeaderLine('X-Request-ID'));
        self::assertTrue($response->hasHeader('X-Response-Time'));
        self::assertTrue($response->hasHeader('Server-Timing'));
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertIsString($payload['timestamp'] ?? null);
        self::assertGreaterThan(0, (int) $payload['timestamp']);
    }

    private function sessionIdFromResponse(ResponseInterface $response, string $cookieName): string
    {
        $prefix = $cookieName . '=';
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (str_starts_with($cookie, $prefix)) {
                return explode(';', substr($cookie, \strlen($prefix)), 2)[0];
            }
        }

        self::fail('Expected a session cookie in the health response.');
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function requestStats(Storage $storage, string $sessionId): array
    {
        return $storage->query(
            'SELECT route, method, status_code FROM api_stats WHERE session_id = :session_id ORDER BY id',
            ['session_id' => $sessionId],
        );
    }
}
