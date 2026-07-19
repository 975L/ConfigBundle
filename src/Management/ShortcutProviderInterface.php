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
    // Each entry: ['label' => string, 'icon' => string, 'route' => string, 'active' => bool]. 'route' must accept a POST request and check its own CSRF token (csrf_token(route) in the template); 'active' reflects an on/off state to style the button accordingly, one-shot actions can always return false
    public function getShortcuts(): array;
}
