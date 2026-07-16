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
    public function getMenuSection(): array;

    public function getMenus(): array;

    // Links to routes (not EasyAdmin CRUD controllers), merged by MenuBuilder into a single "links" section; return [] if none.
    // Each entry needs either a 'name' (a route name, resolved to its real URL through the app's own
    // router - the usual case) or a 'url' (a literal, already-absolute URL used as-is, no route
    // resolution at all - for a link a provider wants fixed/directly editable, e.g. a specific known
    // deployment). 'url' takes precedence when both are set.
    // Each entry may set an optional 'role' key (e.g. 'ROLE_EDITOR') to hide the link from users lacking it -
    // omit it for links to routes with no access restriction of their own (e.g. a public page). Each entry
    // may also set an optional 'target' key (e.g. '_blank') for a link leaving the admin entirely (e.g. a
    // public showcase page) - MenuBuilder shows an external-link glyph automatically for any such link, and
    // (for a 'name'-based link only) resolves it to a full absolute URL instead of a relative path.
    public function getLinks(): array;
}