<?php

declare(strict_types=1);

namespace App\Capabilities\Session\Transport\Http;

use App\Capabilities\Session\Application\SessionPayloadFactory;
use App\Capabilities\Session\Domain\Session;
use App\Capabilities\Session\Domain\SessionConfig;
use App\Capabilities\Session\Domain\SessionService;
use App\Platform\Http\Middleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Hydration\JsonFieldAdapter;
use App\Platform\Logging\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SessionMiddleware implements Middleware
{
    public function __construct(
        private SessionService $sessionService,
        private Logger $logger,
        private SessionConfig $config,
        private SessionPayloadFactory $sessionPayloadFactory,
        private JsonFieldAdapter $jsonAdapter,
    ) {}

    public function getContext(string $name): mixed
    {
        return match ($name) {
            'sessionService' => $this->sessionService,
            'logger' => $this->logger,
            'config' => $this->config,
            'sessionPayloadFactory' => $this->sessionPayloadFactory,
            'jsonAdapter' => $this->jsonAdapter,
            default => null,
        };
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandler $handler,
    ): ResponseInterface {
        $sessionId = $this->extractSessionId($request);
        $session = null;
        $hadSessionIdButInvalid = false;

        if ($sessionId !== null) {
            $session = $this->sessionService->validateSession($sessionId);
            if ($session === null) {
                $hadSessionIdButInvalid = true;
            }
        }

        if ($session === null) {
            $sessionPayload = $this->sessionPayloadFactory->createFromRequest($request);
            $session = $this->sessionService->createSession(
                userId: null,
                payload: $this->jsonAdapter->serialize($sessionPayload),
                ttl: $this->config->sessionTtl,
            );

            if ($hadSessionIdButInvalid) {
                $this->logger->info('Invalid session ID detected, created new session', [
                    'session_id' => $session->id,
                    'invalid_session_id' => $sessionId,
                ]);
            } else {
                $this->logger->debug('Created new session', ['session_id' => $session->id]);
            }
        }

        $request = $request->withAttribute('session', $session);
        $response = $handler->handle($request);

        $activeSessionId = $response->getHeaderLine(SessionResponseHeaders::ACTIVE_SESSION_ID);
        $destroySession = $response->getHeaderLine(SessionResponseHeaders::DESTROY_SESSION) === '1';
        $response = $this->stripSessionControlHeaders($response);

        if ($destroySession) {
            return $this->expireSessionCookie($response);
        }

        if ($activeSessionId !== '') {
            $activeSession = $this->sessionService->validateSession($activeSessionId);
            if ($activeSession !== null) {
                $session = $activeSession;
            }
        }

        if ($response->getStatusCode() < 400) {
            $refreshedSession = $this->sessionService->refreshSession($session->id, $this->config->sessionTtl);
            $response = $refreshedSession !== null
                ? $this->addSessionCookie($response, $refreshedSession)
                : $this->expireSessionCookie($response);
        }

        return $response;
    }

    private function extractSessionId(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        $cookies = $request->getCookieParams();
        if (!isset($cookies[$this->config->cookieName])) {
            return null;
        }

        $cookie = $cookies[$this->config->cookieName];

        return \is_string($cookie) ? $cookie : null;
    }

    private function addSessionCookie(ResponseInterface $response, Session $session): ResponseInterface
    {
        $expires = gmdate('D, d M Y H:i:s T', $session->expiresAt);

        return $response->withAddedHeader(
            'Set-Cookie',
            \sprintf(
                '%s=%s; Expires=%s; Path=/; HttpOnly; SameSite=Lax',
                $this->config->cookieName,
                $session->id,
                $expires,
            ),
        );
    }

    private function expireSessionCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            \sprintf(
                '%s=deleted; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/; HttpOnly; SameSite=Lax',
                $this->config->cookieName,
            ),
        );
    }

    private function stripSessionControlHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withoutHeader(SessionResponseHeaders::ACTIVE_SESSION_ID)
            ->withoutHeader(SessionResponseHeaders::DESTROY_SESSION);
    }
}
