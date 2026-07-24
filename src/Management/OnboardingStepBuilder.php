<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Builds the guided-tour steps from MenuBuilder's already-aggregated menus/links, covering every entry so the tour reflects the whole sidebar - a link needing a role the current user lacks (see MenuProviderInterface::getLinks()) is skipped though, since its sidebar target isn't even rendered for them. 'description' (see MenuProviderInterface) stays optional - a step for an item without one just shows its label, no explanatory text. Each step carries the item's own resolved URL rather than an invented id/slug: assets/js/onboarding-tour.js matches it against the sidebar's own `a[href]` (no EasyAdmin template override needed, see Sidebar/Item.html.twig), the same deterministic url-generation approach already used by ConfigEditUrlResolver
class OnboardingStepBuilder
{
    public function __construct(
        private readonly MenuBuilder $menuBuilder,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    // [{url, label, description}], one per menu/link (links needing a role the user lacks excluded) - menus follow MenuBuilder::getOrderedMenus(), the same essential-then-advanced-across-sections order the sidebar itself renders, with links (already alphabetical/pinned-last, matching their own sidebar section) appended after
    public function getSteps(): array
    {
        $steps = [];

        foreach ($this->menuBuilder->getOrderedMenus() as $menu) {
            $url = $this->adminUrlGenerator->unsetAll()
                ->setController($menu['controller'])
                ->setAction(Action::INDEX)
                ->generateUrl();

            $steps[] = $this->buildStep($url, $menu);
        }

        foreach ($this->menuBuilder->getLinks() as $link) {
            if (isset($link['role']) && !$this->security->isGranted($link['role'])) {
                continue;
            }

            $referenceType = isset($link['target']) ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;
            $url = $link['url'] ?? $this->urlGenerator->generate($link['name'], [], $referenceType);

            $steps[] = $this->buildStep($url, $link);
        }

        return $steps;
    }

    // Same label/description resolution as MenuBuilder::getMenuItems() for a link (see its 'label_parameters' handling) - kept in sync by hand since both operate on the same MenuProviderInterface item shape
    private function buildStep(string $url, array $item): array
    {
        return [
            'url' => $url,
            'label' => $this->translator->trans($item['label'], $item['label_parameters'] ?? [], $item['translation_domain']),
            'description' => empty($item['description']) ? '' : $this->translator->trans($item['description'], [], $item['translation_domain']),
        ];
    }
}
