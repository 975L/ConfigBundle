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
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// Merges the menus/links contributed by every MenuProvider so bundles sharing the same section appear as one group, with items sorted alphabetically
class MenuBuilder
{
    // Fixed label for the single, merged "links" section, regardless of which bundle contributes links
    private const LINKS_SECTION_LABEL = 'label.links';
    private const LINKS_SECTION_TRANSLATION_DOMAIN = 'config';

    // Fixed label for the single, merged "Avancé" submenu, regardless of which bundle contributes an advanced-tier section
    private const ADVANCED_SUBMENU_LABEL = 'label.menu_advanced';
    private const ADVANCED_SUBMENU_TRANSLATION_DOMAIN = 'config';

    public function __construct(
        private readonly iterable $menuProviders,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // Items opting into the 'advanced' tier are collected into one collapsed submenu, rendered last
    // Tier is resolved per item first, several providers commonly sharing one section
    public function getMenuItems(): iterable
    {
        $advancedItems = [];

        foreach ($this->getGroupedMenus() as $section) {
            $essentialItems = [];
            foreach ($section['items'] as $menu) {
                $item = MenuItem::linkTo($menu['controller'], new TranslatableMessage($menu['label'], [], $menu['translation_domain']), $menu['icon'])->setPermission($this->configService->get('site-role-admin'));

                if ('advanced' === self::tier($menu, $section)) {
                    $advancedItems[] = $item;
                } else {
                    $essentialItems[] = $item;
                }
            }

            // Skip a section header that would sit above zero items - every one of its items moved to "Avancé" (a section contributing no items at all still gets its header, unchanged from before - see getMenuItemsOnlyAppendsALinksSectionWhenLinksExist)
            if ([] === $essentialItems && [] !== $section['items']) {
                continue;
            }

            yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));
            yield from $essentialItems;
        }

        // Links opting into 'advanced' join the same submenu as the CRUD items, which is why they are resolved
        // before it is yielded rather than inside getLinkItems() below
        $essentialLinks = [];
        foreach ($this->getLinks() as $link) {
            $item = $this->buildLinkItem($link);

            if ('advanced' === ($link['tier'] ?? 'essential')) {
                $advancedItems[] = $item;
            } else {
                $essentialLinks[] = $item;
            }
        }

        if ([] !== $advancedItems) {
            yield MenuItem::subMenu(new TranslatableMessage(self::ADVANCED_SUBMENU_LABEL, [], self::ADVANCED_SUBMENU_TRANSLATION_DOMAIN))->setSubItems($advancedItems);
        }

        yield from $this->getLinkItems($essentialLinks);
    }

    // An item's own tier wins over its section's default
    private static function tier(array $menu, array $section): string
    {
        return $menu['tier'] ?? $section['tier'] ?? 'essential';
    }

    // The "Liens" section, rendered last and only when at least one link stayed out of the "Avancé" submenu
    private function getLinkItems(array $links): iterable
    {
        if ([] === $links) {
            return;
        }

        yield MenuItem::section(new TranslatableMessage(self::LINKS_SECTION_LABEL, [], self::LINKS_SECTION_TRANSLATION_DOMAIN));

        yield from $links;
    }

    private function buildLinkItem(array $link): MenuItemInterface
    {
        $item = MenuItem::linkToUrl(
            new TranslatableMessage($link['label'], $link['label_parameters'] ?? [], $link['translation_domain']),
            $link['icon'],
            $this->linkUrl($link)
        );

        // Optional per-link role (see MenuProviderInterface::getLinks()) - unlike CRUD menus above, links can point to routes needing anything from a public page to an editor-only one, so there's no single sensible default; providers not needing gating simply omit the key
        if (isset($link['role'])) {
            $item->setPermission($link['role']);
        }

        // Optional link target (e.g. '_blank' for a link leaving the admin - see MenuProviderInterface::getLinks()) - management.scss shows an external-link glyph automatically for any target="_blank" link, no per-provider styling needed
        if (isset($link['target'])) {
            $item->setLinkTarget($link['target']);
        }

        return $item;
    }

    // "url" (a literal, already-absolute URL) takes precedence when a provider sets it - for a link a provider wants fixed/directly editable rather than derived from a route (e.g. pointing at a specific known deployment on purpose). Otherwise "name" is a route name resolved via linkToUrl()+the plain router, not linkToRoute(): the latter resolves through EasyAdmin's own AdminUrlGenerator (see MenuFactory), which assumes the route is one of the dashboard's own registered actions and wraps it as "/management?routeName=...&..." - correct only for a route reachable *through* the dashboard. A link to a plain, unrelated route (e.g. a consuming app's own public page) needs its real path instead, which the plain router already gives via generate() - this works uniformly for both cases, since a dashboard-registered route's own real path resolves correctly through generate() too. A route-based link with a "target" is leaving the admin entirely - resolved as a full absolute URL (scheme+host), not just a path, since it's meant to stand on its own once opened in a new tab. Still generated from the current request each time, never hardcoded, so it stays correct across dev/staging/prod or any future domain change - only the route itself is a fixed reference, the same way a same-tab link already works.
    private function linkUrl(array $link): string
    {
        if (isset($link['url'])) {
            return $link['url'];
        }

        $referenceType = isset($link['target'])
            ? UrlGeneratorInterface::ABSOLUTE_URL
            : UrlGeneratorInterface::ABSOLUTE_PATH;

        return $this->urlGenerator->generate($link['name'], [], $referenceType);
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

    // Every menu, flattened into the same essential-then-advanced-across-sections order the sidebar itself renders (see getMenuItems()) - every section's essential items first (in provider/section order, alphabetical within each), then every section's advanced items grouped together at the end (the collapsed "Avancé" submenu), rather than getMenus()'s plain alphabetical merge across all of them. Used by OnboardingStepBuilder so tour steps walk the sidebar in the order a user actually sees it
    public function getOrderedMenus(): array
    {
        $essential = [];
        $advanced = [];

        foreach ($this->getGroupedMenus() as $section) {
            foreach ($section['items'] as $key => $menu) {
                if ('advanced' === ($menu['tier'] ?? $section['tier'] ?? 'essential')) {
                    $advanced[$key] = $menu;
                } else {
                    $essential[$key] = $menu;
                }
            }
        }

        return $essential + $advanced;
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

    // Sorts an array of menus/links by their translated label, keeping their keys - an item can set 'pinned' => true (links only, see MenuProviderInterface::getLinks()) to always sort after every non-pinned item, regardless of label (e.g. a "visit the site" link meant to stay at the very bottom of the links section)
    private function sortAlphabetically(array $items): array
    {
        uasort($items, function (array $a, array $b) {
            $pinnedA = $a['pinned'] ?? false;
            $pinnedB = $b['pinned'] ?? false;
            if ($pinnedA !== $pinnedB) {
                return $pinnedA <=> $pinnedB;
            }

            return strcasecmp(
                $this->translator->trans($a['label'], [], $a['translation_domain']),
                $this->translator->trans($b['label'], [], $b['translation_domain']),
            );
        });

        return $items;
    }
}
