<?php

declare(strict_types=1);

namespace App\Platform\Http;

final class Request
{
    private Headers $headers;

    private readonly Uri $uri;

    private Body $body;

    private ?string $target = null;

    /** @var array<array-key, mixed> */
    private array $query = [];

    /** @var array<array-key, mixed> */
    private array $cookies = [];

    /** @var array<array-key, mixed> */
    private array $uploads = [];

    /** @var array<array-key, mixed>|null */
    private ?array $parsedBody = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<array-key, string|list<string>> $headers
     * @param array<array-key, mixed> $serverParams
     */
    public function __construct(
        private readonly string $method,
        string|Uri $uri,
        array $headers = [],
        string|Body $body = '',
        private string $protocol = '1.1',
        private readonly array $serverParams = [],
    ) {
        if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $method) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP method');
        }
        self::validateProtocol($protocol);
        $this->uri = \is_string($uri) ? new Uri($uri) : $uri;
        $this->headers = new Headers($headers);
        if (!$this->headers->has('Host') && $this->uri->getHost() !== '') {
            $this->headers = $this->headers->with('Host', $this->uri->getHost() . ($this->uri->getPort() === null ? '' : ':' . $this->uri->getPort()));
        }
        $this->body = \is_string($body) ? Body::create($body) : $body;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function getRequestTarget(): string
    {
        return $this->target ?? (($this->uri->getPath() === '' ? '/' : $this->uri->getPath())
            . ($this->uri->getQuery() === '' ? '' : '?' . $this->uri->getQuery()));
    }

    public function withRequestTarget(string $target): self
    {
        if (preg_match('/[\x00-\x20\x7f#\\\]|%(?![a-fA-F0-9]{2})/', $target) === 1
            || ($target !== '*' && !str_starts_with($target, '/')) || ($target === '*' && $this->method !== 'OPTIONS')) {
            throw new \InvalidArgumentException('Invalid request target');
        }
        $copy = clone $this;
        $copy->target = $target;

        return $copy;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): self
    {
        self::validateProtocol($version);
        $copy = clone $this;
        $copy->protocol = $version;

        return $copy;
    }

    public function getBody(): Body
    {
        return $this->body;
    }

    public function withBody(Body $body): self
    {
        $copy = clone $this;
        $copy->body = $body;

        return $copy;
    }

    /** @return array<array-key, list<string>> */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, string|array $value): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->with($name, $value);

        return $copy;
    }

    /** @return array<array-key, mixed> */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /** @return array<array-key, mixed> */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    /** @param array<array-key, mixed> $query */
    public function withQueryParams(array $query): self
    {
        $copy = clone $this;
        $copy->query = $query;

        return $copy;
    }

    /** @return array<array-key, mixed> */
    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    /** @param array<array-key, mixed> $cookies */
    public function withCookieParams(array $cookies): self
    {
        $copy = clone $this;
        $copy->cookies = $cookies;

        return $copy;
    }

    /** @return array<array-key, mixed>|null */
    public function getParsedBody(): ?array
    {
        return $this->parsedBody;
    }

    /** @param array<array-key, mixed>|null $body */
    public function withParsedBody(?array $body): self
    {
        $copy = clone $this;
        $copy->parsedBody = $body;

        return $copy;
    }

    /** @return array<array-key, mixed> */
    public function getUploadedFiles(): array
    {
        return $this->uploads;
    }

    /** @param array<array-key, mixed> $files */
    public function withUploadedFiles(array $files): self
    {
        $copy = clone $this;
        $copy->uploads = $files;

        return $copy;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return \array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributes[$name] = $value;

        return $copy;
    }

    private static function validateProtocol(string $version): void
    {
        if (!\in_array($version, ['1.0', '1.1', '2', '3'], true)) {
            throw new \InvalidArgumentException('Invalid HTTP protocol');
        }
    }
}
