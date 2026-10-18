<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Error\Error;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class JsonResponse implements ErrorResponse
{
    public function success(string $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $data,
        );
    }

    /**
     * @throws \JsonException
     */
    public function error(Error $error, int $status): ResponseInterface
    {
        return $this->success(
            json_encode(['error' => $error->text()], JSON_THROW_ON_ERROR),
            $status,
        );
    }
}
