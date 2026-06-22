<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;

class ConfigCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Config::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')
                ->onlyOnIndex(),
            TextField::new('label')
                ->setLabel('label.label')
                ->setRequired(true),
            SlugField::new('slug')
                ->setLabel('label.slug')
                ->setTargetFieldName('label')
                ->setRequired(true),

            // Kind
            ChoiceField::new('kind')
                ->setLabel('label.kind')
                ->setRequired(true)
                ->setChoices([
                    'Texte simple'   => Config::TYPE_TEXT,
                    'HTML / Éditeur' => Config::TYPE_HTML,
                    'Image / Média'  => Config::TYPE_IMAGE,
                    'Code'           => Config::TYPE_CODE,
                    'Booléen'        => Config::TYPE_BOOL,
                ]),

            // Content
            TextareaField::new('value')
                ->setLabel('label.value')
                ->setRequired(true),
            TextareaField::new('description')
                ->setLabel('label.description')
                ->setRequired(false)
                ->hideOnIndex(),

            // Dates
            DateTimeField::new('creation')
                ->setLabel('label.creation')
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
            DateTimeField::new('modification')
                ->setLabel('label.modification')
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, 'ROLE_ADMIN')
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission('ROLE_ADMIN')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('label')
        ;
    }

    // New config - Invalidate cache
    public function persistEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        $config->setCreation(new \DateTime());
        $config->setModification(new \DateTime());
        $this->setUser($config);

        parent::persistEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Updated config - Invalidate cache
    public function updateEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        $config->setModification(new \DateTime());
        $this->setUser($config);

        parent::updateEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Deleted config - Invalidate cache
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        // if ($config->getKind() === Config::TYPE_IMAGE && $config->getValue()) {
        //     $path = rtrim($this->uploadsDirectory, '/') . '/' . $config->getValue();
        //     if (file_exists($path)) {
        //         unlink($path);
        //     }
        // }

        parent::deleteEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Defines the user for the config
    private function setUser(Config $config): void
    {
        $user = $this->security->getUser();
        if (null !== $user) {
            $config->setUser($user);
        }
    }
}
