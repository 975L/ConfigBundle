<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Twig\CopyrightExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class CopyrightExtensionTest extends TestCase
{
    private string $currentYear;

    protected function setUp(): void
    {
        $this->currentYear = date('Y');
    }

    private function createExtension(?string $firstOnlineDate, ?string $siteName, string $locale = 'en'): CopyrightExtension
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['site-first-online-date', $firstOnlineDate],
            ['site-name', $siteName],
        ]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn($locale);

        return new CopyrightExtension($configService, $translator);
    }

    public function testGetCopyrightWithoutFirstOnlineDateShowsCurrentYearOnly(): void
    {
        $extension = $this->createExtension(null, null);

        $this->assertSame('© ' . $this->currentYear, $extension->getCopyright(false));
    }

    public function testGetCopyrightWithFirstOnlineDateSameAsCurrentYearShowsCurrentYearOnly(): void
    {
        $extension = $this->createExtension($this->currentYear . '-06-15', null);

        $this->assertSame('© ' . $this->currentYear, $extension->getCopyright(false));
    }

    public function testGetCopyrightWithEarlierFirstOnlineDateShowsRange(): void
    {
        $extension = $this->createExtension('2018-10-18', null);

        $this->assertSame('© 2018 - ' . $this->currentYear, $extension->getCopyright(false));
    }

    // The config is free text: a date typed in the French shape is unreadable to strtotime(), and used to render as the epoch year on every page ("© 1970 - ...")
    public function testGetCopyrightIgnoresAnUnparseableFirstOnlineDate(): void
    {
        $extension = $this->createExtension('15/03/2015', null);

        $this->assertSame('© ' . $this->currentYear, $extension->getCopyright(false));
    }

    // English has no space before the colon - matches the old emails/fullLayout.html.twig convention
    public function testGetCopyrightWithSiteNameInEnglishHasNoSpaceBeforeColon(): void
    {
        $extension = $this->createExtension('2018-10-18', '975L', 'en');

        $this->assertSame('© 2018 - ' . $this->currentYear . ': 975L', $extension->getCopyright());
    }

    // French/Spanish typographic convention adds a space before the colon - matches the old layout.html.twig convention
    public function testGetCopyrightWithSiteNameInFrenchHasASpaceBeforeColon(): void
    {
        $extension = $this->createExtension('2018-10-18', '975L', 'fr');

        $this->assertSame('© 2018 - ' . $this->currentYear . ' : 975L', $extension->getCopyright());
    }

    public function testGetCopyrightWithSiteNameInSpanishHasASpaceBeforeColon(): void
    {
        $extension = $this->createExtension('2018-10-18', '975L', 'es');

        $this->assertSame('© 2018 - ' . $this->currentYear . ' : 975L', $extension->getCopyright());
    }

    public function testGetCopyrightWithSiteNameFalseOmitsIt(): void
    {
        $extension = $this->createExtension('2018-10-18', '975L');

        $this->assertSame('© 2018 - ' . $this->currentYear, $extension->getCopyright(false));
    }

    public function testGetFunctionsRegistersSiteCopyrightFunction(): void
    {
        $extension = $this->createExtension(null, null);

        $functions = $extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('site_copyright', $functions[0]->getName());
    }
}
