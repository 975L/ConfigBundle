<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\BackupHealthCheckAdviceProvider;
use c975L\ConfigBundle\Management\BackupResultRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class BackupHealthCheckAdviceProviderTest extends TestCase
{
    private function createResult(string $kind, ?array $details): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind($kind)
            ->setUrl('https://example.com')
            ->setStatus(HealthCheckResult::STATUS_OK)
            ->setSummary('summary')
            ->setDetails($details)
            ->setCheckedAt(new \DateTime());
    }

    private function createProvider(bool $isSuperAdmin = true): BackupHealthCheckAdviceProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($isSuperAdmin);

        return new BackupHealthCheckAdviceProvider($translator, $security);
    }

    public function testErrorsAndWarningsAreListedAsCollapsedItems(): void
    {
        $result = $this->createResult(BackupResultRecorder::KIND, [
            'errors' => ['mysqldump failed for table user'],
            'warnings' => ['Discarded empty file', 'Archive shrank'],
        ]);

        $lines = $this->createProvider()->buildAdvice([$result]);

        $advice = reset($lines);
        $this->assertSame('label.health_check_advice_backup_errors', $advice[0]['text']);
        $this->assertCount(1, $advice[0]['items']);
        $this->assertCount(2, $advice[1]['items']);
    }

    // The raw messages carry mysqldump's stderr, which names the backup user and host - both restricted to ROLE_SUPER_ADMIN
    public function testAnAdminBelowSuperAdminGetsTheCountWithoutTheRawMessages(): void
    {
        $result = $this->createResult(BackupResultRecorder::KIND, [
            'errors' => ["mysqldump failed for table user: Access denied for user 'bkp'@'db01'"],
            'warnings' => ['Discarded empty file'],
        ]);

        $lines = $this->createProvider(false)->buildAdvice([$result]);
        $advice = reset($lines);

        $this->assertSame('label.health_check_advice_backup_errors', $advice[0]['text']);
        $this->assertSame([], $advice[0]['items']);
        $this->assertSame([], $advice[1]['items']);
    }

    // The question the archives being copied offsite made impossible to answer without an SSH session
    public function testRetentionIsReportedWhenTheServerStillHoldsRuns(): void
    {
        $result = $this->createResult(BackupResultRecorder::KIND, [
            'errors' => [],
            'warnings' => [],
            'retention' => ['days' => 15, 'runs' => 5, 'oldest' => '2026-07-14'],
        ]);

        $lines = $this->createProvider()->buildAdvice([$result]);
        $advice = reset($lines);

        $this->assertSame('label.health_check_advice_backup_retention', $advice[0]['text']);
    }

    public function testNothingIsSaidAboutAnEmptyServerSideRetention(): void
    {
        $result = $this->createResult(BackupResultRecorder::KIND, [
            'errors' => [],
            'warnings' => [],
            'retention' => ['days' => 15, 'runs' => 0, 'oldest' => null],
        ]);

        $this->assertSame([], $this->createProvider()->buildAdvice([$result]));
    }

    // Other kinds have their own advice providers, this one must not answer for them
    public function testOtherKindsAreLeftAlone(): void
    {
        $result = $this->createResult('pagespeed', ['errors' => ['whatever']]);

        $this->assertSame([], $this->createProvider()->buildAdvice([$result]));
    }

    // A row recorded before details were stored, or by an older version, must not fatal the Health check page
    public function testAResultWithoutDetailsIsHandled(): void
    {
        $this->assertSame([], $this->createProvider()->buildAdvice([$this->createResult(BackupResultRecorder::KIND, null)]));
    }
}
