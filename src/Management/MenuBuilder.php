<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// Merges the menus/links contributed by every MenuProvider so bundles sharing
// the same section appear as one group, with items sorted alphabetically
class MenuBuilder
{
    // Fixed label for the single, merged "links" section, regardless of which bundle contributes links
    private const LINKS_SECTION_LABEL = 'label.links';
    private const LINKS_SECTION_TRANSLATION_DOMAIN = 'config';

    public function __construct(
        private readonly iterable $menuProviders,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Yields the EasyAdmin menu items, grouped by section and sorted alphabetically
    public function getMenuItems(): iterable
    {
        foreach ($this->getGroupedMenus() as $section) {
            yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));

            foreach ($section['items'] as $menu) {
                yield MenuItem::linkTo($menu['controller'], new TranslatableMessage($menu['label'], [], $menu['translation_domain']), $menu['icon'])->setPermission($this->configService->get('site-role-needed'));
            }
        }

        $links = $this->getLinks();
        if ([] !== $links) {
            yield MenuItem::section(new TranslatableMessage(self::LINKS_SECTION_LABEL, [], self::LINKS_SECTION_TRANSLATION_DOMAIN));

            foreach ($links as $link) {
                yield MenuItem::linkToRoute(new TranslatableMessage($link['label'], [], $link['translation_domain']), $link['icon'], $link['name'])->setLinkTarget('_blank');
            }
        }
    }

    // Returns all menus, merged across providers and sorted alphabetically
    public function getMenus(): array
    {
        return $this->sortAlphabetically(ProviderMerger::merge($this->menuProviders, fn (MenuProviderInterface $provider) => $provider->getMenus()));
    }

    // Returns all links, merged across providers and sorted alphabetically
    public function getLinks(): array
    {
        return $this->sortAlphabetically(ProviderMerger::merge($this->menuProviders, fn (MenuProviderInterface $provider) => $provider->getLinks()));
    }

    // Groups the menus by section, so providers sharing the same section (label + translation_domain) are merged
    private function getGroupedMenus(): array
    {
        $sections = [];
        foreach ($this->menuProviders as $provider) {
            $section = $provider->getMenuSection();
            $key = $section['translation_domain'] . '.' . $section['label'];
            $sections[$key] ??= $section + ['items' => []];
            $sections[$key]['items'] = array_merge($sections[$key]['items'], $provider->getMenus());
        }

        foreach ($sections as &$section) {
            $section['items'] = $this->sortAlphabetically($section['items']);
        }

        return $sections;
    }

    // Sorts an array of menus/links by their translated label, keeping their keys
    private function sortAlphabetically(array $items): array
    {
        uasort($items, fn (array $a, array $b) => strcasecmp(
            $this->translator->trans($a['label'], [], $a['translation_domain']),
            $this->translator->trans($b['label'], [], $b['translation_domain']),
        ));

        return $items;
    }
}
