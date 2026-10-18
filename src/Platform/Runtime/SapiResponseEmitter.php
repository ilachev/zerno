<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use Psr\Http\Message\ResponseInterface;

final readonly class SapiResponseEmitter
{
    private const CHUNK_SIZE = 8192;

    public function emit(ResponseInterface $response, string $requestMethod = 'GET'): void
    {
        $body = $response->getBody();

        try {
            if (headers_sent()) {
                throw new \RuntimeException('Response headers have already been sent');
            }

            $status = $response->getStatusCode();
            foreach ($response->getHeaders() as $name => $values) {
                if (($status < 200 || $status === 204) && strcasecmp($name, 'Content-Length') === 0) {
                    continue;
                }
                $replace = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }

            // Location headers can change the status implicitly in PHP.
            http_response_code($status);

            if ($requestMethod === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
                return;
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (true) {
                $chunk = $body->read(self::CHUNK_SIZE);
                $finished = $body->eof();
                if ($chunk === '' && !$finished) {
                    throw new \RuntimeException('Response stream returned no data before EOF');
                }
                echo $chunk;

                if ($finished) {
                    break;
                }
            }
        } finally {
            // Emission owns the body, including responses whose body must not be sent.
            $body->close();
        }
    }
}
