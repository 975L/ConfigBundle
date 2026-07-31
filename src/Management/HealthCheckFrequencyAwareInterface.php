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
 * Implemented by a HealthCheckProvider whose cadence cannot be written on its class, because one class is registered several times over - one instance per source (see SiteBundle's DeclaredUrlsHealthCheckProvider, registered once per SitemapProviderInterface). Everything else states its cadence with the AsHealthCheck attribute and has no reason to implement this: HealthCheckRunner asks the instance first, then falls back to the class attribute, then to weekly.
 */
interface HealthCheckFrequencyAwareInterface
{
    /**
     * One of AsHealthCheck::FREQUENCIES.
     */
    public function getFrequency(): string;
}
