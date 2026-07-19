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
use c975L\ConfigBundle\Form\Type\ReadonlyTextType;
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\ConfigAlertProvider;
use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Repository\ConfigRepository;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
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
        private readonly ConfigRepository $configRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Config::class;
    }

    // Without a "group" to scope to, shows the intermediate "pick a group" screen instead of EasyAdmin's own grid - same reasoning/pattern as SiteBundle's CollectionItemCrudController: the flat list became unreadable once enough groups accumulated
    public function index(AdminContext $context): KeyValueStore|Response
    {
        if (!$this->currentGroup()) {
            $showSensitive = $this->requestStack->getCurrentRequest()?->query->getBoolean('showSensitive', false) ?? false;

            return $this->render('@c975LConfig/management/config_crud_groups.html.twig', [
                'counts' => $this->configRepository->countsByGroup($showSensitive, $this->security->isGranted('ROLE_SUPER_ADMIN')),
                'alerts' => AlertBuilder::groupBySeverity($this->configAlertProvider->getAlerts()),
                'alertsTitle' => $this->translator->trans(
                    'label.items_not_filled_for',
                    ['%entity%' => $this->translator->trans('label.config', [], 'config')],
                    'config'
                ),
            ]);
        }

        return parent::index($context);
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

        // Group is fixed by the import json, never editable through the admin. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead
        $groupField = TextField::new('group')
            ->setLabel(t('label.group', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (?string $group): string =>
                $group ? $this->translator->trans('label.group_' . $group, [], 'config') : ''
            );
        if (Crud::PAGE_EDIT === $pageName && $entity instanceof Config) {
            $group = $entity->getGroup();
            $groupField->setFormTypeOptions([
                'data' => $group ? $this->translator->trans('label.group_' . $group, [], 'config') : '',
            ]);
        }

        // Severity is fixed by the import json, never editable through the admin. Rendered as a colored badge so an empty mandatory config stands out in the list
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

        // Index lists every config in one column: kind/sensitivity vary per row and can't be resolved from the (null) top-level entity, so decide via the row's $config argument
        if (Crud::PAGE_INDEX === $pageName) {
            $valueField = TextareaField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->formatValue(fn (?string $value, Config $config): string =>
                    $config->getIsSensitive() ? '••••••••' : ($value ?? '')
                );
        } elseif ($isSensitive && Crud::PAGE_EDIT === $pageName) {
            // Sensitive fields are pre-filled with the decrypted raw string value in edit (must stay the raw string, not configService->get()'s kind-cast value, otherwise a sensitive bool/int/date config like site-maintenance renders as "1"/"" instead of "true"/"false") (no need to mask with a password widget, the value is already shown in clear on the detail page)
            $decryptedValue = null;
            if (null !== $rawValue && '' !== $rawValue) {
                $decryptedValue = $this->vaultEncryptor->decrypt($rawValue);
            }

            // A sensitive json config (e.g. an authorized-tokens map) still needs a multi-line widget -
            // the single-line TextField below is fine for a sensitive string/key but unusable for json
            $valueField = (Config::TYPE_JSON === $kind ? TextareaField::new('value') : TextField::new('value'))
                ->setLabel(t('label.value_sensitive', [], 'config'))
                ->setFormTypeOptions([
                    'data' => Config::TYPE_JSON === $kind ? $this->prettyJson($decryptedValue) : $decryptedValue,
                ])
                ->setRequired(false);
        } elseif ($isSensitive) {
            // Detail page: reveal the decrypted value
            $valueField = TextareaField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setRequired(true)
                ->formatValue(function (?string $value) use ($kind): string {
                    if (null === $value || '' === $value) {
                        return $value ?? '';
                    }

                    $decrypted = $this->vaultEncryptor->decrypt($value);

                    return Config::TYPE_JSON === $kind ? ($this->prettyJson($decrypted) ?? '') : $decrypted;
                });
        } else {
            // Non-sensitive fields use a widget matching the config kind
            $valueField = match ($kind) {
                // The raw string value must be overridden with a real bool/DateTime via setValue(), since EasyAdmin's boolean/date templates and formatters read the field's raw value directly
                Config::TYPE_BOOL => BooleanField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setValue($this->configService->getBool($rawValue))
                    ->setFormTypeOptions(['data' => $this->configService->getBool($rawValue)]),
                Config::TYPE_INT => IntegerField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setFormTypeOptions(['data' => null !== $rawValue ? (int) $rawValue : null])
                    ->setRequired(false),
                Config::TYPE_DATE => DateField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setValue($this->toDate($rawValue))
                    ->setFormTypeOptions(['data' => $this->toDate($rawValue)])
                    ->setRequired(false),
                Config::TYPE_JSON => TextareaField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setHelp(t('help.value_json', [], 'config'))
                    ->setFormTypeOptions(['data' => $this->prettyJson($rawValue)])
                    ->formatValue(fn (?string $value): string => $this->prettyJson($value) ?? '')
                    ->setRequired(false),
                // Html kind is for the rare configs needing rich content, reuses EasyAdmin's own rich text editor (same widget as blocks)
                Config::TYPE_HTML => TextEditorField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setRequired(false),
                // Text kind is plain string (URLs, ids, emails...), a rich editor would wrap it in a <div>
                default => TextField::new('value')
                    ->setLabel(t('label.value', [], 'config'))
                    ->setRequired(false),
            };
        }

        // Edit form renders field help as plain text below the widget (unlike detail/index, which use a tooltip/popover). The json kind keeps its own dedicated help instead, since it needs to explain the expected format
        if (Crud::PAGE_EDIT === $pageName) {
            $valueField = $valueField->setHelp(Config::TYPE_JSON === $kind
                ? t('help.value_json', [], 'config')
                : t('help.value', [], 'config'));
        }

        // Description holds a 'site_config' translation key (description.xxx) once a bundle has migrated to it; trans() safely falls back to the raw text unchanged for bundles that haven't yet. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead. ReadonlyTextType renders a <p> instead of an <input> - see form_theme.html.twig
        $descriptionField = TextField::new('description')
            ->setLabel(t('label.description', [], 'config'))
            ->setFormType(ReadonlyTextType::class)
            ->setFormTypeOption('disabled', true)
            ->hideOnIndex()
            ->formatValue(fn (?string $description): string =>
                $description ? $this->translator->trans($description, [], 'site_config') : ''
            );
        if (Crud::PAGE_EDIT === $pageName && $entity instanceof Config) {
            $description = $entity->getDescription();
            $descriptionField->setFormTypeOptions([
                'data' => $description ? $this->translator->trans($description, [], 'site_config') : '',
            ]);
        }

        // Label uses a 'site_config' translation key derived from the slug (label.xxx), mirroring description's key format; trans() falls back to the raw key unchanged if not translated. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead
        $labelField = TextField::new('label')
            ->setLabel(t('label.label', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (string $label, Config $config): string =>
                $this->translator->trans($config->getLabelTranslationKey(), [], 'site_config')
            );
        if (Crud::PAGE_EDIT === $pageName && $entity instanceof Config) {
            $labelField->setFormTypeOptions([
                'data' => $this->translator->trans($entity->getLabelTranslationKey(), [], 'site_config'),
            ]);
        }

        return [
            IdField::new('id')
                ->setLabel(false)
                ->onlyOnIndex(),
            // Label/slug are fixed by the import json, never editable through the admin
            $labelField,
            TextField::new('slug')
                ->setLabel(t('label.slug', [], 'config'))
                ->setFormTypeOption('disabled', true),

            // Sensitive
            BooleanField::new('isSensitive')
                ->setLabel(t('label.is_sensitive', [], 'config'))
                ->setRequired(false)
                ->setFormTypeOption('disabled', true)
                ->setHelp(t('label.is_sensitive_help', [], 'config')),

            // Restricted — hides this config entirely below ROLE_SUPER_ADMIN, see denyAccessToRestrictedConfig()
            BooleanField::new('isRestricted')
                ->setLabel(t('label.is_restricted', [], 'config'))
                ->setRequired(false)
                ->setFormTypeOption('disabled', true)
                ->setHelp(t('label.is_restricted_help', [], 'config')),

            // Kind
            $kindField,

            // Group
            $groupField,

            // Severity
            $severityField,

            // Content — widget depends on kind (bool/int/date/text); sensitive values are masked in list/detail
            $valueField,

            // Fixed by the import json, never editable through the admin
            $descriptionField,

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

        // Only reachable once a group is selected (the "pick a group" screen replaces the grid entirely otherwise) - unsets "group" to go back to it
        $backToGroupsAction = Action::new('groups', t('label.config', [], 'config'), 'fas fa-layer-group')
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('group')
                ->generateUrl())
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $exportGroup)
            ->add(Crud::PAGE_INDEX, $toggleAction)
            ->add(Crud::PAGE_INDEX, $backToGroupsAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.detail', [], 'EasyAdminBundle'),
            ))
            ->setPermission('exportCsv', $this->configService->get('site-role-admin'))
            ->setPermission('toggleSensitive', $this->configService->get('site-role-admin'))
            ->setPermission('exportSql', 'ROLE_SUPER_ADMIN')
            ->setPermission('exportJson', 'ROLE_SUPER_ADMIN')
            // Configs are fixed by the bundles' import json: no manual creation, no deletion
            ->disable(Action::NEW, Action::DELETE)
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LConfig/management/config_crud_index.html.twig')
            ->addFormTheme('@c975LConfig/management/form_theme.html.twig')
            ->setEntityLabelInSingular(t('label.config', [], 'config'))
            ->setEntityLabelInPlural(t('label.config', [], 'config'))
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->setDefaultSort(['label' => 'ASC'])
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

    // "group" is deliberately not filterable here anymore - the index is already scoped to one group via the "pick a group" screen (see index()), and a second, conflicting group filter on top of that URL-driven scoping would just AND against it and silently return zero rows
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('label')
            ->add(ChoiceFilter::new('severity')
                ->setLabel(t('label.severity', [], 'config'))
                ->setTranslatableChoices($this->severityChoices()))
        ;
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
        $query = $searchDto->getQuery();

        // EasyAdmin's own search only matches raw DB columns, but "label"/"description" store
        // translation keys ("label.ai_help_llm_enabled"), never the rendered text an admin actually
        // searches for ("Donovan (Q&A) - Activé") - a non-empty query is instead resolved against every
        // row's *translated* label/description in memory (the whole config list is always small, a few
        // dozen rows) into a slug allowlist below. The query itself is blanked out before calling
        // parent() so its own SQL LIKE search (which would always find nothing against those keys)
        // doesn't also apply and AND against zero rows
        $matchingSlugs = null;
        if ('' !== $query) {
            $matchingSlugs = $this->slugsMatchingTranslatedQuery($query);
            $searchDto = new SearchDto(
                $searchDto->getRequest(),
                $searchDto->getSearchableProperties(),
                '',
                $searchDto->getDefaultSort(),
                $searchDto->getCustomSort(),
                $searchDto->getAppliedFilters(),
                $searchDto->getSearchMode(),
            );
        }

        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if (null !== $matchingSlugs) {
            // Never an empty IN() - Doctrine/DBAL reject an empty parameter array, and a slug that can't
            // exist cleanly yields zero rows when nothing matched
            $qb->andWhere('entity.slug IN (:matchingSlugs)')
                ->setParameter('matchingSlugs', $matchingSlugs ?: ['']);
        }

        $request = $this->requestStack->getCurrentRequest();
        $showSensitive = $request?->query->getBoolean('showSensitive', false);
        $qb->andWhere('entity.isSensitive = :isSensitive')
            ->setParameter('isSensitive', $showSensitive);

        // Configs flagged "restricted" (backup DB credentials, payment API keys...) stay out of the list entirely below ROLE_SUPER_ADMIN, see denyAccessToRestrictedConfig() (isRestricted is nullable: legacy rows not yet synced must NOT be treated as restricted)
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $qb->andWhere('entity.isRestricted IS NULL OR entity.isRestricted = :isRestricted')
                ->setParameter('isRestricted', false);
        }

        $group = $this->currentGroup();
        if (null !== $group) {
            $qb->andWhere('entity.group = :group')->setParameter('group', $group);
        }

        return $qb;
    }

    private function currentGroup(): ?string
    {
        $group = $this->requestStack->getCurrentRequest()?->query->get('group');

        return \is_string($group) && '' !== $group ? $group : null;
    }

    // Slugs whose slug/translated-label/translated-description contain $query (case-insensitive) - raw
    // SQL rather than the entity repository since this class already fetches this way (see
    // fetchExportRows()) and doesn't otherwise need Doctrine ORM here. A throwaway Config instance
    // reuses Config::getLabelTranslationKey() instead of duplicating its slug->key derivation
    private function slugsMatchingTranslatedQuery(string $query): array
    {
        $needle = mb_strtolower($query);
        $rows = $this->connection->fetchAllAssociative('SELECT `slug`, `description` FROM `site_config`');

        $matching = [];
        foreach ($rows as $row) {
            $slug = $row['slug'];
            $label = $this->translator->trans((new Config())->setSlug($slug)->getLabelTranslationKey(), [], 'site_config');
            $description = $row['description'] ? $this->translator->trans($row['description'], [], 'site_config') : '';

            if (str_contains(mb_strtolower($slug), $needle)
                || str_contains(mb_strtolower($label), $needle)
                || str_contains(mb_strtolower($description), $needle)
            ) {
                $matching[] = $slug;
            }
        }

        return $matching;
    }

    // A "restricted" config must stay invisible to any admin below ROLE_SUPER_ADMIN: it's a secret shared across the install (backup DB credentials, payment API keys...), never per-client application data
    private function denyAccessToRestrictedConfig(AdminContext $context): void
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof Config && $entity->getIsRestricted()) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        }
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->denyAccessToRestrictedConfig($context);

        return parent::detail($context);
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $this->denyAccessToRestrictedConfig($context);

        return parent::edit($context);
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

    // Updated config - encrypt sensitive value if provided, then invalidate cache
    public function updateEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        if ($config->getIsSensitive()) {
            $submitted = $config->getValue();

            // Non-empty submission: encrypt the new plain-text value. A blank submission clears the value: the field is pre-filled with the decrypted value on edit, so blank means the user actively emptied it, not that they left it untouched
            if (null !== $submitted && '' !== $submitted) {
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
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // Non-sensitive: INSERT ... ON DUPLICATE KEY UPDATE (syncs label/value/kind/group/description/severity); sensitive: INSERT IGNORE INTO (creates if missing, preserves production values)
        return $this->tableExporter->export(ExportFormat::Sql, 'site_config', $this->fetchExportRows(), [
            'primary_key' => 'slug',
            'exclude_from_update' => ['creation'],
            'insert_ignore_when' => fn (array $row): bool => (bool) $row['is_sensitive'],
        ]);
    }

    #[AdminRoute]
    public function exportCsv(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->tableExporter->export(ExportFormat::Csv, 'site_config', $this->fetchExportRows());
    }

    #[AdminRoute]
    public function exportJson(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->tableExporter->export(ExportFormat::Json, 'site_config', $this->fetchExportRows());
    }

    // Sensitive values are kept as stored (encrypted), never decrypted for export. Restricted configs (backup DB credentials, payment API keys...) are excluded below ROLE_SUPER_ADMIN, same restriction as the CRUD itself; is_restricted is nullable, legacy rows must NOT be treated as restricted
    private function fetchExportRows(): array
    {
        $sql = 'SELECT `label`, `slug`, `is_sensitive`, `is_restricted`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` FROM `site_config`';
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $sql .= ' WHERE `is_restricted` IS NULL OR `is_restricted` = 0';
        }
        $sql .= ' ORDER BY `slug`';

        return $this->connection->fetchAllAssociative($sql);
    }

    // Re-indents a stored json config value for readability; falls back to the raw string if it isn't valid JSON
    private function prettyJson(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return null === $decoded && 'null' !== trim($value)
            ? $value
            : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
