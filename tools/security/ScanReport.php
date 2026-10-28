<?php

declare(strict_types=1);

namespace Project\Security;

/** Reads the complete machine report; SARIF mode does not fail on findings. */
final class ScanReport
{
    /** @return list<string> */
    public static function findings(string $bytes): array
    {
        $document = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        $runs = $document instanceof \stdClass ? ($document->runs ?? null) : null;
        if (!$document instanceof \stdClass || ($document->version ?? null) !== '2.1.0' || !\is_array($runs) || !array_is_list($runs) || \count($runs) !== 1 || !$runs[0] instanceof \stdClass) {
            throw new \RuntimeException('Incomplete govulncheck SARIF report');
        }
        $run = $runs[0];
        $tool = $run->tool ?? null;
        $driver = $tool instanceof \stdClass ? ($tool->driver ?? null) : null;
        $properties = $driver instanceof \stdClass ? ($driver->properties ?? null) : null;
        if (!$driver instanceof \stdClass || ($driver->name ?? null) !== 'govulncheck' || !$properties instanceof \stdClass || ($properties->scan_mode ?? null) !== 'binary' || ($properties->scan_level ?? null) !== 'symbol') {
            throw new \RuntimeException('Expected a complete govulncheck binary symbol scan');
        }
        $results = $run->results ?? null;
        $rules = $driver->rules ?? null;
        if (!\is_array($results) || !array_is_list($results) || !\is_array($rules) || !array_is_list($rules)) {
            throw new \RuntimeException('Missing govulncheck results or advisory definitions');
        }
        $ids = [];
        foreach ($rules as $rule) {
            $id = $rule instanceof \stdClass ? ($rule->id ?? null) : null;
            if (!\is_string($id) || preg_match('/^GO-\d{4}-\d+$/D', $id) !== 1) {
                throw new \RuntimeException('Invalid Go advisory definition');
            }
            $ids[] = $id;
        }
        $findings = [];
        foreach ($results as $result) {
            $id = $result instanceof \stdClass ? ($result->ruleId ?? null) : null;
            if (!\is_string($id) || !\in_array($id, $ids, true)) {
                throw new \RuntimeException('Finding without a Go advisory definition');
            }
            $findings[] = $id;
        }

        return array_values(array_unique($findings));
    }
}
