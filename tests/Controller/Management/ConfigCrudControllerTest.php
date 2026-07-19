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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\ConfigBundle\Service\VaultEncryptor;
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
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigCrudControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createController(
        ?Security $security = null,
        ?ConfigServiceInterface $configService = null,
        ?VaultEncryptor $vaultEncryptor = null,
        ?Connection $connection = null,
        ?RequestStack $requestStack = null,
        ?TableExporter $tableExporter = null,
    ): ConfigCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ConfigCrudController(
            $security ?? $this->createStub(Security::class),
            $configService ?? $this->createConfigService(),
            $vaultEncryptor ?? new VaultEncryptor(null),
            $connection ?? $this->createStub(Connection::class),
            $requestStack ?? new RequestStack(),
            $translator,
            $tableExporter ?? $this->createStub(TableExporter::class),
            $this->createStub(ConfigAlertProvider::class),
        );
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
            $this->createStub(ConfigAlertProvider::class),
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

    public function testExportSqlDeniesAccessBelowSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportSql($this->createAdminContext());
    }

    public function testExportJsonDeniesAccessBelowSuperAdmin(): void
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

        // A real EasyAdmin runtime pre-populates default actions (EDIT, DETAIL...) before calling configureActions() - update() below assumes EDIT/DETAIL already exist on PAGE_INDEX
        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
        );

        $this->assertInstanceOf(Actions::class, $actions);
    }

    // Index-page row actions become icon-only (see EasyAdminActionHelper::toIconOnly()), the label moving to the hover "title" instead
    public function testConfigureActionsSetsEditAndDetailIconOnlyOnIndexPage(): void
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
        $detailAction = $actionConfigDto->getAction(Crud::PAGE_INDEX, Action::DETAIL);

        $this->assertFalse($editAction->getLabel());
        $this->assertSame(['title' => 'action.edit'], $editAction->getHtmlAttributes());
        $this->assertFalse($detailAction->getLabel());
        $this->assertSame(['title' => 'action.detail'], $detailAction->getHtmlAttributes());
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

    // Non-sensitive json kind is pre-filled with a re-indented value on edit, and shows the same re-indented value on detail via formatValue()
    public function testConfigureFieldsPrettyPrintsNonSensitiveJsonValueOnEditAndDetailPages(): void
    {
        $config = (new Config())->setSlug('ai-roles')->setKind(Config::TYPE_JSON)->setValue('{"role":"admin","active":true}');
        $expected = "{\n    \"role\": \"admin\",\n    \"active\": true\n}";

        $controller = $this->createController();
        $this->setContextEntity($controller, $config);
        $editField = $this->findField($controller->configureFields('edit'), 'value');
        $this->assertInstanceOf(TextareaField::class, $editField);
        $this->assertSame($expected, $editField->getAsDto()->getFormTypeOptions()['data']);

        $controller = $this->createController();
        $this->setContextEntity($controller, $config);
        $detailField = $this->findField($controller->configureFields('detail'), 'value');
        $formatted = ($detailField->getAsDto()->getFormatValueCallable())($config->getValue(), $config);
        $this->assertSame($expected, $formatted);
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

    // Detail page reveals the decrypted sensitive json value, re-indented like its non-sensitive counterpart
    public function testConfigureFieldsPrettyPrintsDecryptedSensitiveJsonValueOnDetailPage(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $config = (new Config())
            ->setSlug('ai-tokens')
            ->setKind(Config::TYPE_JSON)
            ->setIsSensitive(true)
            ->setValue($vaultEncryptor->encrypt('{"token":"abc"}'));

        $controller = $this->createController(vaultEncryptor: $vaultEncryptor);
        $this->setContextEntity($controller, $config);

        $valueField = $this->findField($controller->configureFields('detail'), 'value');
        $formatted = ($valueField->getAsDto()->getFormatValueCallable())($config->getValue());

        $this->assertSame("{\n    \"token\": \"abc\"\n}", $formatted);
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
