<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Alerts on important (non-spam) failed Messenger messages, with more detail shown to ROLE_SUPER_ADMIN than to ROLE_ADMIN, who cannot act on it (see MessengerFailedController)
class MessengerAlertProvider implements AlertProviderInterface
{
    public function __construct(
        private readonly MessengerFailedMessageService $messengerFailedMessageService,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getAlerts(): array
    {
        $count = $this->messengerFailedMessageService->countImportant();
        if (0 === $count) {
            return [];
        }

        $url = $this->urlGenerator->generate('management_config_messenger_failed');

        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return [[
                'label' => $this->translator->trans('label.messenger_alert_super_admin', ['%count%' => $count], 'config'),
                'description' => $this->translator->trans('description.messenger_alert_super_admin', [], 'config'),
                'severity' => Config::SEVERITY_DANGER,
                'url' => $url,
            ]];
        }

        return [[
            'label' => $this->translator->trans('label.messenger_alert_admin', [], 'config'),
            'description' => null,
            'severity' => Config::SEVERITY_WARNING,
            'url' => $url,
        ]];
    }
}
