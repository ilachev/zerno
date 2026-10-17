<?php

declare(strict_types=1);

namespace Tests\Integration\Session;

use App\Capabilities\Session\Application\ClientDetector;
use App\Capabilities\Session\Application\ClientIdentity;
use App\Capabilities\Session\Application\SessionPayloadFactory;
use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionConfig;
use App\Capabilities\Session\Domain\SessionService;
use App\Capabilities\Session\Transport\Http\SessionMiddleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Hydration\JsonFieldAdapter;
use App\Platform\Logging\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Integration\IntegrationTestCase;

final class SessionFlowTest extends IntegrationTestCase
{
    private SessionService $sessionService;

    private ClientDetector $clientDetector;

    private SessionPayloadFactory $sessionPayloadFactory;

    private JsonFieldAdapter $jsonAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionService = $this->container->get(SessionService::class);
        $this->clientDetector = $this->container->get(ClientDetector::class);
        $this->sessionPayloadFactory = $this->container->get(SessionPayloadFactory::class);
        $this->jsonAdapter = $this->container->get(JsonFieldAdapter::class);
    }

    public function testMatchingFingerprintDoesNotRestoreAuthenticatedSession(): void
    {
        $request = $this->createClientRequest('192.168.20.10', 'curl/8.0');
        $payload = $this->jsonAdapter->serialize($this->sessionPayloadFactory->createFromRequest($request));
        $authenticatedSession = $this->sessionService->createSession(42, $payload);
        $middleware = new SessionMiddleware(
            $this->sessionService,
            $this->container->get(Logger::class),
            SessionConfig::fromArray(['session_ttl' => 1800]),
            $this->sessionPayloadFactory,
            $this->jsonAdapter,
        );
        $handler = new class implements RequestHandler {
            public ?Session $session = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $session = $request->getAttribute('session');
                if (!$session instanceof Session) {
                    throw new \LogicException('Session attribute is missing');
                }

                $this->session = $session;

                return new Response(500);
            }
        };
        $startedAt = time();

        $middleware->process($request, $handler);

        $anonymousSession = $handler->session;
        self::assertNotNull($anonymousSession);
        self::assertNull($anonymousSession->userId);
        self::assertNotSame($authenticatedSession->id, $anonymousSession->id);
        self::assertGreaterThanOrEqual($startedAt + 1800, $anonymousSession->expiresAt);
        self::assertLessThanOrEqual(time() + 1800, $anonymousSession->expiresAt);
        self::assertNotNull($this->sessionService->validateSession($anonymousSession->id));
        self::assertSame(42, $this->sessionService->validateSession($authenticatedSession->id)?->userId);

        $similarClients = $this->clientDetector->findSimilarClients($request->withAttribute('session', $anonymousSession));
        self::assertContains(
            $authenticatedSession->id,
            array_map(static fn(ClientIdentity $client): string => $client->id, $similarClients),
        );
    }

    /**
     * Тест проверяет, что при двух последовательных запросах одинакового клиента
     * создается новая сессия и затем она распознается как тот же клиент
     */
    public function testSessionCreationAndRecognition(): void
    {
        // Очищаем старые сессии перед тестом
        $this->sessionService->cleanupExpiredSessions();

        // Создаем первый запрос клиента с определенными параметрами
        $firstRequest = $this->createClientRequest('192.168.1.100', 'Chrome Browser');

        // Используем фабрику и JsonFieldAdapter для создания payload
        $sessionPayload = $this->sessionPayloadFactory->createFromRequest($firstRequest);
        $payload = $this->jsonAdapter->serialize($sessionPayload);

        $firstSession = $this->sessionService->createSession(
            userId: null,
            payload: $payload,
        );

        // Добавляем дополнительную сессию с другими данными
        $otherRequest = $this->createClientRequest('10.1.1.1', 'Firefox Browser');
        $otherSessionPayload = $this->sessionPayloadFactory->createFromRequest($otherRequest);
        $otherPayload = $this->jsonAdapter->serialize($otherSessionPayload);

        $this->sessionService->createSession(
            userId: null,
            payload: $otherPayload,
        );

        // Создаем тестовый запрос с теми же параметрами
        $testRequest = $this->createClientRequest('192.168.1.100', 'Chrome Browser')
            ->withAttribute('session', $firstSession);

        // Проверяем, что система находит идентичные fingerprint
        $similarClients = $this->clientDetector->findSimilarClients($testRequest, true);

        // Проверяем что нашлись похожие клиенты
        self::assertNotEmpty($similarClients, 'Должен найти похожих клиентов');

        // Проверяем что первая сессия найдена
        $found = false;
        foreach ($similarClients as $client) {
            if ($client->ipAddress === '192.168.1.100' && $client->userAgent === 'Chrome Browser') {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Должен распознать клиента по IP и User-Agent');
    }

    /**
     * Тест проверяет, что при запросах от разных клиентов система корректно
     * идентифицирует их как разных.
     */
    public function testDifferentClientsRecognition(): void
    {
        // Создаем первого клиента с Chrome
        $firstRequest = $this->createClientRequest('192.168.1.1', 'Chrome');
        $sessionPayload1 = $this->sessionPayloadFactory->createFromRequest($firstRequest);
        $payload1 = $this->jsonAdapter->serialize($sessionPayload1);

        $firstSession = $this->sessionService->createSession(
            userId: null,
            payload: $payload1,
        );

        // Создаем второго клиента с Firefox и другим IP
        $secondRequest = $this->createClientRequest('10.0.0.1', 'Firefox');
        $sessionPayload2 = $this->sessionPayloadFactory->createFromRequest($secondRequest);
        $payload2 = $this->jsonAdapter->serialize($sessionPayload2);

        $secondSession = $this->sessionService->createSession(
            userId: null,
            payload: $payload2,
        );

        // Запрос от второго клиента
        $request = $this->createClientRequest('10.0.0.1', 'Firefox')
            ->withAttribute('session', $secondSession);

        // Проверяем, что запрос второго клиента НЕ идентифицируется как первый
        $similarClients = $this->clientDetector->findSimilarClients($request, true);

        // Проверяем что клиенты отличаются
        $foundFirstClient = false;
        foreach ($similarClients as $client) {
            if ($client->id === $firstSession->id) {
                $foundFirstClient = true;
                break;
            }
        }

        // Первый клиент не должен быть найден как похожий на второго
        self::assertFalse($foundFirstClient, 'Не должен распознать как первого клиента');
    }

    /**
     * Проверяет работу с fingerprint без cookie.
     */
    public function testFingerprintingWithoutCookie(): void
    {
        // Очищаем старые сессии перед тестом
        $this->sessionService->cleanupExpiredSessions();

        // Создаем запрос клиента с Safari
        $firstRequest = $this->createClientRequest('192.168.1.10', 'Safari');
        $sessionPayload = $this->sessionPayloadFactory->createFromRequest($firstRequest);
        $payload = $this->jsonAdapter->serialize($sessionPayload);

        // Создаем первую сессию
        $session = $this->sessionService->createSession(
            userId: null,
            payload: $payload,
        );

        // Создаем дополнительную сессию с другими данными для уверенности в тесте
        $otherRequest = $this->createClientRequest('10.2.2.2', 'Edge Browser');
        $otherSessionPayload = $this->sessionPayloadFactory->createFromRequest($otherRequest);
        $otherPayload = $this->jsonAdapter->serialize($otherSessionPayload);

        $this->sessionService->createSession(
            userId: null,
            payload: $otherPayload,
        );

        // Создаем еще одну сессию с теми же Safari-данными для имитации другого захода
        $newSafariRequest = $this->createClientRequest('192.168.1.10', 'Safari');
        $newSessionPayload = $this->sessionPayloadFactory->createFromRequest($newSafariRequest);
        $newPayload = $this->jsonAdapter->serialize($newSessionPayload);

        $newSession = $this->sessionService->createSession(
            userId: null,
            payload: $newPayload,
        );

        // Создаем тестовый запрос с новой сессией
        $testRequest = $this->createClientRequest('192.168.1.10', 'Safari')
            ->withAttribute('session', $newSession);

        // Должны найти предыдущую сессию того же клиента по fingerprint
        $similarClients = $this->clientDetector->findSimilarClients($testRequest, true);

        self::assertNotEmpty($similarClients, 'Должен найти клиента по fingerprint');

        // Проверяем, что найдена сессия с тем же fingerprint
        $found = false;
        foreach ($similarClients as $client) {
            if ($client->ipAddress === '192.168.1.10' && $client->userAgent === 'Safari') {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Должен распознать клиента с Safari даже при разных сессиях');
    }

    /**
     * Проверяет корректность определения реального IP и геолокационных данных.
     */
    public function testRealIPGeolocation(): void
    {
        // Используем реальный публичный IP для тестирования геолокации
        $realIP = '8.8.8.8';  // Google DNS
        $userAgent = 'Chrome/112.0.0.0';

        // Создаем запрос с реальным IP
        $request = $this->createClientRequest($realIP, $userAgent);

        // Get client data which should include geolocation
        $sessionPayload = $this->sessionPayloadFactory->createFromRequest($request);

        // Check that IP was correctly identified
        self::assertEquals($realIP, $sessionPayload->ip, 'IP should be correctly identified');

        // Check that geolocation data was obtained
        self::assertNotNull($sessionPayload->geoLocation, 'Geolocation data should be filled');

        // Check basic geolocation fields
        self::assertNotEmpty($sessionPayload->geoLocation->country, 'Country should be identified');
        self::assertNotEmpty($sessionPayload->geoLocation->countryCode, 'Country code should be identified');

        // It's known that 8.8.8.8 belongs to Google in the US
        self::assertEquals('US', $sessionPayload->geoLocation->countryCode, 'Country code should match expected');

        // Создаем сессию с этими данными
        $payload = $this->jsonAdapter->serialize($sessionPayload);
        $session = $this->sessionService->createSession(
            userId: null,
            payload: $payload,
        );

        // Проверяем сохранение геолокационных данных в сессии
        $validatedSession = $this->sessionService->validateSession($session->id);
        self::assertNotNull($validatedSession, 'Сессия должна быть найдена');

        // Проверяем, что payload сохранился
        self::assertNotEmpty($validatedSession->payload, 'Payload должен быть сохранен');

        // Из payload сессии восстанавливаем данные
        $payloadData = json_decode($validatedSession->payload, true);
        self::assertIsArray($payloadData, 'Payload должен быть валидным JSON');

        // Проверяем, что в payload сохранился IP
        self::assertEquals($realIP, $payloadData['ip'], 'IP в сессии должен совпадать с оригинальным');

        // Проверяем, что geoLocation сохранился в сессии
        self::assertArrayHasKey('geoLocation', $payloadData, 'В payload должны быть данные геолокации');
        self::assertIsArray($payloadData['geoLocation'], 'Геолокация должна быть массивом');
        self::assertEquals('US', $payloadData['geoLocation']['countryCode'], 'Код страны в сессии должен совпадать');
    }

    /**
     * Создает тестовый запрос с заданными параметрами IP и User-Agent.
     */
    private function createClientRequest(
        string $ip = '192.168.1.1',
        string $userAgent = 'Test Browser',
        ?string $acceptLanguage = 'en-US,en;q=0.9',
        ?string $acceptEncoding = 'gzip, deflate',
    ): ServerRequest {
        $request = new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => $ip]);
        $request = $request->withHeader('User-Agent', $userAgent);

        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }

        if ($acceptEncoding !== null) {
            $request = $request->withHeader('Accept-Encoding', $acceptEncoding);
        }

        return $request;
    }
}
