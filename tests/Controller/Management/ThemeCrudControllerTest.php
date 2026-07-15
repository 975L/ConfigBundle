<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ThemeCrudController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\ThemePresetRegistry;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ThemeCrudControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createController(
        ?ConfigServiceInterface $configService = null,
        ?Security $security = null,
        ?ThemePresetRegistry $themePresetRegistry = null,
        ?ConfigRepository $configRepository = null,
        ?AdminUrlGenerator $adminUrlGenerator = null,
    ): ThemeCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ThemeCrudController(
            $configService ?? $this->createConfigService(),
            $translator,
            $security ?? $this->createStub(Security::class),
            $themePresetRegistry ?? new ThemePresetRegistry([]),
            $configRepository ?? $this->createStub(ConfigRepository::class),
            $adminUrlGenerator ?? $this->createAdminUrlGenerator(),
        );
    }

    private function createConfigService(): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnMap([
            ['site-role-admin', 'site-role-admin'],
            ['site-role-editor', 'site-role-editor'],
        ]);

        return $service;
    }

    // AdminUrlGenerator is final - can't be mocked, so it's built for real with stubbed interface
    // collaborators, matching how PageCrudControllerTest (SiteBundle) does it
    private function createAdminUrlGenerator(string $generatedUrl = '/admin'): AdminUrlGenerator
    {
        $adminControllers = $this->createStub(AdminControllerRegistryInterface::class);
        $adminControllers->method('getDashboardCount')->willReturn(1);
        $adminControllers->method('getFirstDashboard')->willReturn('App\\Controller\\Management\\DashboardController');
        $adminControllers->method('getFirstDashboardRoute')->willReturn('admin');

        $routeGenerator = $this->createStub(AdminRouteGeneratorInterface::class);
        $routeGenerator->method('findRouteName')->willReturn('admin');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($generatedUrl);

        return new AdminUrlGenerator(
            $this->createStub(AdminContextProviderInterface::class),
            $urlGenerator,
            $adminControllers,
            $routeGenerator,
            new ArrayAdapter(),
        );
    }

    private function setContextEntity(ThemeCrudController $controller, Config $config): void
    {
        $entityDto = new EntityDto(Config::class, new ClassMetadata(Config::class), null, $config);
        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $contextProvider = $this->createStub(AdminContextProviderInterface::class);
        $contextProvider->method('getContext')->willReturn($context);

        $controller->setContainer($this->createContainer([
            AdminContextProviderInterface::class => $contextProvider,
        ]));
    }

    private function invokePrivate(ThemeCrudController $controller, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($controller, $method);

        return $reflection->invoke($controller, ...$args);
    }

    private function createAdminContextFor(Config $config): AdminContext
    {
        $entityDto = new EntityDto(Config::class, new ClassMetadata(Config::class), null, $config);

        return AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));
    }

    private function findField(iterable $fields, string $property): FieldInterface
    {
        foreach ($fields as $field) {
            if ($property === $field->getAsDto()->getProperty()) {
                return $field;
            }
        }

        $this->fail(sprintf('Field "%s" not found.', $property));
    }

    // --- configureFields ------------------------------------------------------------------------------------

    public function testConfigureFieldsReturnsFieldsWhenThereIsNoAdminContext(): void
    {
        $contextProvider = $this->createStub(AdminContextProviderInterface::class);
        $contextProvider->method('getContext')->willReturn(null);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            AdminContextProviderInterface::class => $contextProvider,
        ]));

        $fields = iterator_to_array($controller->configureFields('index'));

        $this->assertNotEmpty($fields);
    }

    public function testConfigureFieldsRendersValueAsTextFieldForAColorSlug(): void
    {
        $config = (new Config())->setSlug('theme-color-primary')->setValue('#ff0000');
        $controller = $this->createController();
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertInstanceOf(TextField::class, $valueField);
    }

    // theme-mode is the only theme slug edited as a fixed light/dark/auto choice, not free text
    public function testConfigureFieldsRendersValueAsChoiceFieldForThemeMode(): void
    {
        $config = (new Config())->setSlug('theme-mode')->setValue('auto');
        $controller = $this->createController();
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertInstanceOf(ChoiceField::class, $valueField);
    }

    // --- configureActions -----------------------------------------------------------------------------------

    public function testConfigureActionsBuildsWithoutError(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(Actions::new());

        $this->assertInstanceOf(Actions::class, $actions);
    }

    public function testConfigureActionsBuildsWithRegisteredPresets(): void
    {
        $registry = new ThemePresetRegistry([$this->createPresetProvider([
            'default' => ['label' => 'label.theme_preset_default', 'values' => ['theme-color-primary' => 'rgb(11, 55, 178)']],
        ])]);
        $controller = $this->createController(themePresetRegistry: $registry);

        $actions = $controller->configureActions(Actions::new());

        $this->assertInstanceOf(Actions::class, $actions);
    }

    // Manual field editing (colors, fonts, mode) is reserved to ROLE_SUPER_ADMIN even for
    // non-restricted values; applying a preset (vetted values only) stays at the lower editor level
    public function testConfigureActionsRestrictsManualEditToSuperAdminAndPresetsToEditor(): void
    {
        $configService = $this->createConfigService();
        $registry = new ThemePresetRegistry([$this->createPresetProvider([
            'default' => ['label' => 'label.theme_preset_default', 'values' => ['theme-color-primary' => 'rgb(11, 55, 178)']],
        ])]);
        $controller = $this->createController(configService: $configService, themePresetRegistry: $registry);

        $actions = $controller->configureActions(Actions::new());

        $permissions = $actions->getAsDto(null)->getActionPermissions();
        $this->assertSame('ROLE_SUPER_ADMIN', $permissions[Action::EDIT]);
        $this->assertSame('site-role-editor', $permissions['applyPreset_default']);
    }

    // --- denyAccessToRestrictedConfig / detail / edit ----------------------------------------------------------

    // A "restricted" theme config (font family, stylesheet) must stay invisible to any admin below
    // ROLE_SUPER_ADMIN, same protection as ConfigCrudController for the same entity/table
    public function testDenyAccessToRestrictedConfigDeniesAccessWhenConfigIsRestrictedAndNotSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $config = (new Config())->setSlug('theme-font-family-title')->setIsRestricted(true);
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $this->invokePrivate($controller, 'denyAccessToRestrictedConfig', [$this->createAdminContextFor($config)]);
    }

    public function testDenyAccessToRestrictedConfigAllowsAccessWhenConfigIsRestrictedAndSuperAdmin(): void
    {
        $config = (new Config())->setSlug('theme-font-family-title')->setIsRestricted(true);
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $this->invokePrivate($controller, 'denyAccessToRestrictedConfig', [$this->createAdminContextFor($config)]);

        $this->addToAssertionCount(1);
    }

    public function testDenyAccessToRestrictedConfigAllowsAccessWhenConfigIsNotRestricted(): void
    {
        $config = (new Config())->setSlug('theme-color-primary')->setIsRestricted(false);
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $this->invokePrivate($controller, 'denyAccessToRestrictedConfig', [$this->createAdminContextFor($config)]);

        $this->addToAssertionCount(1);
    }

    public function testDetailDeniesAccessForRestrictedConfigBelowSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $config = (new Config())->setSlug('theme-stylesheet')->setIsRestricted(true);
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->detail($this->createAdminContextFor($config));
    }

    public function testEditDeniesAccessForRestrictedConfigBelowSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $config = (new Config())->setSlug('theme-stylesheet')->setIsRestricted(true);
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->edit($this->createAdminContextFor($config));
    }

    // --- applyPreset ------------------------------------------------------------------------------------------

    public function testApplyPresetOverwritesMatchingConfigsAndInvalidatesCache(): void
    {
        $primary = (new Config())->setSlug('theme-color-primary')->setValue(null);
        $secondary = (new Config())->setSlug('theme-color-secondary')->setValue(null);

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findByGroup')->willReturn([$primary, $secondary]);

        $registry = new ThemePresetRegistry([$this->createPresetProvider([
            'default' => ['label' => 'label.theme_preset_default', 'values' => [
                'theme-color-primary' => 'rgb(11, 55, 178)',
                'theme-color-secondary' => 'rgb(218, 126, 57)',
            ]],
        ])]);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(
            configService: $configService,
            themePresetRegistry: $registry,
            configRepository: $configRepository,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller->applyPreset(new Request(query: ['preset' => 'default']), $entityManager);

        $this->assertSame('rgb(11, 55, 178)', $primary->getValue());
        $this->assertSame('rgb(218, 126, 57)', $secondary->getValue());
        $this->assertNotNull($primary->getModification());
    }

    public function testApplyPresetDoesNothingForUnknownPreset(): void
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findByGroup')->willReturn([]);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');
        $configService->expects($this->never())->method('invalidateCache');

        $controller = $this->createController(configService: $configService, configRepository: $configRepository);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller->applyPreset(new Request(query: ['preset' => 'unknown']), $entityManager);
    }

    private function createPresetProvider(array $presets): \c975L\ConfigBundle\Management\ThemePresetProviderInterface
    {
        $provider = $this->createStub(\c975L\ConfigBundle\Management\ThemePresetProviderInterface::class);
        $provider->method('getPresets')->willReturn($presets);

        return $provider;
    }

    // --- createIndexQueryBuilder ------------------------------------------------------------------------------

    public function testCreateIndexQueryBuilderFiltersRestrictedThemeConfigsForNonSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->exactly(2))->method('andWhere')->willReturnSelf();
        $queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnSelf();

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $controller = $this->createController(security: $security);
        $controller->setContainer($this->createContainer([
            EntityRepositoryInterface::class => $repository,
        ]));

        $result = $controller->createIndexQueryBuilder(
            new SearchDto(new Request(), null, null, [], [], null),
            new EntityDto(Config::class, new ClassMetadata(Config::class)),
            new FieldCollection([]),
            new FilterCollection([]),
        );

        $this->assertSame($queryBuilder, $result);
    }

    public function testCreateIndexQueryBuilderSkipsRestrictedFilterForSuperAdminAndFiltersOnThemeGroup(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->once())->method('andWhere')->with('entity.group = :group')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('setParameter')->with('group', Config::GROUP_THEME)->willReturnSelf();

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $controller = $this->createController(security: $security);
        $controller->setContainer($this->createContainer([
            EntityRepositoryInterface::class => $repository,
        ]));

        $controller->createIndexQueryBuilder(
            new SearchDto(new Request(), null, null, [], [], null),
            new EntityDto(Config::class, new ClassMetadata(Config::class)),
            new FieldCollection([]),
            new FilterCollection([]),
        );
    }

    // --- updateEntity -----------------------------------------------------------------------------------------

    public function testUpdateEntitySetsModificationDateAndInvalidatesCache(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(configService: $configService);
        $config = (new Config())->setSlug('theme-color-primary')->setValue('#ff0000');

        $manager = $this->createStub(EntityManagerInterface::class);
        $controller->updateEntity($manager, $config);

        $this->assertNotNull($config->getModification());
    }

    // Same audit trail as ConfigCrudController::updateEntity() - records which admin made the edit
    public function testUpdateEntityRecordsTheLoggedInUserAsEditor(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('getUser')->willReturn(null);

        $controller = $this->createController(security: $security);
        $config = (new Config())->setSlug('theme-color-primary')->setValue('#ff0000');

        $controller->updateEntity($this->createStub(EntityManagerInterface::class), $config);

        // No logged-in user in this scenario: getUser() is consulted but the config's user is left untouched
        $this->assertNull($config->getUser());
    }
}
