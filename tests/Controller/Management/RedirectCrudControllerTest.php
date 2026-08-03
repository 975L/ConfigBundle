<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\RedirectCrudController;
use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Provider\FieldProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class RedirectCrudControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    // The roles configs.json declares, each config answering with its own slug so a test can tell which one a permission came from
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): string => 'site-role-admin' === $slug ? 'ROLE_ADMIN' : 'ROLE_EDITOR'
        );

        return $configService;
    }

    private function createController(?Connection $connection = null, ?TableExporter $tableExporter = null): RedirectCrudController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new RedirectCrudController(
            $this->createConfigService(),
            $connection ?? $this->createStub(Connection::class),
            $tableExporter ?? $this->createStub(TableExporter::class),
            $translator,
        );
    }

    // AbstractCrudController::configureFields() only ever calls getDefaultFields() on whatever the container returns for FieldProvider::class - the real one is final readonly, so an anonymous object with that single method stands in for it
    private function createFieldContainer(): Container
    {
        $container = new Container();
        $container->set(FieldProvider::class, new class {
            public function getDefaultFields(string $pageName): iterable
            {
                return [];
            }
        });

        return $container;
    }

    // A real EasyAdmin runtime pre-populates the default actions before calling configureActions(), which update() then assumes are there
    private function configureActions(RedirectCrudController $controller): Actions
    {
        return $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );
    }

    private function fieldsByProperty(RedirectCrudController $controller): array
    {
        $controller->setContainer($this->createFieldContainer());

        $fields = [];
        foreach ($controller->configureFields(Crud::PAGE_NEW) as $field) {
            $fields[$field->getAsDto()->getProperty()] = $field;
        }

        return $fields;
    }

    public function testGetEntityFqcnIsTheRedirectEntity(): void
    {
        $this->assertSame(Redirect::class, RedirectCrudController::getEntityFqcn());
    }

    public function testConfigureFieldsExposesTheFourEditableColumns(): void
    {
        $fields = $this->fieldsByProperty($this->createController());

        $this->assertSame(['id', 'fromPath', 'toUrl', 'permanent', 'gone'], array_keys($fields));
    }

    // A "gone" row has nothing to redirect to, so the destination can't be required at the form level - Redirect::$toUrl carries the conditional constraint enforcing it for every other row
    public function testConfigureFieldsLeavesTheDestinationOptionalWhileRequiringTheSourcePath(): void
    {
        $fields = $this->fieldsByProperty($this->createController());

        $this->assertTrue($fields['fromPath']->getAsDto()->getFormTypeOption('required'));
        $this->assertFalse($fields['toUrl']->getAsDto()->getFormTypeOption('required'));
    }

    public function testConfigureActionsBuildsWithoutError(): void
    {
        $this->assertInstanceOf(Actions::class, $this->configureActions($this->createController()));
    }

    // Editing a redirect is an editor's job, deleting one an admin's: a wrong deletion silently brings back a 404 nobody is watching for
    public function testConfigureActionsRequiresAnAdminToDeleteButOnlyAnEditorToWrite(): void
    {
        $permissions = $this->configureActions($this->createController())->getAsDto(null)->getActionPermissions();

        $this->assertSame('ROLE_EDITOR', $permissions[Action::INDEX] ?? null);
        $this->assertSame('ROLE_EDITOR', $permissions[Action::NEW] ?? null);
        $this->assertSame('ROLE_EDITOR', $permissions[Action::EDIT] ?? null);
        $this->assertSame('ROLE_ADMIN', $permissions[Action::DELETE] ?? null);
    }

    // The SQL and JSON exports carry every row verbatim, the CSV one being the read-only view an admin may need
    public function testConfigureActionsRestrictsTheSqlAndJsonExportsToSuperAdmin(): void
    {
        $permissions = $this->configureActions($this->createController())->getAsDto(null)->getActionPermissions();

        $this->assertSame('ROLE_SUPER_ADMIN', $permissions['exportSql'] ?? null);
        $this->assertSame('ROLE_SUPER_ADMIN', $permissions['exportJson'] ?? null);
        $this->assertSame('ROLE_ADMIN', $permissions['exportCsv'] ?? null);
    }

    // Detail adds no information beyond what edit already shows
    public function testConfigureActionsDisablesDetail(): void
    {
        $this->assertContains(Action::DETAIL, $this->configureActions($this->createController())->getAsDto(null)->getDisabledActions());
    }

    // Index-page row actions become icon-only (see EasyAdminActionHelper::toIconOnly()), the label moving to the hover "title"
    public function testConfigureActionsSetsTheIndexRowActionsIconOnly(): void
    {
        $actionConfigDto = $this->configureActions($this->createController())->getAsDto(Crud::PAGE_INDEX);

        $this->assertFalse($actionConfigDto->getAction(Crud::PAGE_INDEX, Action::EDIT)->getLabel());
        $this->assertFalse($actionConfigDto->getAction(Crud::PAGE_INDEX, Action::DELETE)->getLabel());
    }

    // A "Cancel" action on the new/edit pages lets the admin back out without saving
    public function testConfigureActionsAddsCancelOnBothWriteScreens(): void
    {
        $actions = $this->configureActions($this->createController());

        $this->assertSame(Action::INDEX, $actions->getAsDto(Crud::PAGE_NEW)->getAction(Crud::PAGE_NEW, 'cancel')->getCrudActionName());
        $this->assertSame(Action::INDEX, $actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'cancel')->getCrudActionName());
    }

    public function testConfigureCrudGivesTheEntityTheEditorPermission(): void
    {
        $crud = $this->createController()->configureCrud(Crud::new());

        $this->assertSame('ROLE_EDITOR', $crud->getAsDto()->getEntityPermission());
    }

    public function testConfigureFiltersOffersBothPathColumns(): void
    {
        $filters = $this->createController()->configureFilters(Filters::new());

        $this->assertSame(['fromPath', 'toUrl'], array_keys($filters->getAsDto()->all()));
    }

    public function testExportCsvDelegatesToTheExporterWithTheRedirectRows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['from_path' => '/old', 'to_url' => '/new']]);

        $tableExporter = $this->createMock(TableExporter::class);
        $tableExporter->expects($this->once())
            ->method('export')
            ->with(ExportFormat::Csv, 'site_redirect', [['from_path' => '/old', 'to_url' => '/new']])
            ->willReturn(new Response());

        $controller = $this->createController($connection, $tableExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportCsv(AdminContext::forTesting());
    }

    public function testExportSqlAsksTheExporterForTheSqlFormat(): void
    {
        $tableExporter = $this->createMock(TableExporter::class);
        $tableExporter->expects($this->once())
            ->method('export')
            ->with(ExportFormat::Sql, 'site_redirect', [])
            ->willReturn(new Response());

        $controller = $this->createController(tableExporter: $tableExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportSql(AdminContext::forTesting());
    }

    public function testExportJsonAsksTheExporterForTheJsonFormat(): void
    {
        $tableExporter = $this->createMock(TableExporter::class);
        $tableExporter->expects($this->once())
            ->method('export')
            ->with(ExportFormat::Json, 'site_redirect', [])
            ->willReturn(new Response());

        $controller = $this->createController(tableExporter: $tableExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportJson(AdminContext::forTesting());
    }

    // The action permission is EasyAdmin's own doing, the action itself checks again - a route reached directly answers the same way the button would
    public function testEveryExportDeniesAccessToAnUnauthorizedUser(): void
    {
        foreach (['exportSql', 'exportCsv', 'exportJson'] as $action) {
            $controller = $this->createController();
            $controller->setContainer($this->createContainer([
                'security.authorization_checker' => $this->createAuthorizationChecker(false),
            ]));

            try {
                $controller->{$action}(AdminContext::forTesting());
                $this->fail(sprintf('%s() should have denied access', $action));
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
