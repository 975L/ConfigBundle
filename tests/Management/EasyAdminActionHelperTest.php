<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use PHPUnit\Framework\TestCase;

class EasyAdminActionHelperTest extends TestCase
{
    public function testToIconOnlyHidesLabelAndSetsTitle(): void
    {
        $action = Action::new('edit', 'Edit')->linkToCrudAction('edit');

        $result = EasyAdminActionHelper::toIconOnly($action, 'Edit');

        $this->assertSame($action, $result);
        $this->assertFalse($result->getAsDto()->getLabel());
        $this->assertSame(['title' => 'Edit'], $result->getAsDto()->getHtmlAttributes());
    }

    public function testToIconOnlyKeepsExistingHtmlAttributes(): void
    {
        $action = Action::new('preview', 'Preview')
            ->linkToUrl('https://example.com')
            ->setHtmlAttributes(['target' => '_blank']);

        $result = EasyAdminActionHelper::toIconOnly($action, 'Preview');

        $this->assertSame(['target' => '_blank', 'title' => 'Preview'], $result->getAsDto()->getHtmlAttributes());
    }
}
