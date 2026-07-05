<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigShortcutController;
use c975L\ConfigBundle\Controller\Management\MaintenanceShortcutController;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// To add a ShortcutProvider, you need to:
// add the Management Folder in the src/ folder of your bundle
// Create a class implementing ShortcutProviderInterface, providing a getShortcuts() method (label already translated, like AlertProviderInterface)
// Each shortcut's 'route' must accept a POST request and check its own CSRF token (see ConfigShortcutController)
// add the declaration of the Management folder in the services.yaml file of your bundle
// ConfigBundle will automatically detect the ShortcutProvider and add it to the dashboard

class ConfigShortcutProvider implements ShortcutProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getShortcuts(): array
    {
        $maintenanceEnabled = (bool) $this->configService->get('site-maintenance');

        return [
            [
                'label' => $this->translator->trans('label.config_clear_cache', [], 'config'),
                'icon' => 'fa fa-broom',
                'route' => ConfigShortcutController::CLEAR_CACHE_ROUTE,
                'active' => false,
            ],
            [
                'label' => $this->translator->trans(
                    $maintenanceEnabled ? 'label.maintenance_disable' : 'label.maintenance_enable',
                    [],
                    'config',
                ),
                'icon' => 'fa fa-wrench',
                'route' => MaintenanceShortcutController::TOGGLE_ROUTE,
                'active' => $maintenanceEnabled,
            ],
        ];
    }
}
