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
use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Management\ThemePresetRegistry;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// Same Config entity/table as ConfigCrudController, restricted to the "theme" group (CSS variables
// editable by the admin - colors, fonts, light/dark mode), kept in its own dashboard view so it
// doesn't get mixed up with the general site_config list
class ThemeCrudController extends AbstractCrudController
{
    // Choices for the theme-mode config's value, the only theme slug that isn't a raw CSS value
    private const THEME_MODE_CHOICES = ['auto', 'light', 'dark'];

    private function themeModeChoices(): array
    {
        $choices = [];
        foreach (self::THEME_MODE_CHOICES as $mode) {
            $choices[$mode] = t('label.theme_mode_' . $mode, [], 'config');
        }

        return $choices;
    }

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ThemePresetRegistry $themePresetRegistry,
        private readonly ConfigRepository $configRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
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
        $slug = $entity instanceof Config ? $entity->getSlug() : null;

        // Description holds a 'site_config' translation key (description.xxx); trans() safely falls
        // back to the raw text unchanged for slugs that haven't migrated to it
        $descriptionField = TextField::new('description')
            ->setLabel(t('label.description', [], 'config'))
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

        // Label uses a 'site_config' translation key derived from the slug (label.xxx)
        $labelField = TextField::new('label')
            ->setLabel(t('label.label', [], 'config'))
            ->setFormTypeOption('disabled', true)
            ->formatValue(fn (string $value, Config $config): string =>
                $this->translator->trans($config->getLabelTranslationKey(), [], 'site_config')
            );
        if (Crud::PAGE_EDIT === $pageName && $entity instanceof Config) {
            $labelField->setFormTypeOptions([
                'data' => $this->translator->trans($entity->getLabelTranslationKey(), [], 'site_config'),
            ]);
        }

        // Every theme slug is a plain CSS value (color, font stack...) edited as free text, except
        // theme-mode, which picks the site-wide light/dark/auto mode and is edited as a fixed choice
        $valueField = match (true) {
            'theme-mode' === $slug => ChoiceField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setTranslatableChoices($this->themeModeChoices()),
            null !== $slug && str_starts_with($slug, 'theme-color-') => TextField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setRequired(false)
                ->setHelp(t('help.value_theme_color', [], 'config')),
            default => TextField::new('value')
                ->setLabel(t('label.value', [], 'config'))
                ->setRequired(false),
        };

