<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\BackupAlertProvider;
use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class BackupAlertProviderTest extends TestCase
{
    private function createResult(string $status, string $checkedAt): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind(BackupResultRecorder::KIND)
            ->setUrl('https://example.com')
            ->setStatus($status)
            ->setSummary('24 tables')
            ->setCheckedAt(new \DateTime($checkedAt));
    }

    private function createProvider(?HealthCheckResult $latest, array $configs = []): BackupAlertProvider
    {
        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findLatestByKind')->willReturn(null === $latest ? [] : [$latest]);

        $values = array_merge(['site-backup-database' => 'example_db', 'site-backup-max-age-hours' => '30'], $configs);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? '');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/health-check');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new BackupAlertProvider($repository, $configService, $urlGenerator, $translator);
    }

    // The whole point of the provider: a backup that stopped running produces no email, so only the dashboard can say so
    public function testABackupOlderThanTheMaximumAgeRaisesADanger(): void
    {
        $alerts = $this->createProvider($this->createResult(HealthCheckResult::STATUS_OK, '-3 days'))->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.backup_alert_stale', $alerts[0]['label']);
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
    }

    // A stale success is worse than a fresh failure, and the successful row would otherwise keep the dashboard quiet
    public function testStalenessWinsOverTheRunsOwnStatus(): void
    {
        $alerts = $this->createProvider($this->createResult(HealthCheckResult::STATUS_ERROR, '-3 days'))->getAlerts();

        $this->assertSame('label.backup_alert_stale', $alerts[0]['label']);
    }

    public function testARecentSuccessfulBackupRaisesNothing(): void
    {
        $this->assertSame([], $this->createProvider($this->createResult(HealthCheckResult::STATUS_OK, '-2 hours'))->getAlerts());
    }

    public function testARecentFailedBackupRaisesADanger(): void
    {
        $alerts = $this->createProvider($this->createResult(HealthCheckResult::STATUS_ERROR, '-2 hours'))->getAlerts();

        $this->assertSame('label.backup_alert_error', $alerts[0]['label']);
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
    }

    public function testARecentWarnedBackupRaisesAWarning(): void
    {
        $alerts = $this->createProvider($this->createResult(HealthCheckResult::STATUS_WARNING, '-2 hours'))->getAlerts();

        $this->assertSame('label.backup_alert_warning', $alerts[0]['label']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }

    public function testAConfiguredBackupThatNeverRanRaisesAWarning(): void
    {
        $alerts = $this->createProvider(null)->getAlerts();

        $this->assertSame('label.backup_alert_never_ran', $alerts[0]['label']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }

    // Every config behind this alert is itself restricted, so an admin below ROLE_SUPER_ADMIN can neither read nor fix what it reports
    public function testEveryAlertIsRestrictedToSuperAdmin(): void
    {
        $alerts = $this->createProvider($this->createResult(HealthCheckResult::STATUS_ERROR, '-2 hours'))->getAlerts();

        $this->assertSame('ROLE_SUPER_ADMIN', $alerts[0]['role']);
    }

    // An unconfigured backup is already what the empty site-backup-database entry alerts on, through ConfigAlertProvider
    public function testNothingIsRaisedWhenTheBackupIsNotConfigured(): void
    {
        $this->assertSame([], $this->createProvider(null, ['site-backup-database' => ''])->getAlerts());
    }

    // A mistyped or empty maximum age falls back to the default rather than alerting on every single run
    public function testAnEmptyMaximumAgeFallsBackToTheDefault(): void
    {
        $provider = $this->createProvider($this->createResult(HealthCheckResult::STATUS_OK, '-2 hours'), ['site-backup-max-age-hours' => '']);

        $this->assertSame([], $provider->getAlerts());
    }
}
