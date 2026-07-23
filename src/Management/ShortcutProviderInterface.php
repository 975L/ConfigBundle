<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface ShortcutProviderInterface
{
    // Shared categories, so a provider grouping its shortcut alongside another bundle's (e.g. c975L\SiteBundle's export tables shortcut and c975L\ConfigBundle's own SQL/sync exports) references the same constant rather than a hand-typed label that could drift
    public const CATEGORY_EXPORT = ['label' => 'label.shortcuts_category_export', 'translation_domain' => 'config'];
    public const CATEGORY_MAINTENANCE = ['label' => 'label.shortcuts_category_maintenance', 'translation_domain' => 'config'];
    public const CATEGORY_SITE = ['label' => 'label.shortcuts_category_site', 'translation_domain' => 'config'];

    // Each entry: ['label' => string, 'icon' => string, 'route' => string, 'active' => bool, 'role' => string, 'category' => array]. 'route' must accept a POST request and check its own CSRF token (csrf_token(route) in the template); 'active' reflects an on/off state (e.g. a toggled maintenance mode), one-shot actions can always return false. 'role' is optional - omit it for a shortcut with no access restriction of its own, set it (e.g. 'ROLE_SUPER_ADMIN') to hide the tile from users lacking it. 'category' is optional too (one of the CATEGORY_* constants above, or a custom ['label' => string, 'translation_domain' => string] pair, both untranslated - translated once by ShortcutBuilder/the template): shortcuts sharing the same one (across bundles) are grouped under one heading; omit it to fall into the generic "Other" category.
    public function getShortcuts(): array;
}
