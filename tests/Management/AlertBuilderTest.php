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
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class AlertBuilderTest extends TestCase
{
    // Builds an AlertProviderInterface double returning the given alerts
    private function createProvider(array $alerts): AlertProviderInterface
    {
        $provider = $this->createStub(AlertProviderInterface::class);
        $provider->method('getAlerts')->willReturn($alerts);

        return $provider;
    }

    private function createAlert(string $label, string $severity, ?string $role = null): array
    {
        $alert = ['label' => $label, 'description' => null, 'severity' => $severity, 'url' => '/x'];

        return null === $role ? $alert : $alert + ['role' => $role];
    }

    // $grantedRoles: what the current user holds, every other role tested being denied
    private function createBuilder(array $providers, array $grantedRoles = ['ROLE_SUPER_ADMIN']): AlertBuilder
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $role): bool => \in_array($role, $grantedRoles, true)
        );

        return new AlertBuilder($providers, $security);
    }

    public function testGetAlertsMergesProvidersAndGroupsBySeverity(): void
    {
        $providerA = $this->createProvider([$this->createAlert('a', Config::SEVERITY_DANGER)]);
        $providerB = $this->createProvider([
            $this->createAlert('b', Config::SEVERITY_INFO),
            $this->createAlert('c', Config::SEVERITY_WARNING),
        ]);
        $builder = $this->createBuilder([$providerA, $providerB]);

        $grouped = $builder->getAlerts();

        $this->assertSame(['a'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
        $this->assertSame(['c'], array_column($grouped[Config::SEVERITY_WARNING], 'label'));
        $this->assertSame(['b'], array_column($grouped[Config::SEVERITY_INFO], 'label'));
    }

    public function testGetAlertsReturnsAllSeverityKeysEvenWhenEmpty(): void
    {
        $builder = $this->createBuilder([]);

        $grouped = $builder->getAlerts();

        $this->assertSame([
            Config::SEVERITY_DANGER => [],
            Config::SEVERITY_WARNING => [],
            Config::SEVERITY_INFO => [],
        ], $grouped);
    }

    // An alert whose configs are themselves restricted must not reach an admin who can do nothing about it (see BackupAlertProvider)
    public function testGetAlertsDropsAnAlertWhoseRoleTheUserLacks(): void
    {
        $provider = $this->createProvider([
            $this->createAlert('super-only', Config::SEVERITY_DANGER, 'ROLE_SUPER_ADMIN'),
            $this->createAlert('everyone', Config::SEVERITY_DANGER),
        ]);

        $grouped = $this->createBuilder([$provider], ['ROLE_ADMIN'])->getAlerts();

        $this->assertSame(['everyone'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
    }

    public function testGetAlertsKeepsAnAlertWhoseRoleTheUserHolds(): void
    {
        $provider = $this->createProvider([$this->createAlert('super-only', Config::SEVERITY_DANGER, 'ROLE_SUPER_ADMIN')]);

        $grouped = $this->createBuilder([$provider])->getAlerts();

        $this->assertSame(['super-only'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
    }

    public function testGroupBySeverityGroupsAFlatAlertList(): void
    {
        $alerts = [
            $this->createAlert('warn-one', Config::SEVERITY_WARNING),
            $this->createAlert('danger-one', Config::SEVERITY_DANGER),
        ];

        $grouped = AlertBuilder::groupBySeverity($alerts);

        $this->assertSame(['danger-one'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
        $this->assertSame(['warn-one'], array_column($grouped[Config::SEVERITY_WARNING], 'label'));
        $this->assertSame([], $grouped[Config::SEVERITY_INFO]);
    }
}
