<?php

declare(strict_types=1);

namespace Project\HttpFixtures;

final readonly class AttachmentFiles
{
    public const array SIZES = [0, 8191, 8192, 8193, 1_048_576, 16_777_233];

    /** @return array<int, string> */
    public static function prepare(string $directory): array
    {
        $block = str_repeat(implode('', array_map(chr(...), range(0, 255))), 32);
        $hashes = [];
        foreach (self::SIZES as $size) {
            $file = fopen($directory . '/' . $size . '.bin', 'wb');
            if ($file === false) {
                throw new \RuntimeException('Cannot create a private attachment fixture');
            }

            try {
                for ($remaining = $size; $remaining > 0; $remaining -= \strlen($chunk)) {
                    $chunk = substr($block, 0, min($remaining, \strlen($block)));
                    if (fwrite($file, $chunk) !== \strlen($chunk)) {
                        throw new \RuntimeException('Cannot write an attachment fixture');
                    }
                }
            } finally {
                fclose($file);
            }
            $hash = hash_file('sha256', $directory . '/' . $size . '.bin');
            if ($hash === false) {
                throw new \RuntimeException('Cannot hash an attachment fixture');
            }
            $hashes[$size] = $hash;
        }

        return $hashes;
    }
}
