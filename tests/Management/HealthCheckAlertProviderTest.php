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
use c975L\ConfigBundle\Management\HealthCheckAlertProvider;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HealthCheckAlertProviderTest extends TestCase
{
    private function createResult(string $status, string $checkedAt = '2026-07-27 04:00:00'): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind('content-quality')
            ->setUrl('https://example.com/')
            ->setStatus($status)
            ->setSummary('summary')
            ->setCheckedAt(new \DateTime($checkedAt));
    }

    private function createProvider(array $results): HealthCheckAlertProvider
    {
        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findLatestPerUrlAndKind')->willReturn($results);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/health-check');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => strtr($id, $parameters)
        );

        return new HealthCheckAlertProvider($repository, $urlGenerator, $translator);
    }

    public function testNoAlertWhenNothingHasEverBeenChecked(): void
    {
        $this->assertSame([], $this->createProvider([])->getAlerts());
    }

    // An alert saying "all good" would be permanent noise on a dashboard whose alerts are meant to be acted upon
    public function testNoAlertWhenEverythingIsOk(): void
    {
        $results = [$this->createResult(HealthCheckResult::STATUS_OK), $this->createResult(HealthCheckResult::STATUS_SKIPPED)];

        $this->assertSame([], $this->createProvider($results)->getAlerts());
    }

    public function testAnErrorRaisesADangerAlertCountingTheErrorsOnly(): void
    {
        $results = [
            $this->createResult(HealthCheckResult::STATUS_ERROR),
            $this->createResult(HealthCheckResult::STATUS_ERROR),
            $this->createResult(HealthCheckResult::STATUS_WARNING),
            $this->createResult(HealthCheckResult::STATUS_OK),
        ];

        $alerts = $this->createProvider($results)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.health_check_alert_errors', strtr($alerts[0]['label'], ['2' => '%count%']));
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
        $this->assertSame('/management/health-check', $alerts[0]['url']);
    }

    public function testWarningsAloneRaiseAWarningAlert(): void
    {
        $results = [$this->createResult(HealthCheckResult::STATUS_WARNING), $this->createResult(HealthCheckResult::STATUS_OK)];

        $alerts = $this->createProvider($results)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.health_check_alert_warnings', strtr($alerts[0]['label'], ['1' => '%count%']));
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }

    // The date is what makes the alert double as the "your queued run is done" signal
    public function testTheDescriptionCarriesTheMostRecentRunDate(): void
    {
        $results = [
            $this->createResult(HealthCheckResult::STATUS_ERROR, '2026-07-20 04:00:00'),
            $this->createResult(HealthCheckResult::STATUS_OK, '2026-07-27 06:30:00'),
        ];

        $alerts = $this->createProvider($results)->getAlerts();

        $this->assertSame('description.health_check_alert', strtr($alerts[0]['description'], ['27/07/2026 06:30' => '%date%']));
    }
}
