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

    // Links to routes (not EasyAdmin CRUD controllers), merged by MenuBuilder into a single "links" section; return [] if none
    public function getLinks(): array;
}