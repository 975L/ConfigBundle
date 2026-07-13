<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\MenuBuilder;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class MenuBuilderTest extends TestCase
{
    // Builds a MenuProviderInterface double for a given section/menus/links
    private function createProvider(array $section, array $menus, array $links = []): MenuProviderInterface
    {
        $provider = $this->createStub(MenuProviderInterface::class);
        $provider->method('getMenuSection')->willReturn($section);
        $provider->method('getMenus')->willReturn($menus);
        $provider->method('getLinks')->willReturn($links);

        return $provider;
    }

    // Translator double that returns the translation key untouched, so alphabetical sorting stays predictable
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return $translator;
    }

    private function createConfigService(string $role = 'ROLE_ADMIN'): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturn($role);

        return $service;
    }

    public function testGetMenusSortsAlphabeticallyByTranslatedLabel(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [
            'zebra' => ['controller' => 'ZebraController', 'label' => 'label.zebra', 'translation_domain' => 'config', 'icon' => 'fa fa-z'],
            'apple' => ['controller' => 'AppleController', 'label' => 'label.apple', 'translation_domain' => 'config', 'icon' => 'fa fa-a'],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator());

        $menus = $builder->getMenus();

        $this->assertSame(['apple', 'zebra'], array_keys($menus));
    }

    public function testGetLinksMergesAndSortsAcrossProviders(): void
    {
        $providerA = $this->createProvider(
            ['label' => 'label.management', 'translation_domain' => 'site'],
            [],
            ['zzz' => ['label' => 'label.zzz', 'name' => 'zzz_route', 'translation_domain' => 'config', 'icon' => 'fa fa-z']],
        );
        $providerB = $this->createProvider(
            ['label' => 'label.management', 'translation_domain' => 'site'],
            [],
            ['aaa' => ['label' => 'label.aaa', 'name' => 'aaa_route', 'translation_domain' => 'config', 'icon' => 'fa fa-a']],
        );
        $builder = new MenuBuilder([$providerA, $providerB], $this->createConfigService(), $this->createTranslator());

        $this->assertSame(['aaa', 'zzz'], array_keys($builder->getLinks()));
    }

    public function testGetMenuItemsYieldsOneSectionPerGroupAndAppliesAdminPermission(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [
            'config' => ['controller' => 'ConfigCrudController', 'label' => 'label.config', 'translation_domain' => 'config', 'icon' => 'fa fa-cog'],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService('ROLE_SUPER_ADMIN'), $this->createTranslator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        $this->assertCount(2, $items);
        $this->assertInstanceOf(MenuItemInterface::class, $items[0]);
        $sectionDto = $items[0]->getAsDto();
        $this->assertSame('label.management', $sectionDto->getLabel()->getMessage());

        $itemDto = $items[1]->getAsDto();
        $this->assertSame('label.config', $itemDto->getLabel()->getMessage());
        $this->assertSame('ROLE_SUPER_ADMIN', $itemDto->getPermission());
    }

    public function testGetMenuItemsOnlyAppendsALinksSectionWhenLinksExist(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $providerWithoutLinks = $this->createProvider($section, []);
        $builderWithoutLinks = new MenuBuilder([$providerWithoutLinks], $this->createConfigService(), $this->createTranslator());

        // A provider's section header is yielded even when it contributes no items, since
        // getGroupedMenus() registers the section for every provider regardless of getMenus()
        $itemsWithoutLinks = iterator_to_array($builderWithoutLinks->getMenuItems(), false);
        $this->assertCount(1, $itemsWithoutLinks);
        $this->assertSame('label.management', $itemsWithoutLinks[0]->getAsDto()->getLabel()->getMessage());

        $providerWithLinks = $this->createProvider($section, [], ['whatsnew' => [
            'label' => 'label.whatsnew',
            'name' => 'management_whatsnew_index',
            'translation_domain' => 'config',
            'icon' => 'fa fa-bullhorn',
        ]]);
        $builderWithLinks = new MenuBuilder([$providerWithLinks], $this->createConfigService(), $this->createTranslator());

        $items = iterator_to_array($builderWithLinks->getMenuItems(), false);

        // The provider's own (empty) menu section header, then the "links" section header
        // followed by the one link item
        $this->assertCount(3, $items);
        $this->assertSame('label.management', $items[0]->getAsDto()->getLabel()->getMessage());
        $this->assertSame('label.links', $items[1]->getAsDto()->getLabel()->getMessage());
        $this->assertSame('label.whatsnew', $items[2]->getAsDto()->getLabel()->getMessage());
    }
}
