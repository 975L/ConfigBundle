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
use c975L\ConfigBundle\Management\ConfigGuidedProjectProvider;
use c975L\ConfigBundle\Management\ConfigShortcutProvider;
use c975L\ConfigBundle\Management\MenuProvider;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\UiBundle\Repository\FormRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// This bundle's own management targets, checked by the very case it ships for the others (see ManagementTargetsTestCase) - so a change breaking a satellite bundle's checks breaks this one first
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        return [
            new MenuProvider($this->createConfigService()),
            new ConfigShortcutProvider($this->createTranslator(), $this->createConfigService(), $this->createStub(FormRepository::class)),
            new ConfigEssentialActionProvider($this->createConfigService(), $this->adminUrlGenerator()),
            new ConfigGuidedProjectProvider($this->adminUrlGenerator(), $this->urlGenerator()),
        ];
    }

    // Anything but "site-url" answers a role, the only two shapes these providers read - a configured site url adds the sidebar's link to the site itself, the one link carrying an url rather than a route
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key) => 'site-url' === $key ? 'https://example.com' : 'ROLE_ADMIN'
        );

        return $configService;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
