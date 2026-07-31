# UPGRADE

## Unreleased

**`ManagementAuthenticationListener` is removed — add an `access_control` rule for `/management`.** The listener threw an `InsufficientAuthenticationException` on any `/management` request without an authenticated user, so that visitors landed on the login form rather than on a 403. It read the token from a `kernel.request` listener at priority 7, on the assumption that the firewall (priority 8) had already resolved it — which only holds when the firewall is *not* lazy. On the `lazy: true` firewall the Symfony skeleton ships, the token is resolved only when something first reads it, so `Security::getUser()` returned `null` even for a fully authenticated admin, and every back-office request was redirected to the login page.

Declare the rule instead:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/management, roles: IS_AUTHENTICATED_FULLY }
```

Behaviour is unchanged: an anonymous visitor is still redirected to your login form, and an authenticated user without the `site-role-admin` role still gets a 403 from the controllers' `denyAccessUnlessGranted()`. Nothing else to do — no code of yours referenced the listener, which was registered through its own `#[AsEventListener]` attribute.

Note that the redirect survives even without the rule: an anonymous visitor reaching the dashboard gets an `AccessDeniedException` from the controller, and Symfony's security `ExceptionListener` turns it into the same redirect to the login form, since the token isn't fully fledged. The rule is what stops the request before the controller runs, and what keeps a lazy firewall from deferring the token past the point where the back-office needs it.

**The bundle now requires PHP 8.4 and Symfony 8.** It used to declare `"php": ">=8.0"` and `"symfony/*": "*"`, which described nothing: the code has needed PHP 8.1 since its first promoted `readonly` property, and an unbound `*` let Composer resolve Symfony against whatever PHP the application ran on - so an application on PHP 8.2 silently got Symfony 7 with a bundle only ever tested against Symfony 8. The requirements now say what is actually built and tested: `"php": ">=8.4"` and `"symfony/*": "^8.0"`.

If your application is still on Symfony 7, stay on the previous release until you migrate - `composer update c975l/config-bundle` will simply refuse to move rather than break anything. Nothing in the bundle's own code changes with it: no new syntax, no removed method.

**Your `App\Entity\User` must now implement `c975L\ConfigBundle\Contract\UserInterface`.** `Config::$user` was typed `App\Entity\User`, a class that lives in app-space and that a standalone bundle checkout cannot reference; it is now typed against this new interface, which `c975LConfigBundle::prependExtension()` maps back onto `App\Entity\User` through Doctrine's `resolve_target_entities` - so there is nothing to declare in your app's configuration, but the PHP property type rejects a user entity that doesn't implement the interface (`TypeError` on hydration, and on saving a config from the back-office):

```php
// src/Entity/User.php
use c975L\ConfigBundle\Contract\UserInterface;

class User implements UserInterface
{
    // ...
}
```

The interface extends `Symfony\Component\Security\Core\User\UserInterface` (which your `User` already implements) and adds `getId(): int|string|null` - satisfied by the getter Doctrine entities carry anyway, whether the identifier is an auto-increment integer or a uuid. Nothing else changes: no migration, the column and the join stay identical.

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
