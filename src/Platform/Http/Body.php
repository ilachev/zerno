<?php

declare(strict_types=1);

namespace App\Platform\Http;

/** Owns its resource; copies of a request/response share this body and its position. */
final class Body implements \Stringable
{
    /** @var resource|null */
    private $resource;

    private ?string $data = null;

    private int $offset = 0;

    private function __construct() {}

    private function __clone() {}

    /** @param string|resource $source */
    public static function create(mixed $source = ''): self
    {
        $body = new self();
        if (\is_string($source)) {
            $body->data = $source;
        } elseif (\is_resource($source) && get_resource_type($source) === 'stream') {
            $body->resource = $source;
        } else {
            throw new \InvalidArgumentException('HTTP body requires a string or stream resource');
        }

        return $body;
    }

    public static function fromFile(string $path): self
    {
        $resource = @fopen($path, 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Cannot open HTTP body');
        }

        return self::create($resource);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        if ($this->isSeekable()) {
            $this->rewind();
        }

        return $this->getContents();
    }

    public function close(): void
    {
        if (\is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->resource = null;
        $this->data = null;
    }

    public function getSize(): ?int
    {
        if ($this->data !== null) {
            return \strlen($this->data);
        }
        if (!\is_resource($this->resource)) {
            return null;
        }
        $stat = fstat($this->resource);

        return $stat === false ? null : $stat['size'];
    }

    public function isReadable(): bool
    {
        return $this->data !== null || (\is_resource($this->resource)
            && strpbrk(stream_get_meta_data($this->resource)['mode'], 'r+') !== false);
    }

    public function isWritable(): bool
    {
        return \is_resource($this->resource)
            && strpbrk(stream_get_meta_data($this->resource)['mode'], 'waxc+') !== false;
    }

    public function isSeekable(): bool
    {
        return $this->data !== null || (\is_resource($this->resource) && stream_get_meta_data($this->resource)['seekable']);
    }

    public function tell(): int
    {
        if ($this->data !== null) {
            return $this->offset;
        }
        $offset = \is_resource($this->resource) ? ftell($this->resource) : false;
        if ($offset === false) {
            throw new \RuntimeException('Cannot determine HTTP body position');
        }

        return $offset;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('HTTP body is not seekable');
        }
        if ($this->data !== null) {
            $position = match ($whence) {
                SEEK_SET => $offset,
                SEEK_CUR => $this->offset + $offset,
                SEEK_END => \strlen($this->data) + $offset,
                default => -1,
            };
            if ($position < 0) {
                throw new \RuntimeException('Invalid HTTP body position');
            }
            $this->offset = $position;
        } elseif (!\is_resource($this->resource) || fseek($this->resource, $offset, $whence) !== 0) {
            throw new \RuntimeException('Cannot seek HTTP body');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    /** @phpstan-impure */
    public function eof(): bool
    {
        return $this->data !== null
            ? $this->offset >= \strlen($this->data)
            : (!\is_resource($this->resource) || feof($this->resource));
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Read length must not be negative');
        }
        if (!$this->isReadable()) {
            throw new \RuntimeException('Cannot read from non-readable stream');
        }
        if ($length === 0) {
            return '';
        }
        if ($this->data !== null) {
            $chunk = substr($this->data, $this->offset, $length);
            $this->offset += \strlen($chunk);

            return $chunk;
        }
        if (!\is_resource($this->resource) || ($chunk = fread($this->resource, $length)) === false) {
            throw new \RuntimeException('Cannot read HTTP body');
        }

        return $chunk;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('Cannot read from non-readable stream');
        }
        if ($this->data !== null) {
            return $this->read(max(0, \strlen($this->data) - $this->offset));
        }
        if (!\is_resource($this->resource) || ($contents = stream_get_contents($this->resource)) === false) {
            throw new \RuntimeException('Cannot read HTTP body');
        }

        return $contents;
    }

    /**
     * Reads remaining bytes without rewinding or closing the body.
     * On overflow consumes at most maxBytes + 1 logical bytes; partial data is not returned.
     * @throws \InvalidArgumentException when the limit is negative
     * @throws \LengthException when the remaining body exceeds the limit
     * @throws \RuntimeException when reading fails or makes no progress before EOF
     */
    public function readLimited(int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Body byte limit must not be negative');
        }
        $remaining = $maxBytes;
        $contents = '';

        while (true) {
            // Only add one when remaining is small, including when maxBytes is PHP_INT_MAX.
            $chunk = $this->read($remaining >= 8192 ? 8192 : $remaining + 1);
            $length = \strlen($chunk);
            if ($length > $remaining) {
                throw new \LengthException('HTTP body exceeds the byte limit');
            }
            $contents .= $chunk;
            $remaining -= $length;
            if ($this->eof()) {
                return $contents;
            }
            if ($length === 0) {
                throw new \RuntimeException('HTTP body returned no data before EOF');
            }
        }
    }
}
