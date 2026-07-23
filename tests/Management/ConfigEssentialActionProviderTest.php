<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ConfigEssentialActionProvider;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

class ConfigEssentialActionProviderTest extends TestCase
{
    private function createConfigService(array $values): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);

        return $service;
    }

    // Records the last "group" set on the fluent AdminUrlGenerator and echoes it back through generateUrl(), so each action's link can be checked without asserting the whole fluent call chain
    private function createAdminUrlGenerator(): AdminUrlGeneratorInterface
    {
        $lastGroup = null;
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('set')->willReturnCallback(function (string $name, $value) use ($generator, &$lastGroup) {
            if ('group' === $name) {
                $lastGroup = $value;
            }

            return $generator;
        });
        $generator->method('generateUrl')->willReturnCallback(function () use (&$lastGroup) {
            return '/config?group=' . $lastGroup;
        });

        return $generator;
    }

    public function testGetEssentialActionsReturnsFourActionsInOrder(): void
    {
        $provider = new ConfigEssentialActionProvider($this->createConfigService([]), $this->createAdminUrlGenerator());

        $actions = $provider->getEssentialActions();

        $this->assertSame(['identity', 'legal', 'email', 'roles'], array_column($actions, 'slug'));
        $this->assertSame([10, 20, 30, 40], array_column($actions, 'order'));
    }

    public function testEachActionLinksToItsGroupOnConfigCrudControllerIndex(): void
    {
        $provider = new ConfigEssentialActionProvider($this->createConfigService([]), $this->createAdminUrlGenerator());

        $actions = $provider->getEssentialActions();

        $this->assertSame('/config?group=general', $actions[0]['url']);
        $this->assertSame('/config?group=legal', $actions[1]['url']);
        $this->assertSame('/config?group=email', $actions[2]['url']);
        $this->assertSame('/config?group=security', $actions[3]['url']);
    }

    // The link is still returned even once the action is done - this is a permanent quick-access entry point, not a wizard that stops linking anywhere once complete
    public function testDoneActionStillCarriesItsUrl(): void
    {
        $provider = new ConfigEssentialActionProvider($this->createConfigService(['site-name' => 'Mon site']), $this->createAdminUrlGenerator());

        $identity = $provider->getEssentialActions()[0];

        $this->assertTrue($identity['isDone']);
        $this->assertSame('/config?group=general', $identity['url']);
    }

    public function testIdentityActionIsDoneOnlyWhenSiteNameIsSet(): void
    {
        $done = new ConfigEssentialActionProvider($this->createConfigService(['site-name' => 'Mon site']), $this->createAdminUrlGenerator());
        $notDone = new ConfigEssentialActionProvider($this->createConfigService([]), $this->createAdminUrlGenerator());

        $this->assertTrue($done->getEssentialActions()[0]['isDone']);
        $this->assertFalse($notDone->getEssentialActions()[0]['isDone']);
    }

    public function testLegalActionRequiresBothContactEmailAndDirector(): void
    {
        $onlyEmail = new ConfigEssentialActionProvider(
            $this->createConfigService(['site-contact-email' => 'a@b.c']),
            $this->createAdminUrlGenerator(),
        );
        $both = new ConfigEssentialActionProvider(
            $this->createConfigService(['site-contact-email' => 'a@b.c', 'site-director' => 'Jane']),
            $this->createAdminUrlGenerator(),
        );

        $this->assertFalse($onlyEmail->getEssentialActions()[1]['isDone']);
        $this->assertTrue($both->getEssentialActions()[1]['isDone']);
    }

    public function testEmailActionRequiresBothFromAndTo(): void
    {
        $provider = new ConfigEssentialActionProvider(
            $this->createConfigService(['email-from' => 'noreply@x.com', 'email-to' => 'admin@x.com']),
            $this->createAdminUrlGenerator(),
        );

        $this->assertTrue($provider->getEssentialActions()[2]['isDone']);
    }

    public function testRolesActionIsDoneOnlyWhenUserRolesAvailableIsSet(): void
    {
        $provider = new ConfigEssentialActionProvider(
            $this->createConfigService(['user-roles-available' => ['ROLE_ADMIN']]),
            $this->createAdminUrlGenerator(),
        );

        $this->assertTrue($provider->getEssentialActions()[3]['isDone']);
    }
}
