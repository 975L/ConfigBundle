<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\ConfigAlertProvider;
use c975L\ConfigBundle\Management\ConfigExportProvider;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\ConfigBundle\Service\VaultEncryptor;
use c975L\UiBundle\Contract\FontProviderInterface;
use c975L\UiBundle\Form\FontChoiceType;
use c975L\UiBundle\Registry\FontRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface as EaAdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigCrudControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    // AdminUrlGenerator is final - can't be mocked, so it's built for real with stubbed interface collaborators, same pattern as SiteBundle's PageCrudControllerTest/CollectionItemCrudControllerTest
    private function createAdminUrlGenerator(): AdminUrlGenerator
    {
        $adminControllers = $this->createStub(AdminControllerRegistryInterface::class);
        $adminControllers->method('getDashboardCount')->willReturn(1);
        $adminControllers->method('getFirstDashboard')->willReturn('App\\Controller\\Management\\DashboardController');
        $adminControllers->method('getFirstDashboardRoute')->willReturn('admin');

        $routeGenerator = $this->createStub(AdminRouteGeneratorInterface::class);
        $routeGenerator->method('findRouteName')->willReturn('admin');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/config');

        return new AdminUrlGenerator(
            $this->createStub(EaAdminContextProviderInterface::class),
            $urlGenerator,
            $adminControllers,
            $routeGenerator,
            new ArrayAdapter(),
        );
    }

    // Simulates browsing the index already scoped to a group (?group=...)
    private function createRequestStackWithGroup(?string $group): RequestStack
    {
        $request = new Request(null !== $group ? ['group' => $group] : []);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function createController(
        ?Security $security = null,
        ?ConfigServiceInterface $configService = null,
        ?VaultEncryptor $vaultEncryptor = null,
        ?Connection $connection = null,
        ?RequestStack $requestStack = null,
        ?TableExporter $tableExporter = null,
        ?ConfigSqlExporter $configSqlExporter = null,
        ?ContentExporter $contentExporter = null,
        ?ConfigExportProvider $configExportProvider = null,
        ?ConfigRepository $configRepository = null,
        ?AdminUrlGenerator $adminUrlGenerator = null,
        ?FontRegistry $fontRegistry = null,
    ): ConfigCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $security ??= $this->createStub(Security::class);
        $connection ??= $this->createStub(Connection::class);

        return new ConfigCrudController(
            $security,
            $configService ?? $this->createConfigService(),
            $vaultEncryptor ?? new VaultEncryptor(null),
            $connection,
            $requestStack ?? new RequestStack(),
            $translator,
            $tableExporter ?? $this->createStub(TableExporter::class),
            $configSqlExporter ?? $this->createStub(ConfigSqlExporter::class),
            $contentExporter ?? $this->createStub(ContentExporter::class),
            $configExportProvider ?? new ConfigExportProvider($connection, $security),
            $this->createStub(ConfigAlertProvider::class),
            $configRepository ?? $this->createStub(ConfigRepository::class),
            $adminUrlGenerator ?? $this->createAdminUrlGenerator(),
            $fontRegistry ?? new FontRegistry(),
        );
    }

    // Builds a FontRegistry pre-populated with a single stub provider returning $fonts, mirroring what
    // FontProviderPass would wire in a real app that has a font-declaring bundle (e.g. SiteBundle) installed
    private function createFontRegistry(array $fonts): FontRegistry
    {
        $provider = $this->createStub(FontProviderInterface::class);
        $provider->method('getFonts')->willReturn($fonts);

        $registry = new FontRegistry();
        $registry->addProvider($provider);

        return $registry;
    }

    private function createConfigService(): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturn('site-role-admin');
        $service->method('getBool')->willReturnCallback(
            static fn (mixed $value) => filter_var($value, \FILTER_VALIDATE_BOOL),
        );

        return $service;
    }

    private function invokePrivate(ConfigCrudController $controller, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($controller, $method);

        return $reflection->invoke($controller, ...$args);
    }

    // AdminContext is final (can't be mocked); EasyAdmin ships AdminContext::forTesting() precisely for this - export*() never reads $context, so the bare default is enough here
    private function createAdminContext(): AdminContext
    {
        return AdminContext::forTesting();
    }

    // --- currentGroup (private) -----------------------------------------------------------------------

    public function testCurrentGroupReturnsNullWhenNoGroupQueryParam(): void
    {
        $controller = $this->createController(requestStack: $this->createRequestStackWithGroup(null));

        $this->assertNull($this->invokePrivate($controller, 'currentGroup'));
    }

    public function testCurrentGroupReturnsTheGroupQueryParam(): void
    {
        $controller = $this->createController(requestStack: $this->createRequestStackWithGroup('legal'));

        $this->assertSame('legal', $this->invokePrivate($controller, 'currentGroup'));
    }

    // --- showGroupsScreen (private) ----------------------------------------------------------------------

    public function testShowGroupsScreenIsTrueWithNoGroupAndNoQuery(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $controller = $this->createController(requestStack: $requestStack);

        $this->assertTrue($this->invokePrivate($controller, 'showGroupsScreen'));
    }

    public function testShowGroupsScreenIsFalseWhenAGroupIsSelected(): void
    {
        $controller = $this->createController(requestStack: $this->createRequestStackWithGroup('legal'));

        $this->assertFalse($this->invokePrivate($controller, 'showGroupsScreen'));
    }

    // A search query typed on the "pick a group" screen (which displays the search box but previously never read it) now bypasses it, searching across every group at once
    public function testShowGroupsScreenIsFalseWhenASearchQueryIsPresent(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['query' => 'ai-help']));

        $controller = $this->createController(requestStack: $requestStack);

        $this->assertFalse($this->invokePrivate($controller, 'showGroupsScreen'));
    }

    public function testShowGroupsScreenIsTrueWhenQueryParamIsBlank(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['query' => '']));

        $controller = $this->createController(requestStack: $requestStack);

        $this->assertTrue($this->invokePrivate($controller, 'showGroupsScreen'));
    }

    // --- index ------------------------------------------------------------------------------------------

    // Without a "group" to scope to, index() renders the "pick a group" screen directly (bypassing EasyAdmin's own grid/parent::index()) - AbstractController::render() only needs a "twig" service in the container, so this is exercised without a real Twig environment or template file
    public function testIndexRendersGroupsScreenWithCountsWhenNoGroupIsSelected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects($this->once())
            ->method('countsByGroup')
            ->with(false, true)
            ->willReturn(['general' => 3, 'legal' => 2]);

        $controller = $this->createController(
            security: $security,
            configRepository: $configRepository,
            requestStack: $this->createRequestStackWithGroup(null),
        );

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@c975LConfig/management/config_crud_groups.html.twig',
                $this->callback(function (array $parameters) {
                    $this->assertSame(['general' => 3, 'legal' => 2], $parameters['counts']);

                    return true;
                })
            )
            ->willReturn('<html>groups</html>');

        $controller->setContainer($this->createContainer(['twig' => $twig]));

        $response = $controller->index(AdminContext::forTesting());

        $this->assertSame('<html>groups</html>', $response->getContent());
    }

    // --- persistEntity / updateEntity / deleteEntity -------------------------------------------------

    public function testPersistEntityEncryptsSensitiveValueSetsDatesAndInvalidatesCache(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(configService: $configService, vaultEncryptor: $vaultEncryptor);
        $config = (new Config())->setSlug('api-key')->setIsSensitive(true)->setValue('secret');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($config);
        $manager->expects($this->once())->method('flush');

        $controller->persistEntity($manager, $config);

        $this->assertSame('secret', $vaultEncryptor->decrypt($config->getValue()));
        $this->assertNotNull($config->getCreation());
        $this->assertNotNull($config->getModification());
    }

    public function testPersistEntityLeavesNonSensitiveValueUntouched(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(configService: $configService);
        $config = (new Config())->setSlug('site-name')->setValue('My Site');

        $manager = $this->createStub(EntityManagerInterface::class);
        $controller->persistEntity($manager, $config);

        $this->assertSame('My Site', $config->getValue());
    }

    public function testPersistEntityDoesNotEncryptAnEmptySensitiveValue(): void
    {
        $controller = $this->createController();
        $config = (new Config())->setSlug('api-key')->setIsSensitive(true)->setValue(null);

        $controller->persistEntity($this->createStub(EntityManagerInterface::class), $config);

        $this->assertNull($config->getValue());
    }

    public function testUpdateEntityEncryptsNewNonBlankSensitiveValue(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $controller = $this->createController(vaultEncryptor: $vaultEncryptor);
        $config = (new Config())->setSlug('api-key')->setIsSensitive(true)->setValue('new-secret');

        $controller->updateEntity($this->createStub(EntityManagerInterface::class), $config);

        $this->assertSame('new-secret', $vaultEncryptor->decrypt($config->getValue()));
    }

    // A blank submission on a sensitive field means the admin actively cleared it (the field is pre-filled with the decrypted value on edit), so it must be stored empty, not re-encrypted
    public function testUpdateEntityClearsSensitiveValueOnBlankSubmission(): void
    {
        $controller = $this->createController();
        $config = (new Config())->setSlug('api-key')->setIsSensitive(true)->setValue('');

        $controller->updateEntity($this->createStub(EntityManagerInterface::class), $config);

        $this->assertSame('', $config->getValue());
    }

    public function testDeleteEntityInvalidatesCache(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(configService: $configService);
        $config = (new Config())->setSlug('site-name')->setValue('My Site');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('remove')->with($config);

        $controller->deleteEntity($manager, $config);
    }

    // --- createIndexQueryBuilder ----------------------------------------------------------------------

    public function testCreateIndexQueryBuilderFiltersRestrictedConfigsForNonSuperAdmin(): void
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

    public function testCreateIndexQueryBuilderSkipsRestrictedFilterForSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->once())->method('andWhere')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('setParameter')->willReturnSelf();

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

    // The index is scoped to one group via ?group=... (see index()/currentGroup()) once a group has been picked on the "groups" screen
    public function testCreateIndexQueryBuilderFiltersByCurrentGroupWhenOneIsSelected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        // isSensitive + group, isRestricted skipped (super admin)
        $queryBuilder->expects($this->exactly(2))->method('andWhere')->willReturnSelf();
        $queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnCallback(
            function (string $name, mixed $value) use ($queryBuilder) {
                if ('group' === $name) {
                    $this->assertSame('general', $value);
                }

                return $queryBuilder;
            }
        );

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $controller = $this->createController(security: $security, requestStack: $this->createRequestStackWithGroup('general'));
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

    // EasyAdmin's own search only ever matches raw DB columns ("label" stores a translation key like
    // "label.ai_help_llm_enabled", never the rendered "Donovan (Q&A) - Activé" an admin actually types)
    // - a non-empty query must instead restrict to slugs whose *translated* label/description contain it
    public function testCreateIndexQueryBuilderRestrictsToSlugsMatchingTranslatedLabelOrDescription(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['slug' => 'ai-help-llm-enabled', 'description' => 'description.ai_help_llm_enabled'],
            ['slug' => 'site-name', 'description' => null],
        ]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['label.ai_help_llm_enabled', [], 'site_config', 'Donovan (Q&A) - Activé'],
            ['description.ai_help_llm_enabled', [], 'site_config', 'Some description'],
            ['label.site_name', [], 'site_config', 'Site Name'],
        ]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        // 1 extra andWhere/setParameter for the slug allowlist, on top of the usual isSensitive one
        // (isRestricted is skipped here since $security grants ROLE_SUPER_ADMIN)
        $queryBuilder->expects($this->exactly(2))->method('andWhere')->willReturnSelf();
        $queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnCallback(
            function (string $name, mixed $value) use ($queryBuilder) {
                if ('matchingSlugs' === $name) {
                    $this->assertSame(['ai-help-llm-enabled'], $value);
                }

                return $queryBuilder;
            }
        );

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $controller = new ConfigCrudController(
            $security,
            $this->createConfigService(),
            new VaultEncryptor(null),
            $connection,
            new RequestStack(),
            $translator,
            $this->createStub(TableExporter::class),
            $this->createStub(ConfigSqlExporter::class),
            $this->createStub(ContentExporter::class),
            new ConfigExportProvider($connection, $security),
            $this->createStub(ConfigAlertProvider::class),
            $this->createStub(ConfigRepository::class),
            $this->createAdminUrlGenerator(),
            new FontRegistry(),
        );
        $controller->setContainer($this->createContainer([
            EntityRepositoryInterface::class => $repository,
        ]));

        $controller->createIndexQueryBuilder(
            new SearchDto(new Request(), null, 'donovan', [], [], null),
            new EntityDto(Config::class, new ClassMetadata(Config::class)),
            new FieldCollection([]),
            new FilterCollection([]),
        );
    }

    // --- fetchExportRows (private) ---------------------------------------------------------------------

    public function testFetchExportRowsExcludesRestrictedConfigsForNonSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('WHERE `is_restricted` IS NULL OR `is_restricted` = 0'))
            ->willReturn([]);

        $controller = $this->createController(security: $security, connection: $connection);
        $this->invokePrivate($controller, 'fetchExportRows');
    }

    public function testFetchExportRowsIncludesRestrictedConfigsForSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalNot($this->stringContains('WHERE')))
            ->willReturn([]);

        $controller = $this->createController(security: $security, connection: $connection);
        $this->invokePrivate($controller, 'fetchExportRows');
    }

    // --- toDate (private) -------------------------------------------------------------------------------

    public function testToDateParsesAValidDateString(): void
    {
        $controller = $this->createController();

        $date = $this->invokePrivate($controller, 'toDate', ['2026-01-15']);

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('2026-01-15', $date->format('Y-m-d'));
    }

    public function testToDateReturnsNullForEmptyOrInvalidValues(): void
    {
        $controller = $this->createController();

        $this->assertNull($this->invokePrivate($controller, 'toDate', [null]));
        $this->assertNull($this->invokePrivate($controller, 'toDate', ['']));
        $this->assertNull($this->invokePrivate($controller, 'toDate', ['not-a-date']));
    }

    // --- exportSql / exportCsv / exportJson --------------------------------------------------------------

    public function testExportCsvDelegatesToTableExporterWithCsvFormat(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['slug' => 'site-name']]);

        $tableExporter = $this->createMock(TableExporter::class);
        $tableExporter->expects($this->once())
            ->method('export')
            ->with(ExportFormat::Csv, 'site_config', [['slug' => 'site-name']])
            ->willReturn(new Response());

        $controller = $this->createController(connection: $connection, tableExporter: $tableExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportCsv($this->createAdminContext());
    }

    public function testExportContentMapsRowsIntoTheSiteConfigEnvelope(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'slug' => 'site-title',
            'label' => 'Site title',
            'is_sensitive' => 0,
            'is_restricted' => 0,
            'value' => 'My Site',
            'kind' => 'text',
            'group' => 'general',
            'description' => null,
            'severity' => null,
            'creation' => '2026-01-01 00:00:00',
            'modification' => '2026-01-01 00:00:00',
        ]]);

        $expectedItems = [[
            'slug' => 'site-title',
            'label' => 'Site title',
            'isSensitive' => false,
            'isRestricted' => false,
            'value' => 'My Site',
            'kind' => 'text',
            'group' => 'general',
            'description' => null,
            'severity' => null,
        ]];

        $contentExporter = $this->createMock(ContentExporter::class);
        $contentExporter->expects($this->once())
            ->method('export')
            ->with('site_config', $expectedItems)
            ->willReturn(new BinaryFileResponse(tempnam(sys_get_temp_dir(), 'export_test_')));

        $controller = $this->createController(connection: $connection, contentExporter: $contentExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportContent($this->createAdminContext());
    }

    public function testExportSqlDelegatesToConfigSqlExporter(): void
    {
        $exportResponse = new Response();
        $configSqlExporter = $this->createMock(ConfigSqlExporter::class);
        $configSqlExporter->expects($this->once())->method('export')->willReturn($exportResponse);

        $controller = $this->createController(configSqlExporter: $configSqlExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $response = $controller->exportSql($this->createAdminContext());

        $this->assertSame($exportResponse, $response);
    }

    public function testExportSqlDeniesAccessWithoutRoleAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportSql($this->createAdminContext());
    }

    public function testExportJsonDeniesAccessWithoutRoleAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportJson($this->createAdminContext());
    }

    // --- configureActions ---------------------------------------------------------------------------------

    public function testConfigureActionsBuildsWithoutError(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $controller = $this->createController(requestStack: $requestStack);

        // A real EasyAdmin runtime pre-populates default actions (EDIT...) before calling configureActions() - update() below assumes EDIT already exists on PAGE_INDEX
        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
        );

        $this->assertInstanceOf(Actions::class, $actions);
    }

    // Index-page row action becomes icon-only (see EasyAdminActionHelper::toIconOnly()), the label moving to the hover "title" instead
    public function testConfigureActionsSetsEditIconOnlyOnIndexPage(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $controller = $this->createController(requestStack: $requestStack);

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
        );

        $actionConfigDto = $actions->getAsDto(Crud::PAGE_INDEX);
        $editAction = $actionConfigDto->getAction(Crud::PAGE_INDEX, Action::EDIT);

        $this->assertFalse($editAction->getLabel());
        $this->assertSame(['title' => 'action.edit'], $editAction->getHtmlAttributes());
    }

    // Detail adds no information beyond what edit already shows (sensitive values are revealed there too) - disabled entirely, alongside new/delete since configs are fixed by the bundles' import json
    public function testConfigureActionsDisablesNewDeleteAndDetail(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $controller = $this->createController(requestStack: $requestStack);

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
        );

        $this->assertSame(
            [],
            array_diff([Action::NEW, Action::DELETE, Action::DETAIL], $actions->getAsDto(null)->getDisabledActions())
        );
    }

    // A "Cancel" action on the edit page lets the admin back out without saving, linking back to the index like EasyAdmin's own built-in actions do
    public function testConfigureActionsAddsCancelActionOnEditPageLinkingToIndex(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $controller = $this->createController(requestStack: $requestStack);

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
        );

        $cancelAction = $actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'cancel');

        $this->assertNotNull($cancelAction);
        $this->assertSame(Action::INDEX, $cancelAction->getCrudActionName());
    }

    // --- configureFilters -----------------------------------------------------------------------------------

    public function testConfigureFiltersBuildsWithoutError(): void
    {
        $controller = $this->createController();

        $filters = $controller->configureFilters(Filters::new());

        $this->assertInstanceOf(Filters::class, $filters);
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

    private function setContextEntity(ConfigCrudController $controller, Config $config): void
    {
        $entityDto = new EntityDto(Config::class, new ClassMetadata(Config::class), null, $config);
        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $contextProvider = $this->createStub(AdminContextProviderInterface::class);
        $contextProvider->method('getContext')->willReturn($context);

        $controller->setContainer($this->createContainer([
            AdminContextProviderInterface::class => $contextProvider,
        ]));
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

    // The bool/int/date kinds must override the raw string value with a real typed value via setValue(), since EasyAdmin's boolean/date templates and formatters read it directly
    public function testConfigureFieldsCastsBoolKindValueOnEditPage(): void
    {
        $config = (new Config())->setSlug('site-maintenance')->setKind(Config::TYPE_BOOL)->setValue(true);
        $controller = $this->createController();
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertTrue($valueField->getAsDto()->getValue());
        $this->assertTrue($valueField->getAsDto()->getFormTypeOptions()['data']);
    }

    // Sensitive fields must be pre-filled with the decrypted raw value, not the encrypted one, otherwise re-saving the form without changes would encrypt the already-encrypted string
    public function testConfigureFieldsPreFillsDecryptedValueForSensitiveFieldOnEditPage(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $config = (new Config())
            ->setSlug('api-key')
            ->setIsSensitive(true)
            ->setValue($vaultEncryptor->encrypt('secret-api-key'));

        $controller = $this->createController(vaultEncryptor: $vaultEncryptor);
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertSame('secret-api-key', $valueField->getAsDto()->getFormTypeOptions()['data']);
    }

    // Non-sensitive json kind is pre-filled with a re-indented value on edit
    public function testConfigureFieldsPrettyPrintsNonSensitiveJsonValueOnEditPage(): void
    {
        $config = (new Config())->setSlug('ai-roles')->setKind(Config::TYPE_JSON)->setValue('{"role":"admin","active":true}');
        $expected = "{\n    \"role\": \"admin\",\n    \"active\": true\n}";

        $controller = $this->createController();
        $this->setContextEntity($controller, $config);
        $editField = $this->findField($controller->configureFields('edit'), 'value');
        $this->assertInstanceOf(TextareaField::class, $editField);
        $this->assertSame($expected, $editField->getAsDto()->getFormTypeOptions()['data']);
    }

    // An invalid/malformed json value is kept as-is rather than dropped, so the admin can still see and fix it
    public function testConfigureFieldsKeepsInvalidJsonValueUnchangedOnEditPage(): void
    {
        $config = (new Config())->setSlug('ai-roles')->setKind(Config::TYPE_JSON)->setValue('not-json');

        $controller = $this->createController();
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertSame('not-json', $valueField->getAsDto()->getFormTypeOptions()['data']);
    }

    // A sensitive json config (e.g. an authorized-tokens map) needs a multi-line widget, unlike a plain sensitive string/key which uses TextField
    public function testConfigureFieldsUsesTextareaAndPrettyPrintsSensitiveJsonValueOnEditPage(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $config = (new Config())
            ->setSlug('ai-tokens')
            ->setKind(Config::TYPE_JSON)
            ->setIsSensitive(true)
            ->setValue($vaultEncryptor->encrypt('{"token":"abc"}'));

        $controller = $this->createController(vaultEncryptor: $vaultEncryptor);
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertInstanceOf(TextareaField::class, $valueField);
        $this->assertSame("{\n    \"token\": \"abc\"\n}", $valueField->getAsDto()->getFormTypeOptions()['data']);
    }

    // Font kind renders a ChoiceField built from FontChoiceType/FontRegistry, always topped up with the 3 CSS generics
    public function testConfigureFieldsRendersChoiceFieldForFontKindWhenRegistryHasFonts(): void
    {
        $config = (new Config())->setSlug('theme-font-family-title')->setKind(Config::TYPE_FONT)->setValue('Georgia');

        $controller = $this->createController(fontRegistry: $this->createFontRegistry(['Georgia', 'Roboto']));
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertInstanceOf(ChoiceField::class, $valueField);
        $this->assertSame(FontChoiceType::class, $valueField->getAsDto()->getFormType());
        $this->assertSame(
            ['Georgia' => 'Georgia', 'Roboto' => 'Roboto', 'serif' => 'serif', 'sans-serif' => 'sans-serif', 'monospace' => 'monospace'],
            $valueField->getAsDto()->getFormTypeOptions()['choices'],
        );
    }

    // With no font declared anywhere (no FontProviderInterface registered, or its file is empty/missing), the select
    // still offers the 3 CSS generics - never an empty, unusable <select>
    public function testConfigureFieldsOffersOnlyGenericFontFamiliesWhenRegistryIsEmpty(): void
    {
        $config = (new Config())->setSlug('theme-font-family-title')->setKind(Config::TYPE_FONT)->setValue(null);

        $controller = $this->createController();
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertInstanceOf(ChoiceField::class, $valueField);
        $this->assertSame(
            ['serif' => 'serif', 'sans-serif' => 'sans-serif', 'monospace' => 'monospace'],
            $valueField->getAsDto()->getFormTypeOptions()['choices'],
        );
    }

    // A value no longer declared in the font file (e.g. removed from the @font-face CSS) must stay selectable,
    // otherwise re-saving the form unchanged would silently wipe it
    public function testConfigureFieldsKeepsStaleFontValueSelectableWhenNoLongerInRegistry(): void
    {
        $config = (new Config())->setSlug('theme-font-family-title')->setKind(Config::TYPE_FONT)->setValue('Old Font');

        $controller = $this->createController(fontRegistry: $this->createFontRegistry(['Georgia', 'Roboto']));
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('edit'), 'value');

        $this->assertSame(
            ['Old Font' => 'Old Font', 'Georgia' => 'Georgia', 'Roboto' => 'Roboto', 'serif' => 'serif', 'sans-serif' => 'sans-serif', 'monospace' => 'monospace'],
            $valueField->getAsDto()->getFormTypeOptions()['choices'],
        );
    }

    // --- prettyJson (private) ---------------------------------------------------------------------------

    public function testPrettyJsonReturnsNullAndEmptyStringUnchanged(): void
    {
        $controller = $this->createController();

        $this->assertNull($this->invokePrivate($controller, 'prettyJson', [null]));
        $this->assertSame('', $this->invokePrivate($controller, 'prettyJson', ['']));
    }

    public function testPrettyJsonReindentsValidJson(): void
    {
        $controller = $this->createController();

        $result = $this->invokePrivate($controller, 'prettyJson', ['{"a":1,"b":[1,2]}']);

        $this->assertSame("{\n    \"a\": 1,\n    \"b\": [\n        1,\n        2\n    ]\n}", $result);
    }

    public function testPrettyJsonReturnsRawValueUnchangedWhenNotValidJson(): void
    {
        $controller = $this->createController();

        $this->assertSame('not-json', $this->invokePrivate($controller, 'prettyJson', ['not-json']));
    }
}
