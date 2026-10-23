<?php

declare(strict_types=1);

namespace Project\ProtoGen;

use Google\Protobuf\Internal\CodedInputStream;

/** Reads only the supported google.api.http option, using the bundled wire reader. */
final class HttpBinding
{
    public static function path(string $options): string
    {
        $extensions = self::strings($options);
        if (array_keys($extensions) !== [72_295_728]) {
            throw new \InvalidArgumentException('RPC requires only google.api.http; other method options are unsupported');
        }
        $rule = self::strings($extensions[72_295_728]);
        ksort($rule);
        if (array_keys($rule) !== [4, 7] || $rule[7] !== '*'
            || preg_match('~\A/[a-zA-Z0-9/_-]+\z~', $rule[4]) !== 1) {
            throw new \InvalidArgumentException('HTTP binding requires a literal POST path and body: "*"; other bindings are unsupported');
        }

        return $rule[4];
    }

    /** @return array<int, string> */
    private static function strings(string $bytes): array
    {
        $input = new CodedInputStream($bytes);
        $fields = [];

        while ($input->current() < \strlen($bytes)) {
            $tag = $input->readTag();
            $length = 0;
            $value = '';
            if (!\is_int($tag) || $tag <= 0 || ($tag & 7) !== 2
                || !$input->readVarint32($length) || !\is_int($length) || $length < 0
                || !$input->readRaw($length, $value) || !\is_string($value)
                || isset($fields[$tag >> 3])) {
                throw new \InvalidArgumentException('Unsupported or malformed HTTP option');
            }
            $fields[$tag >> 3] = $value;
        }

        return $fields;
    }
}
