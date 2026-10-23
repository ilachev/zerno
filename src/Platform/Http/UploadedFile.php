<?php

declare(strict_types=1);

namespace App\Platform\Http;

final class UploadedFile
{
    private ?Body $body = null;

    private bool $moved = false;

    public function __construct(
        private readonly string $path,
        private readonly int $size,
        private readonly int $error,
        private readonly string $name,
        private readonly string $mediaType,
    ) {
        if ($size < 0 || !\in_array($error, [UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            throw new \InvalidArgumentException('Invalid uploaded file metadata');
        }
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    /** Client metadata is untrusted and must not determine a storage path. */
    public function getClientFilename(): string
    {
        return $this->name;
    }

    /** Client metadata is not a content-type verification. */
    public function getClientMediaType(): string
    {
        return $this->mediaType;
    }

    public function getStream(): Body
    {
        $this->assertAvailable();

        return $this->body ??= Body::fromFile($this->path);
    }

    public function moveTo(string $target): void
    {
        $this->assertAvailable();
        if ($target === '' || str_contains($target, "\0")) {
            throw new \InvalidArgumentException('Invalid upload destination');
        }
        $this->body?->close();
        $this->body = null;
        $moved = @rename($this->path, $target);
        if (!$moved) {
            throw new \RuntimeException('Cannot move uploaded file');
        }
        $this->moved = true;
    }

    private function assertAvailable(): void
    {
        if ($this->moved || $this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaded file is unavailable');
        }
    }
}
