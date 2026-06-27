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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

class ConfigCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
        private readonly ConfigServiceInterface $configService,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
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
                ->setLabel(new TranslatableMessage('label.label', [], 'config'))
                ->setRequired(true),
            SlugField::new('slug')
                ->setLabel(new TranslatableMessage('label.slug', [], 'config'))
                ->setTargetFieldName('label')
                ->setRequired(true),

            // Sensitive
            BooleanField::new('isSensitive')
                ->setLabel(new TranslatableMessage('label.is_sensitive', [], 'config'))
                ->setRequired(false)
                ->setHelp(new TranslatableMessage('label.is_sensitive_help', [], 'config')),

            // Kind
            ChoiceField::new('kind')
                ->setLabel(new TranslatableMessage('label.kind', [], 'config'))
                ->setRequired(true)
                ->setTranslatableChoices([
                    Config::TYPE_BOOL => t('label.boolean', [], 'config'),
                    Config::TYPE_INT => t('label.int', [], 'config'),
                    Config::TYPE_TEXT => t('label.text', [], 'config'),
                ]),

            // Content — sensitive values are masked in list/detail, editable in form
            TextareaField::new('value')
                ->setLabel(new TranslatableMessage('label.value', [], 'config'))
                ->setRequired(true),
            TextareaField::new('description')
                ->setLabel(new TranslatableMessage('label.description', [], 'config'))
                ->setRequired(false)
                ->hideOnIndex(),

            // Dates
            DateTimeField::new('creation')
                ->setLabel(new TranslatableMessage('label.creation', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
            DateTimeField::new('modification')
                ->setLabel(new TranslatableMessage('label.modification', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $exportAction = Action::new('exportSql', 'Export SQL', 'fa fa-download')
            ->linkToCrudAction('exportSql')
            ->addCssClass('btn btn-secondary btn-sm')
            ->createAsGlobalAction();

        $request = $this->requestStack->getCurrentRequest();
        $showSensitive = $request?->query->getBoolean('showSensitive', false);

        $params = $request?->query->all() ?? [];
        if ($showSensitive) {
            unset($params['showSensitive']);
            $sensitiveLabel = new TranslatableMessage('label.hide_sensitive', [], 'config');
            $sensitiveIcon = 'fa fa-eye-slash';
            $sensitiveCss = 'btn btn-warning btn-sm';
        } else {
            $params['showSensitive'] = 1;
            $sensitiveLabel = new TranslatableMessage('label.show_sensitive', [], 'config');
            $sensitiveIcon = 'fa fa-eye';
            $sensitiveCss = 'btn btn-outline-warning btn-sm';
        }

        $toggleAction = Action::new('toggleSensitive', $sensitiveLabel, $sensitiveIcon)
            ->linkToUrl('?' . http_build_query($params))
            ->addCssClass($sensitiveCss)
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $exportAction)
            ->add(Crud::PAGE_INDEX, $toggleAction)
            ->setPermission(Action::NEW, $this->configService->get('site-role-needed'))
            ->setPermission('exportSql', $this->configService->get('site-role-needed'))
            ->setPermission('toggleSensitive', $this->configService->get('site-role-needed'))
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-needed'))
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('label')
        ;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $request = $this->requestStack->getCurrentRequest();
        $showSensitive = $request?->query->getBoolean('showSensitive', false);
        $qb->andWhere('entity.isSensitive = :isSensitive')
            ->setParameter('isSensitive', $showSensitive);

        return $qb;
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

    #[AdminRoute]
    public function exportSql(AdminContext $context): Response
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `label`, `slug`, `is_sensitive`, `value`, `kind`, `description`, `creation`, `modification` FROM `site_config` ORDER BY `slug`'
        );

        $now = date('Y-m-d H:i:s');
        $lines = [
            "-- site_config export -- {$now}",
            '-- Non-sensitive: INSERT ... ON DUPLICATE KEY UPDATE (syncs label/value/kind/description)',
            '-- Sensitive:     INSERT IGNORE INTO (creates if missing, preserves production values)',
            'SET NAMES utf8mb4;',
            '',
        ];

        foreach ($rows as $row) {
            $sensitive = (bool) $row['is_sensitive'];
            $cols = '(`label`, `slug`, `is_sensitive`, `value`, `kind`, `description`, `creation`, `modification`)';
            $vals = sprintf(
                '(%s, %s, %d, %s, %s, %s, %s, %s)',
                $this->sqlQuote($row['label']),
                $this->sqlQuote($row['slug']),
                (int) $row['is_sensitive'],
                $this->sqlQuote($row['value']),
                $this->sqlQuote($row['kind']),
                $this->sqlQuote($row['description']),
                $this->sqlQuote($row['creation']),
                $this->sqlQuote($row['modification']),
            );

            if ($sensitive) {
                $lines[] = "INSERT IGNORE INTO `site_config` {$cols} VALUES {$vals};";
            } else {
                $lines[] = "INSERT INTO `site_config` {$cols} VALUES {$vals}"
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' `label`=VALUES(`label`), `is_sensitive`=VALUES(`is_sensitive`), `value`=VALUES(`value`),'
                    . ' `kind`=VALUES(`kind`), `description`=VALUES(`description`), `modification`=VALUES(`modification`);';
            }
        }

        $sql = implode("\n", $lines) . "\n";
        $filename = 'site_config_' . date('Ymd_His') . '.sql';

        return new Response($sql, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function sqlQuote(?string $value): string
    {
        return $value === null ? 'NULL' : $this->connection->quote($value);
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
