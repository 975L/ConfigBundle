<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CopyrightExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_copyright', [$this, 'getCopyright']),
        ];
    }

    // "© firstYear - currentYear" (or just "© currentYear" if the site went online this year, or site-first-online-date isn't set), optionally suffixed with a locale-punctuated " : siteName"/": siteName" - was duplicated between layout.html.twig and emails/fullLayout.html.twig (using French/Spanish's own space-before-colon and English's own no-space convention respectively), now shared so a "Copyright"-page menu_link (see MenuExtension::isCopyrightPage()) can also reuse it as a live-computed link label
    public function getCopyright(bool $withSiteName = true): string
    {
        $firstOnlineDate = $this->configService->get('site-first-online-date');
        $currentYear = date('Y');

        // A free-text config: a date strtotime() cannot read (the French "15/03/2015" shape, a typo) comes back as false, which date() would take for the epoch and render as "© 1970 - ..." on every page of the site - such a value is treated as no date at all
        $firstTimestamp = $firstOnlineDate ? strtotime((string) $firstOnlineDate) : false;
        $firstYear = false !== $firstTimestamp ? date('Y', $firstTimestamp) : null;

        $copyright = (null === $firstYear || $firstYear === $currentYear)
            ? '© ' . $currentYear
            : '© ' . $firstYear . ' - ' . $currentYear;

        $siteName = $withSiteName ? $this->configService->get('site-name') : null;
        if (!$siteName) {
            return $copyright;
        }

        $separator = \in_array($this->translator->getLocale(), ['fr', 'es'], true) ? ' : ' : ': ';

        return $copyright . $separator . $siteName;
    }
}
