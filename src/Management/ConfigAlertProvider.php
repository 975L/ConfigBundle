<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Repository\ConfigRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

// Alerts for configs still missing a value despite being flagged with a severity
class ConfigAlertProvider implements AlertProviderInterface
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getAlerts(): array
    {
        $alerts = [];

        foreach ($this->configRepository->findRequiringAttention() as $config) {
            $alerts[] = [
                'label' => $config->getLabel(),
                'description' => $config->getDescription(),
                'severity' => $config->getSeverity(),
                'url' => $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(ConfigCrudController::class)
                    ->setAction(Action::EDIT)
                    ->setEntityId($config->getId())
                    ->generateUrl(),
            ];
        }

        return $alerts;
    }
}
