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
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// This bundle's own guided projects, opening the order sequence the satellite bundles continue (SiteBundle picks up at 50, same as the essential actions do). Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see assets/js/guided-project.js resume())
class ConfigGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getGuidedProjects(): array
    {
        return [
            $this->settingsProject(),
            $this->healthCheckProject(),
            $this->maintenanceProject(),
        ];
    }

    // Where every setting of every bundle lives, which is the first thing to find on a back office one doesn't know
    private function settingsProject(): array
    {
        return [
            'slug' => 'config-settings',
            'label' => 'label.guided_project_config_settings',
            'description' => 'description.guided_project_config_settings',
            'translation_domain' => 'config',
            'order' => 10,
            'steps' => [
                [
                    'label' => 'label.guided_step_config_settings_open',
                    'description' => 'description.guided_step_config_settings_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(ConfigCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_config_settings_group',
                    'description' => 'description.guided_step_config_settings_group',
                    'highlight' => 'table tbody tr:first-child a',
                ],
                [
                    'label' => 'label.guided_step_config_settings_entry',
                    'description' => 'description.guided_step_config_settings_entry',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_config_settings_value',
                    'description' => 'description.guided_step_config_settings_value',
                    'highlight' => '#Config_value',
                ],
                [
                    'label' => 'label.guided_step_config_settings_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_config_settings_alerts',
                    'description' => 'description.guided_step_config_settings_alerts',
                ],
            ],
        ];
    }

    // What the site answers to a visitor, checked from the outside rather than taken on trust
    private function healthCheckProject(): array
    {
        return [
            'slug' => 'config-health-check',
            'label' => 'label.guided_project_config_health_check',
            'description' => 'description.guided_project_config_health_check',
            'translation_domain' => 'config',
            'order' => 20,
            'steps' => [
                [
                    'label' => 'label.guided_step_config_health_check_open',
                    'description' => 'description.guided_step_config_health_check_open',
                    'url' => $this->urlGenerator->generate('management_health_check_index'),
                ],
                [
                    'label' => 'label.guided_step_config_health_check_run',
                    'description' => 'description.guided_step_config_health_check_run',
                    'highlight' => 'form[action$="/health-check/run"] button',
                ],
                [
                    'label' => 'label.guided_step_config_health_check_read',
                    'description' => 'description.guided_step_config_health_check_read',
                    'highlight' => '[data-controller="health-check-table"]',
                ],
                [
                    'label' => 'label.guided_step_config_health_check_fix',
                    'description' => 'description.guided_step_config_health_check_fix',
                ],
                [
                    'label' => 'label.guided_step_config_health_check_again',
                    'description' => 'description.guided_step_config_health_check_again',
                ],
            ],
        ];
    }

    // Rehearsed on a quiet day rather than discovered on the day it's needed
    private function maintenanceProject(): array
    {
        return [
            'slug' => 'config-maintenance',
            'label' => 'label.guided_project_config_maintenance',
            'description' => 'description.guided_project_config_maintenance',
            'translation_domain' => 'config',
            'order' => 30,
            'steps' => [
                [
                    'label' => 'label.guided_step_config_maintenance_open',
                    'description' => 'description.guided_step_config_maintenance_open',
                    'url' => $this->urlGenerator->generate('management'),
                ],
                [
                    'label' => 'label.guided_step_config_maintenance_enable',
                    'description' => 'description.guided_step_config_maintenance_enable',
                    'highlight' => 'form[action$="/config/maintenance-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_config_maintenance_check',
                    'description' => 'description.guided_step_config_maintenance_check',
                ],
                [
                    'label' => 'label.guided_step_config_maintenance_disable',
                    'description' => 'description.guided_step_config_maintenance_disable',
                    'highlight' => 'form[action$="/config/maintenance-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_config_maintenance_done',
                    'description' => 'description.guided_step_config_maintenance_done',
                ],
            ],
        ];
    }
}
