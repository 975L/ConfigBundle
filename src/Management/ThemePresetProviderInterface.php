<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface ThemePresetProviderInterface
{
    // Each entry: id => ['label' => string, 'domain' => string, 'stylesheet' => ?string] - 'domain' is the translation domain owning 'label' (the provider's own bundle, not necessarily 'config'). 'stylesheet' (Config::GROUP_THEME slug 'theme-stylesheet') is the only config a preset ever writes (see ThemeCrudController::applyPreset()) - colors and fonts stay entirely admin-owned, a preset never overwrites them. 'previewUrl' is an optional preview link - a callable(): string returning the URL, NOT an already-generated string: ThemePresetRegistry is built as a constructor dependency while EasyAdmin is still enumerating routes (to register its own dynamic ones), so eagerly calling the router here deadlocks - see SiteThemePresetProvider
    public function getPresets(): array;
}
