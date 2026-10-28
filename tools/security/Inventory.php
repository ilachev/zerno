<?php

declare(strict_types=1);

namespace Project\Security;

/** Reads the delivery manifests, never a package-manager resolver. */
final readonly class Inventory
{
    public function __construct(private string $root) {}

    /** @return list<array{name: string, version: string, ecosystem: string, scope: string}> */
    public function packages(): array
    {
        $packages = [];
        foreach (['runtime', 'codegen/sql'] as $distribution) {
            $manifest = self::read($this->root . '/third_party/' . $distribution . '/manifest.json');
            $entries = $manifest['packages'] ?? null;
            if (!\is_array($entries) || $entries === []) {
                throw new \RuntimeException('Missing bundled packages: ' . $distribution);
            }
            foreach ($entries as $entry) {
                if (!\is_array($entry)) {
                    throw new \RuntimeException('Invalid bundled package');
                }
                if (($entry['type'] ?? null) === 'metapackage') {
                    // Compatibility metadata does not identify the RoadRunner executable.
                    continue;
                }
                $name = $entry['name'] ?? null;
                $version = $entry['version'] ?? null;
                if (!\is_string($name) || !\is_string($version) || $name === '' || $version === '') {
                    throw new \RuntimeException('Bundled packages need exact names and versions');
                }
                $packages[] = ['name' => $name, 'version' => ltrim($version, 'v'), 'ecosystem' => 'Packagist', 'scope' => $distribution];
            }
        }
        $manifest = self::read($this->root . '/third_party/tools/manifest.json');
        $tools = $manifest['tools'] ?? null;
        if (!\is_array($tools)) {
            throw new \RuntimeException('Missing quality tool versions');
        }
        foreach (['phpstan' => 'phpstan/phpstan', 'phpunit' => 'phpunit/phpunit', 'php-cs-fixer' => 'friendsofphp/php-cs-fixer'] as $tool => $name) {
            $entry = $tools[$tool] ?? null;
            $version = \is_array($entry) ? ($entry['version'] ?? null) : null;
            if (!\is_string($version) || $version === '') {
                throw new \RuntimeException('Missing quality tool version: ' . $tool);
            }
            $packages[] = ['name' => $name, 'version' => $version, 'ecosystem' => 'Packagist', 'scope' => 'tools'];
        }

        return $packages;
    }

    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        $bytes = file_get_contents($path, false, null, 0, 4_194_305);
        if ($bytes === false || \strlen($bytes) > 4_194_304) {
            throw new \RuntimeException('Cannot read security input: ' . $path);
        }

        return self::decode($bytes);
    }

    /** @return array<string, mixed> */
    public static function decode(string $bytes): array
    {
        $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('Invalid security input object');
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Invalid security input key');
            }
            $result[$key] = $entry;
        }

        return $result;
    }
}
