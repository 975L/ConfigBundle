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
use c975L\ConfigBundle\Management\ConfigExportProvider;
use c975L\ConfigBundle\Management\ConfigImportProvider;
use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\ConfigBundle\Service\VaultEncryptor;
use c975L\UiBundle\Form\FontChoiceType;
use c975L\UiBundle\Registry\FontRegistry;
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
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
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
        private readonly ConfigSqlExporter $configSqlExporter,
        private readonly ContentExporter $contentExporter,
        private readonly ConfigExportProvider $configExportProvider,
        private readonly ConfigAlertProvider $configAlertProvider,
        private readonly ConfigRepository $configRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly FontRegistry $fontRegistry,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Config::class;
    }

    // Without a "group" to scope to, shows the intermediate "pick a group" screen instead of EasyAdmin's own grid - same reasoning/pattern as SiteBundle's CollectionItemCrudController: the flat list became unreadable once enough groups accumulated. A search query typed from that screen bypasses it though: the search box is otherwise displayed but dead (nothing on the "pick a group" screen reads it), so a non-empty query instead falls through to the grid, unscoped by group (createIndexQueryBuilder() only filters by group when currentGroup() is set), searching across every group at once
    public function index(AdminContext $context): KeyValueStore|Response
    {
        if ($this->showGroupsScreen()) {
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
        $config = $entity instanceof Config ? $entity : null;
        $isEdit = Crud::PAGE_EDIT === $pageName;

        return [
            IdField::new('id')
                ->setLabel(false)
                ->onlyOnIndex(),
            // Label/slug are fixed by the import json, never editable through the admin
            $this->labelField($isEdit, $config),
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

            // Kind is fixed by the import json, never editable through the admin
            TextField::new('kind')
                ->setLabel(t('label.kind', [], 'config'))
                ->setFormTypeOption('disabled', true),

            // Group
            $this->groupField($isEdit, $config),

            // Severity
            $this->severityField(),

            // Content — widget depends on kind (bool/int/date/text); sensitive values are masked on the index list, revealed only on edit
            $this->valueField($pageName, $config),

            // Fixed by the import json, never editable through the admin
            $this->descriptionField($isEdit, $config),

            // Dates - shown read-only on edit rather than onlyOnDetail() now that the detail page is gone (see configureActions())
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->hideOnIndex(),
            DateTimeField::new('modification')
                ->setLabel(t('label.modification', [], 'config'))
                ->setFormTypeOption('disabled', 'disabled')
                ->hideOnIndex(),
        ];
    }

    // Label uses a 'site_config' translation key derived from the slug (label.xxx), mirroring description's key format; trans() falls back to the raw key unchanged if not translated. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead
    private function labelField(bool $isEdit, ?Config $config): TextField
    {
        $field = TextField::new('label')
            ->setLabel(t('label.label', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (string $label, Config $rowConfig): string =>
                $this->translator->trans($rowConfig->getLabelTranslationKey(), [], 'site_config')
            );

        if ($isEdit && null !== $config) {
            $field->setFormTypeOptions([
                'data' => $this->translator->trans($config->getLabelTranslationKey(), [], 'site_config'),
            ]);
        }

        return $field;
    }

    // Group is fixed by the import json, never editable through the admin. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead
    private function groupField(bool $isEdit, ?Config $config): TextField
    {
        $field = TextField::new('group')
            ->setLabel(t('label.group', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (?string $group): string => $this->groupLabel($group));

        if ($isEdit && null !== $config) {
            $field->setFormTypeOptions(['data' => $this->groupLabel($config->getGroup())]);
        }

        return $field;
    }

    private function groupLabel(?string $group): string
    {
        return $group ? $this->translator->trans('label.group_' . $group, [], 'config') : '';
    }

    // Description holds a 'site_config' translation key (description.xxx) once a bundle has migrated to it; trans() safely falls back to the raw text unchanged for bundles that haven't yet. formatValue() only runs on index/detail (EasyAdmin skips it for disabled form fields), so the edit page's disabled input needs the translated text injected via form data instead. ReadonlyTextType renders a <p> instead of an <input> - see form_theme.html.twig
    private function descriptionField(bool $isEdit, ?Config $config): TextField
    {
        $field = TextField::new('description')
            ->setLabel(t('label.description', [], 'config'))
            ->setFormType(ReadonlyTextType::class)
            ->setFormTypeOption('disabled', true)
            ->hideOnIndex()
            ->formatValue(fn (?string $description): string => $this->descriptionLabel($description));

        if ($isEdit && null !== $config) {
            $field->setFormTypeOptions(['data' => $this->descriptionLabel($config->getDescription())]);
        }

        return $field;
    }

    private function descriptionLabel(?string $description): string
    {
        return $description ? $this->translator->trans($description, [], 'site_config') : '';
    }

    // Severity is fixed by the import json, never editable through the admin. Rendered as a colored badge so an empty mandatory config stands out in the list
    private function severityField(): ChoiceField
    {
        $choices = [];
        foreach (Config::SEVERITIES as $severity) {
            $choices[$severity] = t('label.severity_' . $severity, [], 'config');
        }

        return ChoiceField::new('severity')
            ->setLabel(t('label.severity', [], 'config'))
            ->setTranslatableChoices($choices)
            ->renderAsBadges([
                Config::SEVERITY_DANGER => 'danger',
                Config::SEVERITY_WARNING => 'warning',
                Config::SEVERITY_INFO => 'info',
            ])
            ->setFormTypeOption('disabled', true);
    }

    // Three different widgets behind one column: masked on the index, revealed (decrypted) on a sensitive entry's edit form, and matching the entry's own kind otherwise
    private function valueField(string $pageName, ?Config $config): FieldInterface
    {
        $kind = null !== $config ? $config->getKind() : Config::TYPE_TEXT;
        $rawValue = $config?->getValue();
        $isEdit = Crud::PAGE_EDIT === $pageName;

        $field = match (true) {
            Crud::PAGE_INDEX === $pageName => $this->maskedValueField(),
            $isEdit && true === $config?->getIsSensitive() => $this->sensitiveValueField($kind, $rawValue),
            default => $this->kindValueField($kind, $rawValue),
        };

        // Edit form renders field help as plain text below the widget (unlike detail/index, which use a tooltip/popover). The json kind keeps its own dedicated help instead, since it needs to explain the expected format
        if ($isEdit) {
            $field = $field->setHelp(Config::TYPE_JSON === $kind
                ? t('help.value_json', [], 'config')
                : t('help.value', [], 'config'));
        }

        return $field;
    }

    // Index lists every config in one column: kind/sensitivity vary per row and can't be resolved from the (null) top-level entity, so it's decided from the row's own $config argument
    private function maskedValueField(): FieldInterface
    {
        return TextareaField::new('value')
            ->setLabel(t('label.value', [], 'config'))
            // Only an actual secret is masked: an empty sensitive config must read as empty, otherwise the list shows "••••••••" for a setting nobody has filled in yet - and contradicts the dashboard alert telling you to fill it
            ->formatValue(fn (?string $value, Config $config): string =>
                $config->getIsSensitive() && null !== $value && '' !== $value ? '••••••••' : ($value ?? '')
            );
    }

    // Sensitive fields are pre-filled with the decrypted raw string value in edit (must stay the raw string, not configService->get()'s kind-cast value, otherwise a sensitive bool/int/date config like site-maintenance renders as "1"/"" instead of "true"/"false") (no need to mask with a password widget, edit is the only page besides the masked index that ever shows this field). A sensitive json config (e.g. an authorized-tokens map) still needs a multi-line widget - the single-line TextField is fine for a sensitive string/key but unusable for json
    private function sensitiveValueField(string $kind, ?string $rawValue): FieldInterface
    {
        $isJson = Config::TYPE_JSON === $kind;

        $decryptedValue = null;
        if (null !== $rawValue && '' !== $rawValue) {
            $decryptedValue = $this->vaultEncryptor->decrypt($rawValue);
        }

        return ($isJson ? TextareaField::new('value') : TextField::new('value'))
            ->setLabel(t('label.value_sensitive', [], 'config'))
            ->setFormTypeOptions(['data' => $isJson ? $this->prettyJson($decryptedValue) : $decryptedValue])
            ->setRequired(false);
    }

    // Non-sensitive fields use a widget matching the config kind
    private function kindValueField(string $kind, ?string $rawValue): FieldInterface
    {
        return match ($kind) {
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
            // Falls back to a plain TextField with no font declared, an empty <select> being worse than free text
            Config::TYPE_FONT => $this->buildFontField($rawValue),
            // Text kind is plain string (URLs, ids, emails...), a rich editor would wrap it in a <div>
            default => TextField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setRequired(false),
        };
    }

    public function configureActions(Actions $actions): Actions
    {
        $exportGroup = ActionGroup::new('export', t('label.export', [], 'config'), 'fa fa-download')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('exportSql', 'SQL')->linkToCrudAction('exportSql'))
            ->addAction(Action::new('exportCsv', 'CSV')->linkToCrudAction('exportCsv'))
            ->addAction(Action::new('exportJson', 'JSON')->linkToCrudAction('exportJson'))
            ->addAction(Action::new('exportContent', t('action.export_for_sync', [], 'config'))->linkToCrudAction('exportContent'))
            ->addAction(Action::new('exportSqlWithSensitive', t('action.export_sql_with_sensitive', [], 'config'))->linkToCrudAction('exportSqlWithSensitive'))
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

        // Reachable once a group is selected, or once a cross-group search query has bypassed the "pick a group" screen (see index()) - unsets both "group" and "query" to go back to it cleanly
        $backToGroupsAction = Action::new('groups', t('label.config', [], 'config'), 'fas fa-layer-group')
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('group')
                ->unset(EA::QUERY)
                ->generateUrl())
            ->createAsGlobalAction();

        // Lets the admin back out of an edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->add(Crud::PAGE_INDEX, $exportGroup)
            ->add(Crud::PAGE_INDEX, $toggleAction)
            ->add(Crud::PAGE_INDEX, $backToGroupsAction)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->setPermission('exportCsv', $this->configService->get('site-role-admin'))
            ->setPermission('toggleSensitive', $this->configService->get('site-role-admin'))
            ->setPermission('exportSql', $this->configService->get('site-role-admin'))
            ->setPermission('exportSqlWithSensitive', 'ROLE_SUPER_ADMIN')
            ->setPermission('exportJson', $this->configService->get('site-role-admin'))
            // Configs are fixed by the bundles' import json: no manual creation, no deletion; detail adds no information beyond what edit already shows (sensitive values are revealed in clear there too)
            ->disable(Action::NEW, Action::DELETE, Action::DETAIL)
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LConfig/management/config_crud_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LConfig/management/config_crud_edit.html.twig')
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

        // Resolved in memory against translated labels: EasyAdmin's search only matches the raw translation keys
        // The query is blanked before parent() so its own LIKE doesn't AND against zero rows
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
            // Never an empty IN(), which DBAL rejects; an impossible slug cleanly yields zero rows
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

    // True when nothing scopes the grid yet: no group picked and no cross-group search query typed - see index() and configureActions()'s backToGroupsAction, which clears both to return here
    private function showGroupsScreen(): bool
    {
        $query = $this->requestStack->getCurrentRequest()?->query->get(EA::QUERY);

        return null === $this->currentGroup() && (!\is_string($query) || '' === $query);
    }

    // Slugs whose slug or translated label contains $query; raw SQL, as the rest of this class already does
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
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->configSqlExporter->export();
    }

    // Same export, secrets included: only makes sense when the target environment shares this one's C975L_VAULT_KEY, hence ROLE_SUPER_ADMIN and a separate button rather than an option on the previous one
    #[AdminRoute]
    public function exportSqlWithSensitive(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->configSqlExporter->export(true);
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
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->tableExporter->export(ExportFormat::Json, 'site_config', $this->fetchExportRows());
    }

    // Same rows as exportJson, wrapped in the {kind, items} envelope ContentImportController/ConfigImportProvider expect - meant to be re-uploaded on another environment to sync configs the way exportSql already lets you do by hand, but without leaving SSH/phpMyAdmin
    #[AdminRoute]
    public function exportContent(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->contentExporter->export(ConfigImportProvider::KIND, $this->configExportProvider->exportAll()['items']);
    }

    // Sensitive values are kept as stored (encrypted), never decrypted for export - delegates to ConfigExportProvider, the single source of truth also used by the "export sync all" dashboard shortcut (see SyncAllExporter)
    private function fetchExportRows(): array
    {
        return $this->configExportProvider->fetchRows();
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

    // The 3 CSS generics plus whatever FontRegistry knows; a raw value present in neither is kept selectable
    private function buildFontField(?string $rawValue): FieldInterface
    {
        $fonts = array_merge($this->fontRegistry->getFonts(), Config::GENERIC_FONT_FAMILIES);
        $choices = array_combine($fonts, $fonts);
        if (null !== $rawValue && '' !== $rawValue && !isset($choices[$rawValue])) {
            $choices = [$rawValue => $rawValue] + $choices;
        }

        return ChoiceField::new('value')
            ->setLabel(t('label.value', [], 'config'))
            ->setFormType(FontChoiceType::class)
            ->setFormTypeOptions(['choices' => $choices])
            ->setRequired(false);
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
