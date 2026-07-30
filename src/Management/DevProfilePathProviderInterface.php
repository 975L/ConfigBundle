<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

/**
 * To declare the paths c975l:dev-profile:run has to profile, you need to: add the Management folder in the src/ folder of your bundle; create a class implementing DevProfilePathProviderInterface, marked #[When('dev')]; ConfigBundle will automatically detect it and run its paths through the kernel (see DevProfileRunner), same auto-detection mechanism as HealthCheckProviderInterface. Local paths only: unlike the health check, which fetches the live site over HTTP at "site-url" (the production site, even when run from a dev machine), this profiles the very code and database the developer has in front of them.
 */
interface DevProfilePathProviderInterface
{
    /**
     * One entry per path to profile. The path is local and absolute ("/", "/pages/contact"), never a full url - it's handed straight to the kernel, no HTTP request and no host involved.
     *
     * @return list<array{path: string, label?: ?string}>
     */
    public function getPaths(): array;
}
