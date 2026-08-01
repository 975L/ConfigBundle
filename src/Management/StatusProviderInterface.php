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
 * To add a StatusProvider, you need to: add the Management folder in the src/ folder of your bundle; create a class implementing StatusProviderInterface; ConfigBundle will automatically detect it and add its data to the report sent by the c975l:status:send command (see StatusReportBuilder), under the key it declares. Meant for the few numbers a maintainer wants to see across several sites at once - an order backlog, a moderation queue - never for the content itself: the report is sent outside the site, so it must stay small and hold nothing sensitive.
 */
interface StatusProviderInterface
{
    /**
     * Stable identifier for this provider's data (eg. "shop", "book"), used as the key it occupies in the report's "extra" section.
     */
    public function getStatusKey(): string;

    /**
     * Whatever the provider wants to report, as a json-serializable array. Keep it to counts and dates: it travels over the network, and a receiver has no way to know a key is confidential.
     *
     * @return array<string, mixed>
     */
    public function getStatusData(): array;
}
