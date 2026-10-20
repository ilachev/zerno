<?php

declare(strict_types=1);

namespace Examples\JsonEcho;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

/** Application policy for one small POST JSON operation. */
final readonly class EchoHandler implements RequestHandler
{
    public function __construct(private int $maxBytes)
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('JSON body byte limit must not be negative');
        }
    }

    public function handle(Request $request): Response
    {
        $body = $request->getBody();

        try {
            $types = $request->getHeader('Content-Type');
            $type = strtolower(trim(explode(';', $types[0] ?? '', 2)[0]));
            $encoding = strtolower(trim($request->getHeaderLine('Content-Encoding')));
            if (\count($types) !== 1 || $type !== 'application/json' || !\in_array($encoding, ['', 'identity'], true)) {
                return $this->error(415, 'Unsupported media type');
            }

            try {
                $json = $body->readLimited($this->maxBytes);
            } catch (\LengthException) {
                return $this->error(413, 'Request body too large');
            }

            try {
                $input = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->error(400, 'Invalid JSON request');
            }
            if (!$input instanceof \stdClass || !isset($input->message) || !\is_string($input->message) || \count(get_object_vars($input)) !== 1) {
                return $this->error(400, 'Invalid JSON request');
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode(
                ['message' => $input->message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } finally {
            // This operation owns its input; error paths must release unread bodies too.
            $body->close();
        }
    }

    private function error(int $status, string $message): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => $message], JSON_THROW_ON_ERROR));
    }
}
