<?php

declare(strict_types=1);

namespace App\Platform\Http;

final class Headers
{
    /** @var array<array-key, list<string>> */
    private array $values = [];

    /** @var array<string, string> */
    private array $names = [];

    /** @param array<array-key, string|list<string>> $headers */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $values) {
            $this->set((string) $name, $values, true);
        }
    }

    /** @return array<array-key, list<string>> */
    public function all(): array
    {
        return $this->values;
    }

    /** @return list<string> */
    public function get(string $name): array
    {
        return $this->values[$this->names[strtolower($name)] ?? ''] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->names[strtolower($name)]);
    }

    /** @param string|list<string> $values */
    public function with(string $name, string|array $values, bool $append = false): self
    {
        $copy = clone $this;
        $copy->set($name, $values, $append);

        return $copy;
    }

    public function without(string $name): self
    {
        $copy = clone $this;
        $key = strtolower($name);
        unset($copy->values[$copy->names[$key] ?? ''], $copy->names[$key]);

        return $copy;
    }

    /** @param string|list<string> $values */
    private function set(string $name, string|array $values, bool $append): void
    {
        if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP header name');
        }
        $values = \is_string($values) ? [$values] : $values;
        if ($values === []) {
            throw new \InvalidArgumentException('HTTP header values must not be empty');
        }
        foreach ($values as &$value) {
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid HTTP header value');
            }
            $value = trim($value, " \t");
        }
        unset($value);
        $key = strtolower($name);
        $original = $this->names[$key] ?? $name;
        $this->names[$key] = $original;
        $this->values[$original] = $append ? [...($this->values[$original] ?? []), ...$values] : $values;
    }
}
