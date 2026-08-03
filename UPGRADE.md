# UPGRADE

## v6.0.0

**Redirects, the site-wide health checks and the content-quality machinery moved here from SiteBundle.** None of them needed a Page: a url that changed needs a redirect whether it was a page's or a product's, a TLS certificate belongs to the host, and a shop's own urls deserve the same content checks a page gets. Namespace for namespace:

| Was, in SiteBundle | Now |
|---|---|
| `Entity\Redirect`, `Repository\RedirectRepository` | `c975L\ConfigBundle\*` (table `site_redirect` unchanged) |
| `EventSubscriber\RedirectSubscriber` | `c975L\ConfigBundle\EventSubscriber\RedirectSubscriber` |
| `Controller\Management\RedirectCrudController` | `c975L\ConfigBundle\Controller\Management\RedirectCrudController` |
| `Management\Redirect{Export,Import}Provider`, `RedirectChainHealthCheckProvider` | `c975L\ConfigBundle\Management\*` |
| `Management\{SslCertificate,SecurityHeaders,SeoFiles}HealthCheckProvider` | `c975L\ConfigBundle\Management\*` |
| `Service\{SslCertificate,SecurityHeaders,SeoFiles}Client` | `c975L\ConfigBundle\Service\*` |
| `Management\ContentQualityAnalyzer`, `Service\ContentQualityClient` | `c975L\ConfigBundle\*` |
| `Management\DeclaredUrlsHealthCheckProvider`, `DeclaredUrlsHealthCheckPass` | `c975L\ConfigBundle\*` |
| `Service\PageExistenceChecker` | `c975L\ConfigBundle\Service\UrlStatusChecker` |
| `Twig\CopyrightExtension` (`site_copyright()`) | `c975L\ConfigBundle\Twig\CopyrightExtension` |
| `Service\Security\SessionNonceGenerator` | `c975L\ConfigBundle\Security\SessionNonceGenerator` |

**Nothing to run**: same table, same route names, same config slugs (`site-author` and `site-first-online-date` are declared here now, matched on their existing `site_config` row), same `site_copyright()` Twig function.

Three new contracts come with it:

- **`Service\SiteUrlResolver`** — `siteRoot()` returns the one spelling of the site root (`https://example.com/`) every site-wide check groups its dashboard row under. SiteBundle's `PagePublicUrlResolver::resolveSiteRoot()` is gone; its home Page resolves to this exact string, so a site with pages and one without land on the same row.
- **`Management\ContentOffenceLocatorInterface`** (+ `ContentOffenceLocatorRegistry`) — how a bundle turns an offence the analyzer found (an image with no alt text, a broken link) back into a link to the screen that fixes it. SiteBundle registers `PageContentOffenceLocator` for its blocks; implement it and your service is auto-tagged. Without one the offence is still reported, just unlinked.
- **`Management\SelfCheckedSitemapProviderInterface`** — a `SitemapProviderInterface` implementing it gets no generic `urls-<name>` check built on top of it, having one of its own. SiteBundle's `SitePageSitemapProvider` uses it; `DeclaredUrlsHealthCheckPass` no longer names any class from another bundle.

**`security-headers` reads the site root instead of the home Page**, so it runs on a site with no pages at all. Its row label is `null` rather than the page title; same url, same dashboard row.

**`nelmio/security-bundle` is a `suggest` of this bundle now.** `SessionNonceGenerator` keeps a CSP nonce stable across a Turbo visit; it is registered only when the interface exists (`config/services_nelmio.yaml`), so an app without that bundle is unaffected.

**`HealthCheckErrorRow` replaces SiteBundle's `HealthCheckErrorRowTrait`.** The "the check itself blew up" row (network/API failure rather than a check result) is what every health check calling something over the network has to build, so it belongs next to `HealthCheckResult` and `HealthCheckProviderInterface`. Two changes on the way: it is a static class rather than a trait (a trait shared across bundles is only ever analysed against the users of its own package), and the translation domain — hardcoded to `site` — is a parameter, the summary being the calling bundle's own wording:

```diff
-$this->errorRow($url, $label, 'label.my_check_failed', $e->getMessage());
+HealthCheckErrorRow::build($this->translator, 'my-domain', $url, $label, 'label.my_check_failed', $e->getMessage());
```

**`Twig\CanonicalUrlExtension` moved here from SiteBundle.** `canonical_url()` builds the canonical url of the page being rendered from `site-url` and the current path, stripping the query string and normalizing the trailing slash — every bundle serving urls of its own needs one, not just the one serving Pages. Same function name, same behaviour.

**`url-terms-of-use` is declared here now.** SiteBundle, ShopBundle and PaymentBundle each shipped an identical declaration of it, and `c975L\PaymentBundle\Form\PaymentFormFactory` — which requires neither Site nor Shop — is what reads it. One declaration, at the ancestor the three have in common. Same slug, same `legal` group, **nothing to run**.

