<?php

declare(strict_types=1);

namespace App\Platform\Http;

/** HTTP request URI. Encoded path/query bytes are preserved for the router/application. */
final readonly class Uri implements \Stringable
{
    private string $scheme;

    private string $host;

    private ?int $port;

    private string $path;

    private string $query;

    public function __construct(string $uri = '')
    {
        $parts = parse_url($uri);
        if ($parts === false || preg_match('/[\x00-\x20\x7f\\\]|%(?![a-fA-F0-9]{2})/', $uri) === 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid HTTP request URI');
        }
        $this->scheme = strtolower($parts['scheme'] ?? '');
        $this->host = strtolower($parts['host'] ?? '');
        if (!\in_array($this->scheme, ['', 'http', 'https'], true) || ($this->scheme !== '' && $this->host === '')) {
            throw new \InvalidArgumentException('Invalid HTTP request URI scheme or host');
        }
        $port = $parts['port'] ?? null;
        $this->port = ($this->scheme === 'http' && $port === 80) || ($this->scheme === 'https' && $port === 443) ? null : $port;
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function __toString(): string
    {
        return ($this->scheme === '' ? '' : $this->scheme . ':')
            . ($this->host === '' ? '' : '//' . $this->host . ($this->port === null ? '' : ':' . $this->port))
            . $this->path . ($this->query === '' ? '' : '?' . $this->query);
    }
}
