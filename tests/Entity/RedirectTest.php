<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Entity;

use c975L\ConfigBundle\Entity\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase
{
    // A path already starting with a slash is left untouched
    public function testSetFromPathKeepsLeadingSlashAsIs(): void
    {
        $redirect = (new Redirect())->setFromPath('/old-page');

        $this->assertSame('/old-page', $redirect->getFromPath());
    }

    // A path given without its leading slash gets one prepended
    public function testSetFromPathAddsMissingLeadingSlash(): void
    {
        $redirect = (new Redirect())->setFromPath('old-page');

        $this->assertSame('/old-page', $redirect->getFromPath());
    }

    // Several leading slashes are collapsed down to a single one
    public function testSetFromPathCollapsesRepeatedLeadingSlashes(): void
    {
        $redirect = (new Redirect())->setFromPath('///old-page');

        $this->assertSame('/old-page', $redirect->getFromPath());
    }

    // A new row redirects, answering 410 being the deliberate exception
    public function testANewRedirectIsNotGone(): void
    {
        $this->assertFalse((new Redirect())->isGone());
    }

    // A "gone" row has nothing to redirect to, hence the nullable toUrl
    public function testAGoneRedirectCarriesNoTarget(): void
    {
        $redirect = (new Redirect())->setFromPath('/removed')->setGone(true)->setToUrl(null);

        $this->assertTrue($redirect->isGone());
        $this->assertNull($redirect->getToUrl());
    }
}
