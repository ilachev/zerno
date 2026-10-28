<?php

declare(strict_types=1);

namespace Project\Security;

/** A reviewed source-import conclusion applies to one immutable server binary. */
final readonly class RoadRunnerReview
{
    public function __construct(private string $directory) {}

    public function notAffected(string $binarySha256, string $advisory, string $advisoryBytes, \DateTimeImmutable $now): bool
    {
        $review = Inventory::read($this->directory . '/roadrunner.json');
        if (($review['format_version'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported RoadRunner assessment format');
        }
        if (($review['binary_sha256'] ?? null) !== $binarySha256) {
            return false;
        }
        $reviewed = self::date($review['reviewed_on'] ?? null);
        $expires = self::date($review['expires_on'] ?? null);
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        // Both endpoint dates are valid, so 30 days between them cover 31 UTC dates.
        if ($today < $reviewed || $today > $expires || $expires < $reviewed || $expires->getTimestamp() - $reviewed->getTimestamp() > 30 * 86_400) {
            return false;
        }
        $advisories = $review['advisories'] ?? null;
        $assessment = \is_array($advisories) ? ($advisories[$advisory] ?? null) : null;
        if (!\is_array($assessment) || ($assessment['status'] ?? null) !== 'not_affected' || ($assessment['justification'] ?? null) !== 'vulnerable_code_not_present' || ($assessment['advisory_sha256'] ?? null) !== hash('sha256', $advisoryBytes)) {
            return false;
        }
        $bytes = file_get_contents($this->directory . '/roadrunner-imports.json', false, null, 0, 4_194_305);
        if ($bytes === false || \strlen($bytes) > 4_194_304 || hash('sha256', $bytes) !== ($review['evidence_sha256'] ?? null)) {
            throw new \RuntimeException('RoadRunner import evidence is missing or changed');
        }
        $evidence = Inventory::decode($bytes);
        if (($evidence['binary_sha256'] ?? null) !== $binarySha256 || ($evidence['source_revision'] ?? null) !== ($review['source_revision'] ?? null) || !\is_array($evidence['source_modules'] ?? null) || $evidence['source_modules'] === [] || $evidence['source_modules'] !== ($evidence['binary_modules'] ?? null)) {
            throw new \RuntimeException('RoadRunner source and binary module inventories disagree');
        }
        $packages = $evidence['packages'] ?? null;
        $affected = $assessment['affected_packages'] ?? null;
        if (!\is_array($packages) || !array_is_list($packages) || $packages === [] || !\is_array($affected) || !array_is_list($affected) || $affected === []) {
            throw new \RuntimeException('RoadRunner assessment needs concrete imported and affected packages');
        }
        foreach ($affected as $package) {
            if (!\is_string($package) || $package === '') {
                throw new \RuntimeException('Invalid affected Go package');
            }
            foreach ($packages as $import) {
                if (!\is_string($import) || $import === '') {
                    throw new \RuntimeException('Invalid source import evidence');
                }
                if ($import === $package || str_starts_with($import, $package . '/')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('Invalid RoadRunner assessment date');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException('Invalid RoadRunner assessment date');
        }

        return $date;
    }
}
