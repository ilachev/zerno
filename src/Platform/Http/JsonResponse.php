<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Error\Error;

final readonly class JsonResponse implements ErrorResponse
{
    public function success(string $data, int $status = 200): Response
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
    public function error(Error $error, int $status): Response
    {
        return $this->success(
            json_encode(['error' => $error->text()], JSON_THROW_ON_ERROR),
            $status,
        );
    }
}
