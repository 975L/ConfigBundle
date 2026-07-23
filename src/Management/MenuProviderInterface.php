<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface MenuProviderInterface
{
    // ['label' => string, 'translation_domain' => string, 'tier' => 'essential'|'advanced']. 'tier' is optional - omit it (or return 'essential') for the current behavior. It only sets the *default* for this provider's own items in getMenus() below - several providers commonly share one section (e.g. ConfigBundle/SiteBundle/UiBundle all merge into the same "management" section), so a section-level 'tier' never affects another provider's items merged into the same section, and an item can still override it individually.
    public function getMenuSection(): array;

    // Each entry: ['controller' => string, 'label' => string, 'translation_domain' => string, 'icon' => string, 'tier' => 'essential'|'advanced']. 'tier' is optional, defaults to the provider's own getMenuSection() 'tier' (itself defaulting to 'essential') - set it on an individual item to move just that one to the collapsed "Avancé" submenu while its section keeps its other items at the top level (e.g. a bundle keeping "Pages" essential but tucking away "Redirections").
    public function getMenus(): array;

    // Links to routes (not EasyAdmin CRUD controllers), merged by MenuBuilder into a single "links" section; return [] if none. Each entry needs either a 'name' (a route name, resolved to its real URL through the app's own router - the usual case) or a 'url' (a literal, already-absolute URL used as-is, no route resolution at all - for a link a provider wants fixed/directly editable, e.g. a specific known deployment). 'url' takes precedence when both are set. Each entry may set an optional 'role' key (e.g. 'ROLE_EDITOR') to hide the link from users lacking it - omit it for links to routes with no access restriction of their own (e.g. a public page). Each entry may also set an optional 'target' key (e.g. '_blank') for a link leaving the admin entirely (e.g. a public showcase page) - MenuBuilder shows an external-link glyph automatically for any such link, and (for a 'name'-based link only) resolves it to a full absolute URL instead of a relative path.
    public function getLinks(): array;
}