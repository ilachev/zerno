<?php

declare(strict_types=1);

namespace Project\Security;

use App\Platform\Runtime\PhpRuntime;

final class Advisories
{
    /** Data-only HTTPS access; no tool or package downloads. */
    public static function request(string $url, ?string $payload = null): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Advisory requests require HTTPS');
        }
        $context = stream_context_create(['http' => [
            'method' => $payload === null ? 'GET' : 'POST',
            'header' => "User-Agent: zerno-security-audit\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload ?? '',
            'timeout' => 30,
            'follow_location' => 0,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Advisory service unavailable: ' . $url);
        }

        try {
            $bytes = stream_get_contents($stream, 4_194_305);
            $meta = stream_get_meta_data($stream);
            $headers = $meta['wrapper_data'] ?? [];
            if (!\is_array($headers) || !\is_string($headers[0] ?? null) || preg_match('~^HTTP/\S+ 200(?: |$)~', $headers[0]) !== 1) {
                throw new \RuntimeException('Advisory service returned an unsuccessful status');
            }
            if ($bytes === false || \strlen($bytes) > 4_194_304 || $meta['timed_out'] || !feof($stream)) {
                throw new \RuntimeException('Incomplete or oversized advisory response');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<array{name: string, version: string, ecosystem: string, scope: string}> $packages
     * @return list<array{package: string, version: string, scope: string, advisory: string}>
     */
    public static function findings(array $packages, string $response): array
    {
        $decoded = json_decode($response, false, 64, JSON_THROW_ON_ERROR);
        $results = $decoded instanceof \stdClass ? ($decoded->results ?? null) : null;
        if (!\is_array($results) || !array_is_list($results) || \count($results) !== \count($packages)) {
            throw new \RuntimeException('OSV response does not cover every queried package');
        }
        $findings = [];
        foreach ($packages as $index => $package) {
            $result = $results[$index];
            if (!$result instanceof \stdClass || property_exists($result, 'next_page_token') || array_diff(array_keys(get_object_vars($result)), ['vulns']) !== []) {
                // Never turn a partial/paginated result into a clean report.
                throw new \RuntimeException('OSV response needs additional review or pagination');
            }
            $vulns = property_exists($result, 'vulns') ? $result->vulns : [];
            if (!\is_array($vulns) || !array_is_list($vulns)) {
                throw new \RuntimeException('Invalid OSV findings');
            }
            foreach ($vulns as $vuln) {
                $id = $vuln instanceof \stdClass ? ($vuln->id ?? null) : null;
                if (!\is_string($id) || $id === '') {
                    throw new \RuntimeException('Invalid advisory identifier');
                }
                $findings[] = ['package' => $package['name'], 'version' => $package['version'], 'scope' => $package['scope'], 'advisory' => $id];
            }
        }

        return $findings;
    }

    public static function assertPhpPolicyCurrent(string $response): void
    {
        $releases = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($releases) || $releases === []) {
            throw new \RuntimeException('PHP security release feed is empty');
        }
        $found = false;
        foreach ($releases as $version => $release) {
            if (!\is_string($version) || preg_match('/^8\.5\.\d+$/D', $version) !== 1 || !\is_array($release) || !\is_array($release['tags'] ?? null)) {
                throw new \RuntimeException('Invalid PHP release feed');
            }
            foreach ($release['tags'] as $tag) {
                if (!\is_string($tag)) {
                    throw new \RuntimeException('Invalid PHP release tag');
                }
            }
            if (\in_array('security', $release['tags'], true)) {
                $found = true;
                if (version_compare($version, PhpRuntime::MINIMUM_VERSION, '>')) {
                    throw new \RuntimeException('Review the PHP security floor: upstream released ' . $version);
                }
            }
        }
        if (!$found) {
            throw new \RuntimeException('PHP release feed contains no security baseline');
        }
    }
}
