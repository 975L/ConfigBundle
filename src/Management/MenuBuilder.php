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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// Merges the menus/links contributed by every MenuProvider so bundles sharing the same section appear as one group, with items sorted alphabetically
class MenuBuilder
{
    // Fixed label for the single, merged "links" section, regardless of which bundle contributes links
    private const LINKS_SECTION_LABEL = 'label.links';
    private const LINKS_SECTION_TRANSLATION_DOMAIN = 'config';

    public function __construct(
        private readonly iterable $menuProviders,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // Yields the EasyAdmin menu items, grouped by section and sorted alphabetically
    public function getMenuItems(): iterable
    {
        foreach ($this->getGroupedMenus() as $section) {
            yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));

            foreach ($section['items'] as $menu) {
                yield MenuItem::linkTo($menu['controller'], new TranslatableMessage($menu['label'], [], $menu['translation_domain']), $menu['icon'])->setPermission($this->configService->get('site-role-admin'));
            }
        }

        $links = $this->getLinks();
        if ([] !== $links) {
            yield MenuItem::section(new TranslatableMessage(self::LINKS_SECTION_LABEL, [], self::LINKS_SECTION_TRANSLATION_DOMAIN));

            foreach ($links as $link) {
                // "url" (a literal, already-absolute URL) takes precedence when a provider sets it - for a link a provider wants fixed/directly editable rather than derived from a route (e.g. pointing at a specific known deployment on purpose). Otherwise "name" is a route name resolved via linkToUrl()+the plain router, not linkToRoute(): the latter resolves through EasyAdmin's own AdminUrlGenerator (see MenuFactory), which assumes the route is one of the dashboard's own registered actions and wraps it as "/management?routeName=...&..." - correct only for a route reachable *through* the dashboard. A link to a plain, unrelated route (e.g. a consuming app's own public page) needs its real path instead, which the plain router already gives via generate() - this works uniformly for both cases, since a dashboard-registered route's own real path resolves correctly through generate() too. A route-based link with a "target" (see below) is leaving the admin entirely - resolved as a full absolute URL (scheme+host), not just a path, since it's meant to stand on its own once opened in a new tab. Still generated from the current request each time, never hardcoded, so it stays correct across dev/staging/prod or any future domain change - only the route itself is a fixed reference, the same way a same-tab link already works.
                if (isset($link['url'])) {
                    $linkUrl = $link['url'];
                } else {
                    $referenceType = isset($link['target'])
                        ? UrlGeneratorInterface::ABSOLUTE_URL
                        : UrlGeneratorInterface::ABSOLUTE_PATH;
                    $linkUrl = $this->urlGenerator->generate($link['name'], [], $referenceType);
                }
                $item = MenuItem::linkToUrl(
                    new TranslatableMessage($link['label'], [], $link['translation_domain']),
                    $link['icon'],
                    $linkUrl
                );

                // Optional per-link role (see MenuProviderInterface::getLinks()) - unlike CRUD menus above, links can point to routes needing anything from a public page to an editor-only one, so there's no single sensible default; providers not needing gating simply omit the key
                if (isset($link['role'])) {
                    $item->setPermission($link['role']);
                }

                // Optional link target (e.g. '_blank' for a link leaving the admin - see MenuProviderInterface::getLinks()) - management.scss shows an external-link glyph automatically for any target="_blank" link, no per-provider styling needed
                if (isset($link['target'])) {
                    $item->setLinkTarget($link['target']);
                }

                yield $item;
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
