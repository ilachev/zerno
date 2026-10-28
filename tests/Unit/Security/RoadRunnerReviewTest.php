<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\Security\Inventory;
use Project\Security\RoadRunnerReview;

final class RoadRunnerReviewTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/zerno-security-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->evidence(['source_revision' => 'revision', 'binary_sha256' => 'binary', 'source_modules' => ['example/library' => 'v1'], 'binary_modules' => ['example/library' => 'v1'], 'packages' => ['main', 'example/library/safe']]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAssessmentIsScopedToArtifactAdvisoryAndItsUnmodifiedDefinition(): void
    {
        $review = new RoadRunnerReview($this->directory);
        $now = new \DateTimeImmutable('2026-10-28T00:00:00Z');
        self::assertTrue($review->notAffected('binary', 'GO-2026-5932', 'advisory', $now));
        self::assertFalse($review->notAffected('another binary', 'GO-2026-5932', 'advisory', $now));
        self::assertFalse($review->notAffected('binary', 'GO-2026-99999', 'advisory', $now));
        self::assertFalse($review->notAffected('binary', 'GO-2026-5932', 'changed advisory', $now));
    }

    public function testAssessmentExpiresByUtcDate(): void
    {
        $review = new RoadRunnerReview($this->directory);
        self::assertTrue($review->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-11-27T23:59:59Z')));
        self::assertFalse($review->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-11-28T00:00:00Z')));
        self::assertFalse($review->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-10-27T23:59:59Z')));
    }

    #[DataProvider('provideImportedAffectedPackagePreventsApprovalCases')]
    public function testImportedAffectedPackagePreventsApproval(string $package): void
    {
        $evidence = Inventory::read($this->directory . '/roadrunner-imports.json');
        $evidence['packages'] = [$package];
        $this->evidence($evidence);
        self::assertFalse(new RoadRunnerReview($this->directory)->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-10-28T00:00:00Z')));
    }

    /** @return iterable<string, array{string}> */
    public static function provideImportedAffectedPackagePreventsApprovalCases(): iterable
    {
        yield 'exact package' => ['golang.org/x/crypto/openpgp'];
        yield 'subpackage' => ['golang.org/x/crypto/openpgp/armor'];
    }

    public function testChangedEvidenceFailsClosed(): void
    {
        file_put_contents($this->directory . '/roadrunner-imports.json', '{}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('evidence is missing or changed');
        new RoadRunnerReview($this->directory)->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-10-28T00:00:00Z'));
    }

    public function testDifferentSourceModuleVersionFailsClosed(): void
    {
        $evidence = Inventory::read($this->directory . '/roadrunner-imports.json');
        $evidence['source_modules'] = ['example/library' => 'v2'];
        $this->evidence($evidence);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('module inventories disagree');
        new RoadRunnerReview($this->directory)->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-10-28T00:00:00Z'));
    }

    public function testPermanentAssessmentCannotPass(): void
    {
        $review = Inventory::read($this->directory . '/roadrunner.json');
        $review['expires_on'] = '2099-01-01';
        file_put_contents($this->directory . '/roadrunner.json', json_encode($review, JSON_THROW_ON_ERROR));
        self::assertFalse(new RoadRunnerReview($this->directory)->notAffected('binary', 'GO-2026-5932', 'advisory', new \DateTimeImmutable('2026-10-28T00:00:00Z')));
    }

    /** @param array<string, mixed> $evidence */
    private function evidence(array $evidence): void
    {
        $bytes = json_encode($evidence, JSON_THROW_ON_ERROR);
        file_put_contents($this->directory . '/roadrunner-imports.json', $bytes);
        file_put_contents($this->directory . '/roadrunner.json', json_encode([
            'format_version' => 1, 'binary_sha256' => 'binary', 'source_revision' => 'revision',
            'reviewed_on' => '2026-10-28', 'expires_on' => '2026-11-27', 'evidence_sha256' => hash('sha256', $bytes),
            'advisories' => ['GO-2026-5932' => ['status' => 'not_affected', 'justification' => 'vulnerable_code_not_present', 'advisory_sha256' => hash('sha256', 'advisory'), 'affected_packages' => ['golang.org/x/crypto/openpgp']]],
        ], JSON_THROW_ON_ERROR));
    }
}
