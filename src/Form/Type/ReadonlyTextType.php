<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\TextType;

// Renders as plain text (a <p>) instead of an <input>, see c975l_readonly_text_widget block in form_theme.html.twig
class ReadonlyTextType extends TextType
{
    public function getBlockPrefix(): string
    {
        return 'c975l_readonly_text';
    }
}