        return [
            IdField::new('id')
                ->setLabel(false)
                ->onlyOnIndex(),
            $labelField,
            TextField::new('slug')
                ->setLabel(t('label.slug', [], 'config'))
                ->setFormTypeOption('disabled', true),
            $valueField,
            $descriptionField,
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $presets = $this->themePresetRegistry->all();

        // No preset provider registered (e.g. ConfigBundle used without SiteBundle): skip the group
        // entirely, EasyAdmin rejects an ActionGroup with zero actions
        if ([] !== $presets) {
            $presetsGroup = ActionGroup::new('presets', t('label.presets', [], 'config'), 'fa fa-swatchbook')
                ->createAsGlobalActionGroup();

            // Applying a preset only ever writes vetted values, unlike manual field editing (see the
            // EDIT permission below), so it's allowed at the lower site-role-editor level
            $permission = $this->configService->get('site-role-editor');
            foreach ($presets as $id => $preset) {
                // 'label' belongs to whichever bundle contributed the preset (see
                // ThemePresetProviderInterface), not necessarily this bundle's own 'config' domain -
                // 'config' is only the fallback for a provider that hasn't declared one
                $domain = $preset['domain'] ?? 'config';
                $label = $this->translator->trans($preset['label'], [], $domain);

                $actionName = 'applyPreset_' . $id;
                $presetsGroup->addAction(
                    Action::new($actionName, $label)
                        ->linkToUrl(fn () => $this->adminUrlGenerator
                            ->setController(self::class)
                            ->setAction('applyPreset')
                            ->set('preset', $id)
                            ->generateUrl())
                        ->askConfirmation(t('confirm.apply_preset', [], 'config'))
                );
                $actions->setPermission($actionName, $permission);

                // Lets an editor judge the preset's look (colors/fonts/shape, demo layout) before
                // committing to it - a ready-made link built by the owning bundle (see
                // SiteThemePresetProvider), since only it knows which page/route can render it
                $previewUrl = $preset['previewUrl'] ?? null;
                if (null !== $previewUrl) {
                    $previewActionName = 'previewPreset_' . $id;
                    $presetsGroup->addAction(
                        Action::new(
                            $previewActionName,
                            $this->translator->trans('action.preview_preset', ['%label%' => $label], 'config')
                        )
                            ->linkToUrl($previewUrl)
                            ->setHtmlAttributes(['target' => '_blank'])
                    );
                    $actions->setPermission($previewActionName, $permission);
                }
            }

            // Temporarily hidden pending rework - the group/permissions are still built above and
            // applyPreset() stays reachable, only the index button is not displayed
            // $actions->add(Crud::PAGE_INDEX, $presetsGroup);
        }

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.detail', [], 'EasyAdminBundle'),
            ))
            // Manual field-by-field editing (colors, fonts, mode) is reserved to ROLE_SUPER_ADMIN,
            // even for non-restricted values - editors/admins may only apply a vetted preset above
            ->setPermission(Action::EDIT, 'ROLE_SUPER_ADMIN')
            // Theme configs are fixed by SiteBundle's configs-css.json: no manual creation, no deletion
            ->disable(Action::NEW, Action::DELETE)
        ;
    }

    // A "restricted" theme config (font family, stylesheet) must stay invisible to any admin below
    // ROLE_SUPER_ADMIN, same protection as ConfigCrudController::denyAccessToRestrictedConfig() for
    // the same entity/table
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

    // Overwrites theme-stylesheet (the site's visual "shape" - radius/shadows/nav/footer, see
    // StylesheetProvider) with the preset's - the existing ThemeVariablesCssListener (already
    // listening to postUpdate) regenerates site-theme.css. Colors and fonts are never touched here:
    // they stay entirely admin-owned (see configureFields()), so a preset never overwrites values
    // the admin has deliberately chosen - it only ever switches the shape.
    #[AdminRoute('/apply-preset')]
    public function applyPreset(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $preset = $this->themePresetRegistry->get((string) $request->query->get('preset'));
        $config = null !== $preset ? $this->configRepository->findOneBySlug('theme-stylesheet') : null;

        // A preset with no 'stylesheet' (nullable per ThemePresetProviderInterface) leaves the
        // current stylesheet untouched, rather than blanking it
        if (null !== $config && null !== $preset['stylesheet']) {
            $config->setValue($preset['stylesheet']);
            $config->setModification(new \DateTime());
            $this->setUser($config);
            $entityManager->flush();
            $this->configService->invalidateCache();
        }

        return new RedirectResponse($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular(t('label.theme', [], 'config'))
            ->setEntityLabelInPlural(t('label.theme', [], 'config'))
            ->setEntityPermission($this->configService->get('site-role-editor'))
            ->setDefaultSort(['label' => 'ASC'])
        ;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $qb->andWhere('entity.group = :group')
            ->setParameter('group', Config::GROUP_THEME);

        // Restricted theme configs (font families, stylesheet) stay out of the list entirely below
        // ROLE_SUPER_ADMIN, see denyAccessToRestrictedConfig() - same protection as ConfigCrudController
        // (isRestricted is nullable: legacy rows not yet synced must NOT be treated as restricted)
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $qb->andWhere('entity.isRestricted IS NULL OR entity.isRestricted = :isRestricted')
                ->setParameter('isRestricted', false);
        }

        return $qb;
    }

    // Updated theme value - invalidates the config cache (the CSS file itself is regenerated by
    // SiteBundle's ThemeVariablesCssListener, which reacts to the same Doctrine postUpdate event)
    public function updateEntity(EntityManagerInterface $entityManager, mixed $config): void
    {
        if ($config instanceof Config) {
            $config->setModification(new \DateTime());
            $this->setUser($config);
        }

        parent::updateEntity($entityManager, $config);

        $this->configService->invalidateCache();
    }

    // Records which admin made the change, same as ConfigCrudController
    private function setUser(Config $config): void
    {
        $user = $this->security->getUser();
        if (null !== $user) {
            $config->setUser($user);
        }
    }
}