**The nine legal identity keys are declared here now.** `site-name`, `site-director` and `site-contact-email` already were; `site-owner`, `site-producer`, `site-hosting-provider`, `site-dpo`, `site-director-location` and `site-contact-phone` join them, from SiteBundle. UiBundle's legal models print all nine (see its own UPGRADE), and a site running a shop without page management had no way to fill six of them. Same slugs, same `legal` group, same severities, **nothing to run** — only the bundle declaring them changes.

**The email configs every bundle sends through moved here.** `c975L\UiBundle\Service\EmailService` resolves its From/To/Reply-To from `email-from`/`email-to`/`email-reply-to` and their `-name` counterparts — six keys, of which only `email-from` was declared here (and declared a second, identical time by SiteBundle). The other five lived in SiteBundle alone, so an app running Config + Ui + a satellite bundle threw `Missing email parameter(s)` on its first send, this bundle's own account-confirmation email included. All six are declared here now, and SiteBundle's duplicate `email-from` is gone. **Nothing to run**: the slugs, groups and severities are unchanged, so an existing site's rows are matched as they are.

`site-name`, `site-contact-email`, `site-director`, `site-made-by-logo` and `site-made-by-url` moved for the same reason — this bundle's `DashboardController`, `MenuProvider`, `ConfigEssentialActionProvider` and `DeploymentHealthCheckProvider` all read them, and a back-office with no title is not a site-content problem. The five `email-text-*` keys stay in SiteBundle: they are the copy of its own branded email layout.

**Sending an email from a satellite bundle no longer needs SiteBundle at all.** The whole chain is Config + Ui: seed the template with `FormSeeder::ensureEmailTemplate()`, compose it with `EmailTemplateRenderer::renderNamed()`, hand the result to `EmailService::send()` as `html:`. The wrapper comes from whichever `EmailLayoutProviderInterface` is registered — SiteBundle's branded layout when installed, UiBundle's plain shell otherwise — and the addresses from the six configs above. `EmailVerifier` is the worked example.

**The account layer moved here from SiteBundle.** Every satellite bundle (Shop, Book, Gallery, Crowdfunding, Payment, Social) requires this bundle and UiBundle, none requires SiteBundle — yet all of them relate their entities to `Contract\UserInterface`, whose only implementation, back-office and registration flow lived in SiteBundle. An app running Config + Ui + a satellite bundle therefore had accounts it could neither create nor manage. What moved, namespace for namespace:

| Was | Is now |
|---|---|
| `c975L\SiteBundle\Controller\Management\UserCrudController` | `c975L\ConfigBundle\Controller\Management\UserCrudController` |
| `c975L\SiteBundle\Security\Voter\UserManagementVoter` | `c975L\ConfigBundle\Security\Voter\UserManagementVoter` |
| `c975L\SiteBundle\Service\UserRegistrar` | `c975L\ConfigBundle\Service\UserRegistrar` |
| `c975L\SiteBundle\Service\EmailVerifier` | `c975L\ConfigBundle\Service\EmailVerifier` |
| `c975L\SiteBundle\Service\PasswordResetter` | `c975L\ConfigBundle\Service\PasswordResetter` |

The `user-roles-available` config, the `label.users`/`label.roles`/`label.info_user*` translations and the "Users" menu entry moved with them; the entry is now contributed by this bundle's own `MenuProvider`. **Nothing to run**: `site_user` is the app's own table, untouched, and both the config row and the translation keys keep their exact names.

**Re-scaffold the account files.** `App\Entity\User`, `App\Entity\ResetPasswordRequest`, their repositories, `App\Security\UserChecker`, `App\Form\ChangePasswordFormType`, the three controllers (`Security`, `Registration`, `ResetPassword`), the two `FormAction` services, `templates/security/login.html.twig`, `templates/reset_password/reset.html.twig` and the `validators` catalog are shipped by this bundle's scaffold now instead of SiteBundle's. They keep their exact paths in your app, so this is a re-scaffold, not a move:

```bash
php bin/console c975l:scaffold:install --dry-run
php bin/console c975l:scaffold:install
```

Your own copies land in `existingFiles/*.old`. Read them back if you had edited any — three of them changed behaviour on the way:

