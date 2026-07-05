<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface AlertProviderInterface
{
    // Each entry: ['label' => string, 'description' => ?string, 'severity' => Config::SEVERITY_*, 'url' => string]
    public function getAlerts(): array;
}
