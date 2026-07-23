<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface EssentialActionProviderInterface
{
    // Each entry: ['slug' => string, 'label' => string, 'description' => ?string, 'translation_domain' => string, 'url' => string, 'isDone' => bool, 'order' => int]. Not a one-time onboarding wizard - a permanent quick-access entry point to the handful of settings every site needs, always linking straight to its screen so a value can be reviewed or redone at any time, "isDone" only drives the status icon. "order" decides the checklist's display order across every provider (low to high) - unlike menus/alerts, these have a deliberate sequence, not an alphabetical one.
    public function getEssentialActions(): array;
}
