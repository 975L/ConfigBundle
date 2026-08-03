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
use c975L\ConfigBundle\Management\MessengerAlertProvider;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessengerAlertProviderTest extends TestCase
{
    private function createProvider(int $importantCount, bool $isSuperAdmin): MessengerAlertProvider
    {
        $service = $this->createStub(MessengerFailedMessageService::class);
        $service->method('countImportant')->willReturn($importantCount);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($isSuperAdmin);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/messenger-failed');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new MessengerAlertProvider($service, $security, $urlGenerator, $translator);
    }

    public function testNoAlertWhenNothingImportantFailed(): void
    {
        $this->assertSame([], $this->createProvider(0, true)->getAlerts());
    }

    public function testASuperAdminGetsTheDetailedAlert(): void
    {
        $alerts = $this->createProvider(3, true)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.messenger_alert_super_admin', $alerts[0]['label']);
        $this->assertSame('description.messenger_alert_super_admin', $alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
        $this->assertSame('/management/messenger-failed', $alerts[0]['url']);
    }

    // A plain admin can't act on a failed message (see MessengerFailedController), so they only get told it's been signaled
    public function testALesserAdminGetsTheReassuringAlertWithoutDetail(): void
    {
        $alerts = $this->createProvider(3, false)->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.messenger_alert_admin', $alerts[0]['label']);
        $this->assertNull($alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
    }
}
