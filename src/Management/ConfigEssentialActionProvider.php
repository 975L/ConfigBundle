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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

// Core essential actions every site needs regardless of which bundles are installed - each one links to
// the Config grid already scoped to its group (ConfigCrudController's own "group" query param, see
// currentGroup()), not a raw config list, so a value can be reviewed or changed at any time, not just on
// a first visit. Other bundles add their own actions the same way, implementing this same interface -
// this is the only provider ConfigBundle ships itself.
class ConfigEssentialActionProvider implements EssentialActionProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getEssentialActions(): array
    {
        return [
            $this->action('identity', 'label.essential_action_identity', 'description.essential_action_identity', 'general', 10, [self::class, 'identityDone']),
            $this->action('legal', 'label.essential_action_legal', 'description.essential_action_legal', 'legal', 20, [self::class, 'legalDone']),
            $this->action('email', 'label.essential_action_email', 'description.essential_action_email', 'email', 30, [self::class, 'emailDone']),
            $this->action('roles', 'label.essential_action_roles', 'description.essential_action_roles', 'security', 40, [self::class, 'rolesDone']),
        ];
    }

    private function action(string $slug, string $label, string $description, string $group, int $order, callable $isDone): array
    {
        return [
            'slug' => $slug,
            'label' => $label,
            'description' => $description,
            'translation_domain' => 'config',
            'url' => $this->groupUrl($group),
            'isDone' => $isDone($this->configService),
            'order' => $order,
        ];
    }

    private function groupUrl(string $group): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(ConfigCrudController::class)
            ->setAction(Action::INDEX)
            ->set('group', $group)
            ->generateUrl();
    }

    private static function identityDone(ConfigServiceInterface $configService): bool
    {
        return (bool) $configService->get('site-name');
    }

    private static function legalDone(ConfigServiceInterface $configService): bool
    {
        return (bool) $configService->get('site-contact-email') && (bool) $configService->get('site-director');
    }

    private static function emailDone(ConfigServiceInterface $configService): bool
    {
        return (bool) $configService->get('email-from') && (bool) $configService->get('email-to');
    }

    private static function rolesDone(ConfigServiceInterface $configService): bool
    {
        return (bool) $configService->get('user-roles-available');
    }
}
