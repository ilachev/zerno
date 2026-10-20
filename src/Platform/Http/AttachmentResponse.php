<?php

declare(strict_types=1);

namespace App\Platform\Http;

final readonly class AttachmentResponse
{
    /**
     * Takes ownership of the stream, including construction failures.
     * The caller verifies the representation byte size; emission owns successful responses.
     */
    public static function create(
        Body $body,
        string $filename,
        int $size,
        string $contentType = 'application/octet-stream',
        string $fallbackFilename = 'download',
    ): Response {
        try {
            if ($size < 0) {
                throw new \InvalidArgumentException('Attachment size must not be negative');
            }

            $fallback = trim(preg_replace('/[^A-Za-z0-9._-]/', '_', $fallbackFilename) ?? '', '.');
            if ($fallback === '') {
                $fallback = 'download';
            }

            $name = preg_match('//u', $filename) === 1
                ? trim(str_replace(['/', '\\'], '_', preg_replace('/\p{C}/u', '', $filename) ?? ''), '. ')
                : '';
            if ($name === '') {
                $name = $fallback;
            }

            return new Response(200, [
                'Content-Type' => $contentType,
                'Content-Length' => (string) $size,
                'Content-Disposition' => 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name),
                'X-Content-Type-Options' => 'nosniff',
            ], $body);
        } catch (\Throwable $exception) {
            $body->close();

            throw $exception;
        }
    }
}
