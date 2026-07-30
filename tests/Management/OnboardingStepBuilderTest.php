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
use c975L\ConfigBundle\Management\OnboardingStepBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnboardingStepBuilderTest extends TestCase
{
    private function createMenuBuilder(array $menus, array $links): MenuBuilder
    {
        $menuBuilder = $this->createStub(MenuBuilder::class);
        $menuBuilder->method('getOrderedMenus')->willReturn($menus);
        $menuBuilder->method('getLinks')->willReturn($links);

        return $menuBuilder;
    }

    private function createAdminUrlGenerator(string $url = '/management/my-entity'): AdminUrlGeneratorInterface
    {
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn($url);

        return $adminUrlGenerator;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id) => $id);

        return $translator;
    }

    private function createSecurity(bool $isGranted = true): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($isGranted);

        return $security;
    }

    public function testGetStepsBuildsAStepForAMenuWithoutADescription(): void
    {
        $menuBuilder = $this->createMenuBuilder(
            ['my_entity' => ['controller' => 'MyCrudController', 'label' => 'label.my_entity', 'translation_domain' => 'my_bundle', 'icon' => 'fas fa-star']],
            [],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            [['url' => '/management/my-entity', 'label' => 'label.my_entity', 'description' => '']],
            $builder->getSteps(),
        );
    }

    public function testGetStepsBuildsAStepForADescribedMenu(): void
    {
        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setController')->with('MyCrudController')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::INDEX)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/my-entity');

        $menuBuilder = $this->createMenuBuilder(
            ['my_entity' => [
                'controller' => 'MyCrudController',
                'label' => 'label.my_entity',
                'translation_domain' => 'my_bundle',
                'icon' => 'fas fa-star',
                'description' => 'description.my_entity',
            ]],
            [],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $adminUrlGenerator,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            [['url' => '/management/my-entity', 'label' => 'label.my_entity', 'description' => 'description.my_entity']],
            $builder->getSteps(),
        );
    }

    public function testGetStepsBuildsAStepForALinkWithoutADescription(): void
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/whatsnew');

        $menuBuilder = $this->createMenuBuilder(
            [],
            ['whatsnew' => ['name' => 'management_whatsnew_index', 'label' => 'label.whatsnew', 'translation_domain' => 'config', 'icon' => 'fa fa-bullhorn']],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $urlGenerator,
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            [['url' => '/management/whatsnew', 'label' => 'label.whatsnew', 'description' => '']],
            $builder->getSteps(),
        );
    }

    public function testGetStepsResolvesARouteNameBasedLinkThroughThePlainRouter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('management_whatsnew_index')->willReturn('/management/whatsnew');

        $menuBuilder = $this->createMenuBuilder(
            [],
            ['whatsnew' => [
                'name' => 'management_whatsnew_index',
                'label' => 'label.whatsnew',
                'translation_domain' => 'config',
                'icon' => 'fa fa-bullhorn',
                'description' => 'description.whatsnew',
            ]],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $urlGenerator,
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            [['url' => '/management/whatsnew', 'label' => 'label.whatsnew', 'description' => 'description.whatsnew']],
            $builder->getSteps(),
        );
    }

    public function testGetStepsUsesTheLiteralUrlWhenALinkSetsOne(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->never())->method('generate');

        $menuBuilder = $this->createMenuBuilder(
            [],
            ['shop' => [
                'url' => 'https://example.com/showcase',
                'label' => 'label.showcase',
                'translation_domain' => 'my_bundle',
                'icon' => 'fas fa-shapes',
                'description' => 'description.showcase',
            ]],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $urlGenerator,
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            [['url' => 'https://example.com/showcase', 'label' => 'label.showcase', 'description' => 'description.showcase']],
            $builder->getSteps(),
        );
    }

    public function testGetStepsSkipsALinkTheCurrentUserLacksTheRoleFor(): void
    {
        $menuBuilder = $this->createMenuBuilder(
            [],
            ['content_import' => [
                'name' => 'management_content_import_index',
                'label' => 'label.content_import',
                'translation_domain' => 'config',
                'icon' => 'fa fa-file-import',
                'role' => 'ROLE_SUPER_ADMIN',
                'description' => 'description.content_import',
            ]],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createTranslator(),
            $this->createSecurity(false),
        );

        $this->assertSame([], $builder->getSteps());
    }

    public function testGetStepsPassesLabelParametersToTheTranslator(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = []) => $id . ($parameters ? ' ' . json_encode($parameters) : ''),
        );

        $menuBuilder = $this->createMenuBuilder(
            [],
            ['site' => [
                'url' => 'https://example.com',
                'label' => 'label.site_link',
                'label_parameters' => ['%name%' => 'My site'],
                'translation_domain' => 'config',
                'icon' => 'fa fa-globe',
                'description' => 'description.site',
            ]],
        );

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $this->createStub(UrlGeneratorInterface::class),
            $translator,
            $this->createSecurity(),
        );

        $this->assertSame('label.site_link {"%name%":"My site"}', $builder->getSteps()[0]['label']);
    }

    // getSteps() must not re-sort MenuBuilder::getOrderedMenus()'s own order (it's already the sidebar's own essential-then-advanced order, see MenuBuilder) - links are appended after every menu, same as the sidebar's own "links" section always sitting last
    public function testGetStepsPreservesOrderedMenusOrderAndAppendsLinksAfter(): void
    {
        $menuBuilder = $this->createMenuBuilder(
            [
                'zebra' => ['controller' => 'ZebraController', 'label' => 'label.zebra', 'translation_domain' => 'config', 'icon' => 'fa fa-z'],
                'apple' => ['controller' => 'AppleController', 'label' => 'label.apple', 'translation_domain' => 'config', 'icon' => 'fa fa-a'],
            ],
            ['whatsnew' => ['name' => 'management_whatsnew_index', 'label' => 'label.whatsnew', 'translation_domain' => 'config', 'icon' => 'fa fa-bullhorn']],
        );
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/whatsnew');

        $builder = new OnboardingStepBuilder(
            $menuBuilder,
            $this->createAdminUrlGenerator(),
            $urlGenerator,
            $this->createTranslator(),
            $this->createSecurity(),
        );

        $this->assertSame(
            ['label.zebra', 'label.apple', 'label.whatsnew'],
            array_column($builder->getSteps(), 'label'),
        );
    }
}
