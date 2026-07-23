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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

    // Real route names aren't registered in a unit test - stands in for the plain Symfony router (see MenuBuilder, uses generate() instead of EasyAdmin's own AdminUrlGenerator so a link to a route outside the dashboard resolves to its real path, not "/management?routeName=..."). The fake "https://example.test/" prefix for an ABSOLUTE_URL request (vs a bare "/" for the default ABSOLUTE_PATH) lets tests tell the two apart without needing a real router/request context.
    private function createUrlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH) =>
                (UrlGeneratorInterface::ABSOLUTE_URL === $referenceType ? 'https://example.test/' : '/') . $name
        );

        return $generator;
    }

    public function testGetMenusSortsAlphabeticallyByTranslatedLabel(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [
            'zebra' => ['controller' => 'ZebraController', 'label' => 'label.zebra', 'translation_domain' => 'config', 'icon' => 'fa fa-z'],
            'apple' => ['controller' => 'AppleController', 'label' => 'label.apple', 'translation_domain' => 'config', 'icon' => 'fa fa-a'],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

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
        $builder = new MenuBuilder([$providerA, $providerB], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $this->assertSame(['aaa', 'zzz'], array_keys($builder->getLinks()));
    }

    public function testGetMenuItemsYieldsOneSectionPerGroupAndAppliesAdminPermission(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [
            'config' => ['controller' => 'ConfigCrudController', 'label' => 'label.config', 'translation_domain' => 'config', 'icon' => 'fa fa-cog'],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService('ROLE_SUPER_ADMIN'), $this->createTranslator(), $this->createUrlGenerator());

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
        $builderWithoutLinks = new MenuBuilder([$providerWithoutLinks], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        // A provider's section header is yielded even when it contributes no items, since getGroupedMenus() registers the section for every provider regardless of getMenus()
        $itemsWithoutLinks = iterator_to_array($builderWithoutLinks->getMenuItems(), false);
        $this->assertCount(1, $itemsWithoutLinks);
        $this->assertSame('label.management', $itemsWithoutLinks[0]->getAsDto()->getLabel()->getMessage());

        $providerWithLinks = $this->createProvider($section, [], ['whatsnew' => [
            'label' => 'label.whatsnew',
            'name' => 'management_whatsnew_index',
            'translation_domain' => 'config',
            'icon' => 'fa fa-bullhorn',
        ]]);
        $builderWithLinks = new MenuBuilder([$providerWithLinks], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builderWithLinks->getMenuItems(), false);

        // The provider's own (empty) menu section header, then the "links" section header followed by the one link item
        $this->assertCount(3, $items);
        $this->assertSame('label.management', $items[0]->getAsDto()->getLabel()->getMessage());
        $this->assertSame('label.links', $items[1]->getAsDto()->getLabel()->getMessage());
        $this->assertSame('label.whatsnew', $items[2]->getAsDto()->getLabel()->getMessage());
    }

    public function testGetMenuItemsAppliesLinkRoleWhenProvidedAndLeavesItUnsetOtherwise(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [], [
            'media' => [
                'label' => 'label.media',
                'name' => 'management_media_index',
                'translation_domain' => 'ui',
                'icon' => 'fas fa-photo-film',
                'role' => 'ROLE_EDITOR',
            ],
            'shop' => [
                'label' => 'label.shop',
                'name' => 'shop_index',
                'translation_domain' => 'shop',
                'icon' => '',
            ],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        // Provider's own (empty) menu section header, then the "links" section header, then links sorted alphabetically: media before shop
        $this->assertCount(4, $items);
        $this->assertSame('ROLE_EDITOR', $items[2]->getAsDto()->getPermission());
        $this->assertNull($items[3]->getAsDto()->getPermission());
    }

    // A link's URL must come from the plain router (generate()), not EasyAdmin's own AdminUrlGenerator - the latter assumes the route is one of the dashboard's own registered actions and wraps it as "/management?routeName=...", which is wrong for a route outside the dashboard entirely (e.g. a consuming app's own public page) - regression test for exactly that bug
    public function testGetMenuItemsResolvesLinkUrlThroughThePlainRouter(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [], [
            'showcase' => [
                'label' => 'label.block_showcase',
                'name' => 'app_block_showcase_index',
                'translation_domain' => 'messages',
                'icon' => 'fas fa-shapes',
            ],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        $this->assertSame('/app_block_showcase_index', $items[2]->getAsDto()->getLinkUrl());
    }

    // Optional per-link "target" (e.g. '_blank' for a link leaving the admin) - unset by default, same opt-in shape as "role". A "target" link also gets a full absolute URL (scheme+host), not just a path, generated fresh from the current request each time (never a hardcoded domain, so it stays correct across dev/staging/prod or any future domain change) - it's meant to stand on its own once opened in a new tab, unlike a same-tab link staying relative to the current page.
    public function testGetMenuItemsAppliesLinkTargetAndAbsoluteUrlWhenProvidedAndLeavesBothUnsetOtherwise(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [], [
            'showcase' => [
                'label' => 'label.block_showcase',
                'name' => 'app_block_showcase_index',
                'translation_domain' => 'messages',
                'icon' => 'fas fa-shapes',
                'target' => '_blank',
            ],
            'shop' => [
                'label' => 'label.shop',
                'name' => 'shop_index',
                'translation_domain' => 'shop',
                'icon' => '',
            ],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        // Links sorted alphabetically by translated label: "label.block_showcase" before "label.shop". MenuItemDto's own default target (not set by MenuBuilder) is '_self', not null
        $this->assertSame('_blank', $items[2]->getAsDto()->getLinkTarget());
        $this->assertSame('https://example.test/app_block_showcase_index', $items[2]->getAsDto()->getLinkUrl());
        $this->assertSame('_self', $items[3]->getAsDto()->getLinkTarget());
        $this->assertSame('/shop_index', $items[3]->getAsDto()->getLinkUrl());
    }

    // A section opting into 'advanced' (see MenuProviderInterface::getMenuSection()) doesn't get its own top-level section header - its items are collected into one collapsed "Avancé" submenu instead, appended after every essential section
    public function testGetMenuItemsGroupsAdvancedTierSectionsIntoOneCollapsedSubmenu(): void
    {
        $essential = $this->createProvider(
            ['label' => 'label.essential', 'translation_domain' => 'site'],
            ['config' => ['controller' => 'ConfigCrudController', 'label' => 'label.config', 'translation_domain' => 'config', 'icon' => 'fa fa-cog']],
        );
        $advanced = $this->createProvider(
            ['label' => 'label.seo', 'translation_domain' => 'ui', 'tier' => 'advanced'],
            ['seo' => ['controller' => 'SeoCrudController', 'label' => 'label.seo_settings', 'translation_domain' => 'ui', 'icon' => 'fa fa-search']],
        );
        $builder = new MenuBuilder([$essential, $advanced], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        // Essential section header + its item, then one submenu (no separate "seo" section header)
        $this->assertCount(3, $items);
        $this->assertSame('label.essential', $items[0]->getAsDto()->getLabel()->getMessage());
        $this->assertSame('label.config', $items[1]->getAsDto()->getLabel()->getMessage());

        $submenuDto = $items[2]->getAsDto();
        $this->assertSame('label.menu_advanced', $submenuDto->getLabel()->getMessage());
        $this->assertCount(1, $submenuDto->getSubItems());
        $this->assertSame('label.seo_settings', $submenuDto->getSubItems()[0]->getLabel()->getMessage());
    }

    // Real-world case: several providers share the same section (e.g. Config/Site/UiBundle all merge into
    // "management") - an individual item's own 'tier' must move just that item to "Avancé" without
    // dragging the rest of that shared section (from the same or another provider) along with it
    public function testGetMenuItemsMovesOnlyTheItemsThatOptInWithinASharedSection(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $providerA = $this->createProvider($section, [
            'page' => ['controller' => 'PageCrudController', 'label' => 'label.pages', 'translation_domain' => 'site', 'icon' => 'fa fa-file'],
            'redirect' => ['controller' => 'RedirectCrudController', 'label' => 'label.redirects', 'translation_domain' => 'site', 'icon' => 'fa fa-arrow-right', 'tier' => 'advanced'],
        ]);
        $providerB = $this->createProvider($section, [
            'config' => ['controller' => 'ConfigCrudController', 'label' => 'label.config', 'translation_domain' => 'config', 'icon' => 'fa fa-cog'],
        ]);
        $builder = new MenuBuilder([$providerA, $providerB], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        // One section header, its 2 essential items (config, page - alphabetical), then one submenu holding "redirect" alone
        $this->assertCount(4, $items);
        $this->assertSame('label.management', $items[0]->getAsDto()->getLabel()->getMessage());
        $this->assertSame(['label.config', 'label.pages'], [$items[1]->getAsDto()->getLabel()->getMessage(), $items[2]->getAsDto()->getLabel()->getMessage()]);

        $submenuDto = $items[3]->getAsDto();
        $this->assertSame('label.menu_advanced', $submenuDto->getLabel()->getMessage());
        $this->assertCount(1, $submenuDto->getSubItems());
        $this->assertSame('label.redirects', $submenuDto->getSubItems()[0]->getLabel()->getMessage());
    }

    // A section without a 'tier' key (or explicitly 'essential') keeps today's behavior - no submenu is created when nothing opts into 'advanced'
    public function testGetMenuItemsOmitsTheAdvancedSubmenuWhenNoSectionOptsIn(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [
            'config' => ['controller' => 'ConfigCrudController', 'label' => 'label.config', 'translation_domain' => 'config', 'icon' => 'fa fa-cog'],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertNotSame('label.menu_advanced', $item->getAsDto()->getLabel()?->getMessage());
        }
    }

    // An explicit "url" (a literal, already-absolute URL) is used as-is, bypassing route resolution entirely - for a provider that wants a link fixed/directly editable rather than derived from a route (e.g. 975l.com's own MenuProvider pinning its "vitrine des blocks" link to the real production domain on purpose, see App\Management\MenuProvider)
    public function testGetMenuItemsUsesAnExplicitUrlAsIsWithoutRouteResolution(): void
    {
        $section = ['label' => 'label.management', 'translation_domain' => 'site'];
        $provider = $this->createProvider($section, [], [
            'showcase' => [
                'label' => 'label.block_showcase',
                'url' => 'https://975l.com/vitrine-blocks',
                'translation_domain' => 'messages',
                'icon' => 'fas fa-shapes',
                'target' => '_blank',
            ],
        ]);
        $builder = new MenuBuilder([$provider], $this->createConfigService(), $this->createTranslator(), $this->createUrlGenerator());

        $items = iterator_to_array($builder->getMenuItems(), false);

        $this->assertSame('https://975l.com/vitrine-blocks', $items[2]->getAsDto()->getLinkUrl());
    }
}
