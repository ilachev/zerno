<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Body;
use App\Platform\Logging\Logger;

final readonly class FileStore
{
    public function __construct(
        private string $directory,
        private Logger $logger,
    ) {}

    public function open(Document $document): ?Body
    {
        if (preg_match('/\A[a-f0-9]{64}\.pdf\z/', $document->storageKey) !== 1 || $document->fileSize < 0) {
            return $this->unavailable($document);
        }

        $root = realpath($this->directory);
        $candidate = $root === false ? null : $root . \DIRECTORY_SEPARATOR . $document->storageKey;

        if ($candidate !== null) {
            // A long-running worker must recheck the current file, including after operator recovery.
            clearstatcache(true, $candidate);
        }

        $path = $candidate === null ? false : realpath($candidate);

        if ($root === false || $path === false || !str_starts_with($path, rtrim($root, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR)) {
            return $this->unavailable($document);
        }

        $expected = @stat($path);

        if ($expected === false || ($expected['mode'] & 0o170000) !== 0o100000) {
            return $this->unavailable($document);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $this->unavailable($document);
        }

        $actual = fstat($handle);

        if (
            $actual === false
            || ($actual['mode'] & 0o170000) !== 0o100000
            || $actual['dev'] !== $expected['dev']
            || $actual['ino'] !== $expected['ino']
            || $actual['size'] !== $document->fileSize
        ) {
            fclose($handle);

            return $this->unavailable($document);
        }

        return Body::create($handle);
    }

    private function unavailable(Document $document): null
    {
        $this->logger->warning('A published document file is unavailable.', ['document_id' => $document->id]);

        return null;
    }
}
