<?php

declare(strict_types=1);

namespace App\Platform\Http;

final class Response
{
    private Headers $headers;

    private Body $body;

    /** @param array<array-key, string|list<string>> $headers */
    public function __construct(
        private readonly int $status = 200,
        array $headers = [],
        string|Body $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP response status');
        }
        $this->headers = new Headers($headers);
        $this->body = \is_string($body) ? Body::create($body) : $body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
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

    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, string|array $value): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->with($name, $value);

        return $copy;
    }

    /** @param string|list<string> $value */
    public function withAddedHeader(string $name, string|array $value): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->with($name, $value, true);

        return $copy;
    }

    public function withoutHeader(string $name): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->without($name);

        return $copy;
    }
}