- **`RegistrationController` and `ResetPasswordController` redirect to `app_login`.** They used to resolve the SiteBundle `Page` carrying the matching `form` Block and fall back on `page_home`, which a Config-only app has neither of. Every outcome now lands on the login form, the one route this scaffold owns itself — the visitor isn't authenticated yet at any of those points, and logging in is where the flow was heading.
- **`login.html.twig` calls UiBundle's `form_url()`** instead of SiteBundle's `site_page_for_form_block()`. Same result on a site running SiteBundle (the real Page with its admin-editable per-locale slug, through the new `FormPageUrlProviderInterface`), the bare `ui_form_submit` route elsewhere. Both it and `reset.html.twig` extend `templates/layout.html.twig`, the name every c975L app already uses for its own shell — the error pages extend it too.
- **`templates/layout.html.twig` is scaffolded here now**, SiteBundle's scaffold no longer shipping it (nor the `base.html.twig` that only existed to alias it). One file for both worlds, since Twig takes the first template of the list that exists:

  ```twig
  {% extends ['@c975LSite/layout.html.twig', '@c975LUi/layout.html.twig'] %}
  ```

  A site running SiteBundle keeps its full layout — header, footer, navigation, SEO, theme — exactly as before; an app without it falls back on UiBundle's new minimal shell (stylesheets, importmap, flashes, `content` block). Adding or removing SiteBundle changes nothing in your app, and the file stays yours to replace outright with your own markup. `c975l:scaffold:install` will back your current one up to `existingFiles/` and hand you this one: unless you had put real markup in it, take the new version. **`templates/base.html.twig` becomes an orphan** — delete it once nothing extends it.
- **`ResetPasswordRequestFormAction` renders the `password_reset` EmailTemplate directly**, rather than the `@c975LSite/emails/reset_password_email.html.twig` file that no longer exists.

**The registration and reset-password emails are composed from their EmailTemplate, not from a Twig file.** `EmailVerifier::sendEmailConfirmation()` lost its `$template` argument and `UserRegistrar::register()` with it; both now render the admin-editable `account_validation` template through `EmailTemplateRenderer::renderNamed()`, wrapped by whichever bundle registers an `EmailLayoutProviderInterface` — SiteBundle's branded layout when it is installed, UiBundle's plain fallback otherwise. **Update your calls** if you drive either service yourself:

```diff
-$userRegistrar->register($user, $plainPassword, 'app_verify_email', $subject, '@c975LSite/emails/confirmation_email.html.twig', $email);
+$userRegistrar->register($user, $plainPassword, 'app_verify_email', $subject, $email);
```

Both return `false` without sending when the named template has been renamed or deleted from the back-office, an empty email being worse than none.

**`register` and `reset_password_request` are seeded by `UserFormSeeder` now**, not by SiteBundle's `DefaultPagesImporter` — which keeps seeding the Pages that carry them, and delegates. Idempotent as before, so an existing site's Forms and EmailTemplates are left exactly as they are.

**Added `c975l:config:user-create`**, an interactive equivalent of the account step of `c975l:site:create` for an app that has no site foundation to run that wizard on. It creates the account through the new `AdminUserCreator` (which `c975l:site:create` also calls now, so the two can't drift) and seeds the account Forms around it.

**The scaffold installer moved here too.** `c975L\SiteBundle\Service\ScaffoldInstaller` and `c975l:scaffold:install` are `c975L\ConfigBundle\Service\ScaffoldInstaller` and the same command — the tool installing every bundle's `scaffold/` has no reason to live in one of them. Same command name, same behaviour, nothing to run.

**The failed-Messenger screen, the table export and their two shortcuts moved here.** `MessengerFailedMessageService`, `MessengerAlertProvider`, `MessengerFailedController`, `SingleEnvelopeReceiver`, `MessengerCleanupCommand` and `ExportTablesCommand` are `c975L\ConfigBundle\*` now — cross-cutting infrastructure any bundle queueing a message needs, not site content. Three renames follow:

| Was | Is now |
|---|---|
| `c975l:site:messenger-cleanup` | `c975l:config:messenger-cleanup` |
| `c975l:site:export-tables` | `c975l:config:export-tables` |
| `management_site_messenger_failed*` routes | `management_config_messenger_failed*` |

The cleanup command is scheduled through `MaintenanceTaskProviderInterface`, so **nothing to change in a crontab** — the schedule resolves the new name by itself. The `site-messenger-cleanup-mailto` and `site-messenger-cleanup-retention-days` configs keep their slugs. The "Export tables" and "Enable/disable registration" dashboard shortcuts are contributed by `ConfigShortcutProvider` now.

**No Messenger configuration is required for any of it**: listing, purging and deleting go through Doctrine. `MessengerFailedMessageService`'s `$failureReceiver` is optional (`@?messenger.transport.failed`), so an app without `framework.messenger.failure_transport` still compiles its container — it just can't replay a failed message, `retry()` reporting it as not found. Only relevant if you instantiate the service yourself: that argument is nullable now.

**The `deployment` health check moved here**, along with `DeploymentClient` — it only ever reads `site-url` and probes the host over HTTP, which is this bundle's territory. Its `ssl-certificate` and `security-headers` neighbours stay in SiteBundle: both resolve a real `Page` to probe.

**The scaffolded `App\Scheduler\MaintenanceSchedule` is shipped by this bundle now**, SiteBundle's scaffold no longer carrying it — every maintenance task it runs is declared here. Same file, same path, so `c975l:scaffold:install` reports it as already up to date.

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
