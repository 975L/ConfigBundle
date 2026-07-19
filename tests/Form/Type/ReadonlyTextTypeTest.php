<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Form\Type;

use c975L\ConfigBundle\Form\Type\ReadonlyTextType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class ReadonlyTextTypeTest extends TestCase
{
    public function testExtendsTextType(): void
    {
        $type = new ReadonlyTextType();

        $this->assertInstanceOf(TextType::class, $type);
    }

    // Block prefix must match the c975l_readonly_text_widget block declared in form_theme.html.twig, otherwise the field silently falls back to a plain text input
    public function testGetBlockPrefixMatchesTheDedicatedFormThemeBlock(): void
    {
        $type = new ReadonlyTextType();

        $this->assertSame('c975l_readonly_text', $type->getBlockPrefix());
    }
}
