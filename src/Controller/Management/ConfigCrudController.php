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
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\ConfigAlertProvider;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

class ConfigCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
        private readonly ConfigServiceInterface $configService,
        private readonly VaultEncryptor $vaultEncryptor,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly TableExporter $tableExporter,
        private readonly ConfigAlertProvider $configAlertProvider,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Config::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $context = $this->getContext();
        $entity = null !== $context ? $context->getEntity()->getInstance() : null;
        $isSensitive = $entity instanceof Config && true === $entity->getIsSensitive();

        // Kind is fixed by the import json, never editable through the admin
        $kindField = TextField::new('kind')
            ->setLabel(t('label.kind', [], 'config'))
            ->setFormTypeOption('disabled', true);

        // Group is fixed by the import json, never editable through the admin
        $groupField = TextField::new('group')
            ->setLabel(t('label.group', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (?string $group): string =>
                $group ? $this->translator->trans('label.group_' . $group, [], 'config') : ''
            );

        // Severity is fixed by the import json, never editable through the admin
        // Rendered as a colored badge so an empty mandatory config stands out in the list
        $severityFieldChoices = [];
        foreach (Config::SEVERITIES as $severity) {
            $severityFieldChoices[$severity] = t('label.severity_' . $severity, [], 'config');
        }

        $severityField = ChoiceField::new('severity')
            ->setLabel(t('label.severity', [], 'config'))
            ->setTranslatableChoices($severityFieldChoices)
            ->renderAsBadges([
                Config::SEVERITY_DANGER => 'danger',
                Config::SEVERITY_WARNING => 'warning',
                Config::SEVERITY_INFO => 'info',
            ])
            ->setFormTypeOption('disabled', true);

        $kind = $entity instanceof Config ? $entity->getKind() : Config::TYPE_TEXT;
        $rawValue = $entity instanceof Config ? $entity->getValue() : null;

        // Index lists every config in one column: kind/sensitivity vary per row and
        // can't be resolved from the (null) top-level entity, so decide via the row's $config argument
        if (Crud::PAGE_INDEX === $pageName) {
            $valueField = TextareaField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->formatValue(fn (?string $value, Config $config): string =>
                    $config->getIsSensitive() ? '••••••••' : ($value ?? '')
                );
        } elseif ($isSensitive && Crud::PAGE_EDIT === $pageName) {
            // Sensitive fields are pre-filled with the decrypted raw string value in edit
            // (must stay the raw string, not configService->get()'s kind-cast value, otherwise a
            // sensitive bool/int/date config like site-maintenance renders as "1"/"" instead of "true"/"false")
            // (no need to mask with a password widget, the value is already shown in clear on the detail page)
            $decryptedValue = null;
            if (null !== $rawValue && '' !== $rawValue) {
                $decryptedValue = $this->vaultEncryptor->decrypt($rawValue);
            }

            $valueField = TextField::new('value')
                ->setLabel(t('label.value_sensitive', [], 'config'))
                ->setFormTypeOptions([
                    'data' => $decryptedValue,
                ])
                ->setRequired(true);
        } elseif ($isSensitive) {
            // Detail page: reveal the decrypted value
            $valueField = TextareaField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setRequired(true)
                ->formatValue(fn (?string $value): string =>
                    null === $value || '' === $value ? ($value ?? '') : $this->vaultEncryptor->decrypt($value)
                );
        } else {
            // Non-sensitive fields use a widget matching the config kind
            $valueField = match ($kind) {
                // The raw string value must be overridden with a real bool/DateTime via setValue(),
                // since EasyAdmin's boolean/date templates and formatters read the field's raw value directly
                Config::TYPE_BOOL => BooleanField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setValue($this->configService->getBool($rawValue))
                    ->setFormTypeOptions(['data' => $this->configService->getBool($rawValue)]),
                Config::TYPE_INT => IntegerField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setFormTypeOptions(['data' => null !== $rawValue ? (int) $rawValue : null])
                    ->setRequired(true),
                Config::TYPE_DATE => DateField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setValue($this->toDate($rawValue))
                    ->setFormTypeOptions(['data' => $this->toDate($rawValue)])
                    ->setRequired(true),
                Config::TYPE_JSON => TextareaField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setHelp(t('help.value_json', [], 'config'))
                    ->setRequired(false),
                // Html kind is for the rare configs needing rich content, reuses EasyAdmin's own rich text editor (same widget as blocks)
                Config::TYPE_HTML => TextEditorField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setRequired(true),
                // Text kind is plain string (URLs, ids, emails...), a rich editor would wrap it in a <div>
                default => TextField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setRequired(true),
            };
        }

        // Edit form renders field help as plain text below the widget (unlike detail/index, which use a tooltip/popover)
        // The json kind keeps its own dedicated help instead, since it needs to explain the expected format
        if (Crud::PAGE_EDIT === $pageName) {
            $valueField = $valueField->setHelp(Config::TYPE_JSON === $kind
                ? t('help.value_json', [], 'config')
                : t('help.value', [], 'config'));
        }

        return [
            IdField::new('id')
                ->setLabel(false)
                ->onlyOnIndex(),
            // Label/slug are fixed by the import json, never editable through the admin
            TextField::new('label')
                ->setLabel(t('label.label', [], 'config'))
                ->setFormTypeOption('disabled', true),
            TextField::new('slug')
                ->setLabel(t('label.slug', [], 'config'))
                ->setFormTypeOption('disabled', true),

            // Sensitive
            BooleanField::new('isSensitive')
                ->setLabel(t('label.is_sensitive', [], 'config'))
                ->setRequired(false)
                ->setFormTypeOption('disabled', true)
                ->setHelp(t('label.is_sensitive_help', [], 'config')),

            // Kind
            $kindField,

            // Group
            $groupField,

            // Severity
            $severityField,

            // Content — widget depends on kind (bool/int/date/text); sensitive values are masked in list/detail
            $valueField,

            // Fixed by the import json, never editable through the admin. description holds a
            // 'site_config' translation key (label.xxx) once a bundle has migrated to it; trans()
            // safely falls back to the raw text unchanged for bundles that haven't yet
            TextField::new('description')
                ->setLabel(t('label.description', [], 'config'))
                ->setFormTypeOption('disabled', true)
                ->hideOnIndex()
                ->formatValue(fn (?string $description): string =>
                    $description ? $this->translator->trans($description, [], 'site_config') : ''
                ),

            // Dates
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
            DateTimeField::new('modification')
                ->setLabel(t('label.modification', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $exportGroup = ActionGroup::new('export', t('label.export', [], 'config'), 'fa fa-download')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('exportSql', 'SQL')->linkToCrudAction('exportSql'))
            ->addAction(Action::new('exportCsv', 'CSV')->linkToCrudAction('exportCsv'))
            ->addAction(Action::new('exportJson', 'JSON')->linkToCrudAction('exportJson'))
        ;

        $request = $this->requestStack->getCurrentRequest();
        $showSensitive = $request?->query->getBoolean('showSensitive', false);

        $params = $request?->query->all() ?? [];
        if ($showSensitive) {
            unset($params['showSensitive']);
            $sensitiveLabel = t('label.hide_sensitive', [], 'config');
            $sensitiveIcon = 'fa fa-eye-slash';
            $sensitiveCss = 'btn btn-warning btn-sm';
        } else {
            $params['showSensitive'] = 1;
            $sensitiveLabel = t('label.show_sensitive', [], 'config');
            $sensitiveIcon = 'fa fa-eye';
            $sensitiveCss = 'btn btn-outline-warning btn-sm';
        }

        $toggleAction = Action::new('toggleSensitive', $sensitiveLabel, $sensitiveIcon)
            ->linkToUrl('?' . http_build_query($params))
            ->addCssClass($sensitiveCss)
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $exportGroup)
            ->add(Crud::PAGE_INDEX, $toggleAction)
            ->setPermission('exportSql', $this->configService->get('site-role-needed'))
            ->setPermission('exportCsv', $this->configService->get('site-role-needed'))
            ->setPermission('exportJson', $this->configService->get('site-role-needed'))
            ->setPermission('toggleSensitive', $this->configService->get('site-role-needed'))
            // Configs are fixed by the bundles' import json: no manual creation, no deletion
            ->disable(Action::NEW, Action::DELETE)
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LConfig/management/config_crud_index.html.twig')
            ->setEntityLabelInSingular(t('label.config', [], 'config'))
            ->setEntityLabelInPlural(t('label.config', [], 'config'))
            ->setEntityPermission($this->configService->get('site-role-needed'))
            ->setDefaultSort(['group' => 'ASC', 'label' => 'ASC'])
        ;
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $responseParameters->set('alerts', AlertBuilder::groupBySeverity($this->configAlertProvider->getAlerts()));
            $responseParameters->set('alertsTitle', $this->translator->trans(
                'label.items_not_filled_for',
                ['%entity%' => $this->translator->trans('label.config', [], 'config')],
                'config'
            ));
        }

        return $responseParameters;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('label')
            ->add(ChoiceFilter::new('group')
                ->setLabel(t('label.group', [], 'config'))
                ->setTranslatableChoices($this->groupChoices()))
            ->add(ChoiceFilter::new('severity')
                ->setLabel(t('label.severity', [], 'config'))
                ->setTranslatableChoices($this->severityChoices()))
        ;
    }

    // Maps each fixed group slug (Config::GROUPS) to its translated label
    private function groupChoices(): array
    {
        $choices = [];
        foreach (Config::GROUPS as $group) {
            $choices[$group] = t('label.group_' . $group, [], 'config');
        }

        return $choices;
    }

    // Maps each fixed severity slug (Config::SEVERITIES) to its translated label
    private function severityChoices(): array
    {
        $choices = [];
        foreach (Config::SEVERITIES as $severity) {
            $choices[$severity] = t('label.severity_' . $severity, [], 'config');
        }

        return $choices;
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

    // New config - encrypt sensitive value if provided, then invalidate cache
    public function persistEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        if ($config->getIsSensitive() && null !== $config->getValue() && '' !== $config->getValue()) {
            $config->setValue($this->vaultEncryptor->encrypt($config->getValue()));
        }

        $config->setCreation(new \DateTime());
        $config->setModification(new \DateTime());
        $this->setUser($config);

        parent::persistEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Updated config - preserve existing encrypted value when field left empty, encrypt new value otherwise
    public function updateEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        if ($config->getIsSensitive()) {
            $submitted = $config->getValue();

            if (null === $submitted || '' === $submitted) {
                // Empty submission: restore the original value, encrypting it if it was still plain-text
                // (e.g. a default loaded before C975L_VAULT_KEY was configured)
                $original = $entityManager->getUnitOfWork()->getOriginalEntityData($config)['value'] ?? null;
                if (null !== $original && '' !== $original && !$this->vaultEncryptor->isEncrypted($original)) {
                    $original = $this->vaultEncryptor->encrypt($original);
                }
                $config->setValue($original);
            } else {
                // Non-empty submission: encrypt the new plain-text value
                $config->setValue($this->vaultEncryptor->encrypt($submitted));
            }
        }

        $config->setModification(new \DateTime());
        $this->setUser($config);

        parent::updateEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Deleted config - Invalidate cache
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        parent::deleteEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    #[AdminRoute]
    public function exportSql(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        // Non-sensitive: INSERT ... ON DUPLICATE KEY UPDATE (syncs label/value/kind/group/description/severity)
        // Sensitive:     INSERT IGNORE INTO (creates if missing, preserves production values)
        return $this->tableExporter->export(ExportFormat::Sql, 'site_config', $this->fetchExportRows(), [
            'primary_key' => 'slug',
            'exclude_from_update' => ['creation'],
            'insert_ignore_when' => fn (array $row): bool => (bool) $row['is_sensitive'],
        ]);
    }

    #[AdminRoute]
    public function exportCsv(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        return $this->tableExporter->export(ExportFormat::Csv, 'site_config', $this->fetchExportRows());
    }

    #[AdminRoute]
    public function exportJson(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        return $this->tableExporter->export(ExportFormat::Json, 'site_config', $this->fetchExportRows());
    }

    // Sensitive values are kept as stored (encrypted), never decrypted for export
    private function fetchExportRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT `label`, `slug`, `is_sensitive`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` FROM `site_config` ORDER BY `slug`'
        );
    }

    // Parses a stored date value, tolerating empty/invalid strings
    private function toDate(?string $value): ?\DateTime
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
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
