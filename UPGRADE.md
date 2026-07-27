# UPGRADE

## Unreleased

**`ThemePresetProviderInterface` and `ThemePresetRegistry` are removed**, along with the `c975l.theme_preset_provider` tag. The admin action applying a preset had already been removed (see the note below), leaving the interface with no consumer of its own; a site now carries one theme it owns outright rather than a catalog to switch between. Delete any provider of yours implementing it - nothing needs to replace it: a site's design tokens live in its own `assets/styles/themes/theme.css` (see `c975l/site-bundle`'s readme).

**New required Composer dependency: `symfony/messenger`**, and the **"Run health check now" button no longer runs the check in your request** - it dispatches one `RunCommandMessage` per registered kind (`c975l:health-check:run --kind=…`, the command the scheduler already runs) and returns immediately. A single provider can hold thousands of urls (`c975l/site-bundle`'s `DeclaredUrlsHealthCheckProvider` declares one per photo of a gallery), and a run that times out mid-way persists nothing at all. To actually get the asynchronous behaviour, route the message and consume the transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Symfony\Component\Console\Messenger\RunCommandMessage: async
```

```bash
php bin/console messenger:consume async scheduler_site
```

Without the routing, Messenger handles the message synchronously and the button blocks the request exactly as before - nothing breaks, it just gains nothing. `HealthCheckController`'s constructor gained a `MessageBusInterface` argument (autowired, only relevant if you extend or instantiate it yourself), and the `flash.health_check_run_success` translation is replaced by `flash.health_check_queued` - override it again if you had overridden the old one.

The new `HealthCheckAlertProvider` raises a dashboard alert when the last run left errors (danger) or warnings only (warning), with the date of that run - which is what tells you a queued run is done. It's auto-registered like any `AlertProviderInterface`, nothing to wire; it stays silent while nothing has been checked or nothing is left to fix.

**New required Composer dependency: `symfony/ux-chartjs`** (pulled in for the Health check page's trend chart, see `HealthCheckTrendChartBuilder`). Unlike the other notes below, this one breaks the container at *compile* time, not just a missing feature - `composer update symfony/ux-chartjs` (or `composer update c975l/config-bundle`) in your app right after upgrading, so `Symfony\UX\Chartjs\Builder\ChartBuilderInterface` actually exists for autowiring. If Symfony Flex is active in your app it should also register `ChartjsBundle` in `config/bundles.php` and add its own `importmap.php`/`chart.js` entries automatically; if it doesn't (recipe declined, or an app not using Flex), add `Symfony\UX\Chartjs\ChartjsBundle::class => ['all' => true]` there by hand.

Added the `HealthCheckResult` entity (`site_health_check_result` table, see the new "Health check" dashboard page): run `php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate`.

Added a "Guided tour" button on `/management`, highlighting menu items that declare a `description` (see `MenuProviderInterface`) - it's ConfigBundle's first bundle-shipped admin JS, so it needs the same one-time `importmap.php` entry as `c975l/ui-bundle`'s own `admin.js` (see the README's [JS assets loaded on the dashboard](README.md#js-assets-loaded-on-the-dashboard) section):

```php
'@c975l/config-bundle/controllers-admin.js' => [
    'path' => './vendor/c975l/config-bundle/assets/controllers-admin.js',
    'entrypoint' => true,
],
```

Without it, `/management` still works exactly as before - the button just doesn't render (`OnboardingStepBuilder::getSteps()` still runs, but no bundle contributes a `description` yet unless you add one, see the README).

`ConfigCrudController`'s constructor gained two arguments, `ConfigRepository` and `EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator` (both autowired, nothing to configure) — only relevant if your app extends or manually instantiates this controller.

The Config list's EasyAdmin index is no longer a single flat table listing every group's entries together - it now shows a "pick a group" screen first (one row per distinct group, with its entry count), then the familiar grid filtered to that group (`?group=...`). Nothing to migrate: existing config rows work as-is, this only changes the admin UX. The EasyAdmin "group" filter is removed from the grid (redundant with the new screen); if you relied on it (e.g. a saved/bookmarked filtered URL using EasyAdmin's own `filters[group][...]` query format), switch to the plain `?group=<slug>` query param instead. If you link directly to the CRUD's index (bypassing the dashboard menu), append `?group=<slug>` to land straight on a given group's grid instead of the group picker.

**`ThemeCrudController` and its "Theme" dashboard menu entry are removed.** It existed to keep the `theme` group's CSS-variable entries out of the general Config list before that list could be filtered by group - now that Config's own "pick a group" screen does exactly that, the dedicated page is redundant. Theme entries (colors, fonts, light/dark mode) are edited from **Config → theme** like any other group. Concretely:

- `/management/theme` (and any bookmarked link to it) is gone - link to Config's `theme` group instead (`?group=theme` on the Config CRUD's index route).
- **Permission changed**: theme entries were viewable/preset-applicable at `site-role-editor` and hand-editable at `ROLE_SUPER_ADMIN`; they're now gated like every other Config entry, at `site-role-admin` for both viewing and editing. A site relying on an editor-level role to manage theme colors/fonts must grant it `site-role-admin` instead (or wait for the Presets UI's eventual rework, see below).
- The "Presets" admin action (apply a vetted preset in one click) and its `applyPreset` route are removed - it was already hidden pending a rework (`// $actions->add(Crud::PAGE_INDEX, $presetsGroup);` was commented out) and had no working entry point. Both `ThemePresetProviderInterface` and `ThemePresetRegistry` have since been removed too - see the note at the top of this file.
- `label.theme` (the removed page's title) is unused but still translated - harmless, not removed.
- If your app extended `ThemeCrudController` or linked to it directly (custom dashboard menu override, etc.), update accordingly - there is no replacement class, `ConfigCrudController` handles every group generically.

## > v5.4

- Added `isRestricted` column on `Config`: run `php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate`
- A config flagged `"restricted": true` in a bundle's `configs.json` is now hidden entirely (index/detail/edit/export) from any user without `ROLE_SUPER_ADMIN` — use it for secrets shared across the install (DB backup credentials, payment API keys...) that a regular site admin must never see, even encrypted

## v4.x > v5.x

Made use of database to store config parameters. Needs a databse migration.
