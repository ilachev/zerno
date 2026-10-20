<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Platform\Http\Body;

/** A real PHP stream wrapper that observes I/O at the resource boundary. */
final class ObservedStream
{
    public int $reads = 0;

    public int $seeks = 0;

    public int $closes = 0;

    public int $largestRead = 0;

    public int $offset = 0;

    public function __construct(public readonly string $content, public readonly bool $failRead = false, public readonly ?int $failAfterReads = null) {}

    public function open(): Body
    {
        if (!\in_array('zerno-observed', stream_get_wrappers(), true)) {
            stream_wrapper_register('zerno-observed', ObservedStreamWrapper::class);
        }
        $context = stream_context_create(['observed' => ['state' => $this]]);
        $resource = fopen('zerno-observed://body', 'rb', context: $context);
        if ($resource === false) {
            throw new \RuntimeException('Cannot create observed stream');
        }

        return Body::create($resource);
    }
}

final class ObservedStreamWrapper
{
    /** @var resource|null */
    public $context;

    private ObservedStream $state;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!\is_resource($this->context)) {
            throw new \RuntimeException('Missing stream context');
        }
        $options = stream_context_get_options($this->context);
        $observed = $options['observed'] ?? null;
        $state = \is_array($observed) ? ($observed['state'] ?? null) : null;
        if (!$state instanceof ObservedStream) {
            throw new \RuntimeException('Missing stream state');
        }
        $this->state = $state;

        return true;
    }

    public function stream_read(int $length): string
    {
        ++$this->state->reads;
        $this->state->largestRead = max($length, $this->state->largestRead);
        if ($this->state->failRead || ($this->state->failAfterReads !== null && $this->state->reads > $this->state->failAfterReads)) {
            throw new \RuntimeException('Observed stream read failed');
        }
        $chunk = substr($this->state->content, $this->state->offset, $length);
        $this->state->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        ++$this->state->seeks;
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->state->offset + $offset,
            SEEK_END => \strlen($this->state->content) + $offset,
            default => -1,
        };
        if ($position < 0) {
            return false;
        }
        $this->state->offset = $position;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->state->offset;
    }

    public function stream_eof(): bool
    {
        return $this->state->offset >= \strlen($this->state->content);
    }

    /** @return array{size: int} */
    public function stream_stat(): array
    {
        return ['size' => \strlen($this->state->content)];
    }

    public function stream_close(): void
    {
        ++$this->state->closes;
    }
}
