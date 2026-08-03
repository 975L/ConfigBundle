<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use App\Entity\User;
use c975L\ConfigBundle\Controller\Management\UserCrudController;
use c975L\ConfigBundle\Security\Voter\UserManagementVoter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\TableExporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Provider\FieldProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserCrudControllerTest extends TestCase
{
    private const AVAILABLE_ROLES = ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'];

    // What config/configs.json now ships: ROLE_SUPER_ADMIN is no longer declared there, the controller decides it
    private const DEFAULT_ROLES = ['ROLE_ADMIN', 'ROLE_EDITOR'];

    // AbstractCrudController::configureFields() only ever calls getDefaultFields() on whatever the container returns for FieldProvider::class - the real one is final readonly, so an anonymous object with that single method stands in for it
    private function createContainer(): Container
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

    private function createAdminContextProvider(?User $editedUser): AdminContextProvider
    {
        $requestStack = new RequestStack();

        if (null !== $editedUser) {
            $entityDto = new EntityDto(User::class, new ClassMetadata(User::class), null, $editedUser);
            $request = new Request();
            $request->attributes->set('easyadmin_context', AdminContext::forTesting(
                crudContext: CrudContext::forTesting(entityDto: $entityDto),
            ));
            $requestStack->push($request);
        }

        return new AdminContextProvider($requestStack);
    }

    private function createController(bool $actingUserIsSuperAdmin, ?User $editedUser = null, ?array $availableRoles = null): UserCrudController
    {
        $availableRoles ??= self::AVAILABLE_ROLES;

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => 'user-roles-available' === $slug ? $availableRoles : 'ROLE_ADMIN',
        );

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => 'ROLE_SUPER_ADMIN' === $attribute ? $actingUserIsSuperAdmin : true,
        );

        $controller = new UserCrudController(
            $configService,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(TableExporter::class),
            $security,
            $this->createStub(TranslatorInterface::class),
            $this->createAdminContextProvider($editedUser),
        );
        $controller->setContainer($this->createContainer());

        return $controller;
    }

    private function createUser(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles);

        return $user;
    }

    private function rolesField(UserCrudController $controller): mixed
    {
        foreach ($controller->configureFields(Crud::PAGE_EDIT) as $field) {
            if ('roles' === $field->getAsDto()->getProperty()) {
                return $field;
            }
        }

        return null;
    }

    // --- configureFields: roles ---------------------------------------------------------------------------

    public function testRolesChoicesOfferEveryAvailableRoleToASuperAdmin(): void
    {
        $field = $this->rolesField($this->createController(true, $this->createUser(['ROLE_ADMIN'])));

        $this->assertSame(self::AVAILABLE_ROLES, array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // ROLE_SUPER_ADMIN out of the choices means out of the submitted form's allowed values too (Symfony's ChoiceType rejects anything else), so a plain ROLE_ADMIN can't grant it to anyone, themselves included
    public function testRolesChoicesHideSuperAdminFromALesserAdmin(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_ADMIN'])));

        $this->assertSame(['ROLE_ADMIN', 'ROLE_EDITOR'], array_values(array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES))));
    }

    public function testRolesFieldIsEditableWhenTheEditedUserIsNotASuperAdmin(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_ADMIN'])));

        $this->assertArrayNotHasKey('disabled', $field->getAsDto()->getFormTypeOptions());
    }

    // The mirror of the above: a role a lesser admin can't grant is one they can't take away either - without the disabled field, saving a super admin's record would submit a set the role isn't in, silently demoting them
    public function testRolesFieldIsFrozenForALesserAdminEditingASuperAdmin(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_SUPER_ADMIN'])));

        $this->assertSame('disabled', $field->getAsDto()->getFormTypeOptions()['disabled'] ?? null);
    }

    // Frozen doesn't mean hidden: nothing is submitted back from a disabled field, so the role stays in the choices for the select to show what the edited user actually has
    public function testRolesChoicesKeepSuperAdminOnAFrozenField(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_SUPER_ADMIN'])));

        $this->assertContains('ROLE_SUPER_ADMIN', array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // The shipped default no longer declares ROLE_SUPER_ADMIN (it's granted once by c975l:site:create), so a super admin would lose the ability to grant it if the choices were only what the config holds
    public function testRolesChoicesOfferSuperAdminToASuperAdminEvenWhenTheConfigOmitsIt(): void
    {
        $field = $this->rolesField($this->createController(true, $this->createUser(['ROLE_ADMIN']), self::DEFAULT_ROLES));

        $this->assertSame(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'], array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // Same omission, but on the frozen field of a super admin edited by a lesser admin: without the role in the choices the select would show nothing of what that user actually has
    public function testRolesChoicesKeepSuperAdminOnAFrozenFieldEvenWhenTheConfigOmitsIt(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_SUPER_ADMIN']), self::DEFAULT_ROLES));

        $this->assertContains('ROLE_SUPER_ADMIN', array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // A lesser admin gets no extra role from the omission either - the config's own content is what's left
    public function testRolesChoicesHideSuperAdminFromALesserAdminWhenTheConfigOmitsIt(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_ADMIN']), self::DEFAULT_ROLES));

        $this->assertSame(['ROLE_ADMIN', 'ROLE_EDITOR'], array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // A role the config stopped listing would be dropped from the select's values and taken away on the next save, exactly what the ROLE_SUPER_ADMIN guard prevents for its own role
    public function testRolesChoicesKeepARoleTheEditedUserHoldsButTheConfigOmits(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_ADMIN', 'ROLE_MODERATOR']), self::DEFAULT_ROLES));

        $this->assertSame(['ROLE_ADMIN', 'ROLE_EDITOR', 'ROLE_MODERATOR'], array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // Keeping it is not granting it: the role is only ever offered on the edit page of a user who already holds it
    public function testRolesChoicesDontOfferARoleOutsideTheConfigToAUserWithoutIt(): void
    {
        $field = $this->rolesField($this->createController(false, $this->createUser(['ROLE_ADMIN']), self::DEFAULT_ROLES));

        $this->assertNotContains('ROLE_MODERATOR', array_keys($field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES)));
    }

    // A super admin acting on another super admin keeps a fully editable field - the guard is about lesser admins only
    public function testRolesFieldStaysEditableForASuperAdminEditingASuperAdmin(): void
    {
        $field = $this->rolesField($this->createController(true, $this->createUser(['ROLE_SUPER_ADMIN'])));

        $this->assertArrayNotHasKey('disabled', $field->getAsDto()->getFormTypeOptions());
    }

    // --- configureCrud ------------------------------------------------------------------------------------

    // EasyAdmin evaluates the entity permission per row: a plain role could only ever answer "any user", where the voter also keeps a lesser admin away from a super admin's own account, on every action
    public function testTheEntityPermissionIsDecidedByTheVoter(): void
    {
        $crud = $this->createController(false)->configureCrud(Crud::new());

        $this->assertSame(UserManagementVoter::MANAGE, $crud->getAsDto()->getEntityPermission());
    }
}
