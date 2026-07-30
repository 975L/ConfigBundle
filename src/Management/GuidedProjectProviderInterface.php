<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

interface GuidedProjectProviderInterface
{
    /**
     * Unlike an essential action, a project walks the user through a real task to carry out (create a page, add a block, put it in a menu) - so it carries no "isDone" and nothing is ever derived from the site's own data: it's a replayable exercise, still worth following on a site already holding the content it teaches to create, and still worth replaying once done. "order" decides the display order across every provider (low to high), a deliberate sequence rather than an alphabetical one - the same one the user is meant to follow. A step sets either "url", sending the user to another screen (the panel picks the parcours back up there after the page load), or "highlight", a CSS selector pointing at what to look at on the screen already open - never both, the first leaving the page the second points into. "slug" must be unique across every bundle contributing projects.
     *
     * @return list<array{slug: string, label: string, description?: ?string, translation_domain: string, order: int, role?: ?string, steps: list<array{label: string, description?: ?string, url?: ?string, highlight?: ?string}>}>
     */
    public function getGuidedProjects(): array;
}
