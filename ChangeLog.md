# ChangeLog

## v6.0.0

Accounts, scaffolding and shared plumbing move to the ecosystem root

- Failed Messenger dates are read as UTC, the digest no longer staying silent after an alert (03/08/2026)
- `ContentQualityAnalyzer` releases each batch's responses instead of holding them for the whole run (03/08/2026)
- `seo-files`, `deployment` and `redirect-chains` read the site root through `SiteUrlResolver` (03/08/2026)
- A `Redirect` row without a destination is skipped instead of erroring the whole path (03/08/2026)
- `RedirectImportProvider` no longer imports such a row (03/08/2026)
- `c975l:config:export-tables` judges mysqldump on its exit code rather than on stderr (03/08/2026)
- Its `--prefix` and `site-backup-database` must be plain identifiers (03/08/2026)
- `site_copyright()` ignores a `site-first-online-date` it cannot read (03/08/2026)
- `svg-fonts` added to the health check's site-wide kinds (02/08/2026)
- `Redirect` moved here from SiteBundle: entity, `RedirectSubscriber`, CRUD, export/import and the `redirect-chains` check (02/08/2026) [BC-Break]
- The `ssl-certificate`, `security-headers` and `seo-files` checks moved here too, none of them needing a Page (02/08/2026) [BC-Break]
- Added `SiteUrlResolver`, the one spelling of the site root every site-wide check groups on (02/08/2026)
- `security-headers` reads that root instead of resolving the home Page (02/08/2026)
- The content-quality machinery moved here: `ContentQualityAnalyzer`, `ContentQualityClient`, the `urls-<bundle>` check and its pass (02/08/2026) [BC-Break]
- Added `ContentOffenceLocatorInterface`/`ContentOffenceLocatorRegistry`, tracing an offence back to the screen holding it (02/08/2026)
- Added `SelfCheckedSitemapProviderInterface`, opting a sitemap out of the generic urls check (02/08/2026)
- SiteBundle's `PageExistenceChecker` landed here as `UrlStatusChecker` (02/08/2026) [BC-Break]
- `SessionNonceGenerator` moved here from SiteBundle, with its conditional Nelmio wiring (02/08/2026) [BC-Break]
- `site_copyright()` moved here too, with `site-author` and `site-first-online-date` (02/08/2026) [BC-Break]
- `MenuProvider` contributes the "Redirects" entry (02/08/2026)
- Added `ProcedureProvider` and its `procedures.json`, holding the redirect and account procedures (02/08/2026)
- The six legal identity configs moved here from SiteBundle: `site-owner`, `site-producer`, `site-hosting-provider`, `site-dpo`, `site-director-location`, `site-contact-phone` (02/08/2026)
- The account layer moved here from SiteBundle: `UserCrudController`, `UserManagementVoter`, `UserRegistrar`, `EmailVerifier`, `PasswordResetter` (02/08/2026) [BC-Break]
- The account half of SiteBundle's scaffold moved here too, `App\Entity\User` included (02/08/2026) [BC-Break]
- `MenuProvider` contributes the "Users" entry, and `configs.json` the `user-roles-available` key (02/08/2026)
- `EmailVerifier::sendEmailConfirmation()` and `UserRegistrar::register()` lost their `$template` argument (02/08/2026) [BC-Break]
- Both compose their email from the `account_validation` EmailTemplate (02/08/2026)
- Both return `false` without sending when that template has been renamed or deleted (02/08/2026)
- The scaffolded `RegistrationController`/`ResetPasswordController` redirect to `app_login` instead of a SiteBundle Page (02/08/2026) [BC-Break]
- The scaffolded `login.html.twig` calls UiBundle's `form_url()` (02/08/2026) [BC-Break]
- It and the scaffolded `reset.html.twig` extend `layout.html.twig` (02/08/2026) [BC-Break]
- The scaffolded `layout.html.twig` is shipped here now instead of by SiteBundle, and resolves to whichever layout is installed (02/08/2026) [BC-Break]
- Added `UserFormSeeder`, seeding the `register`/`reset_password_request` Forms and their two emails (02/08/2026)
- Added `AdminUserCreator`, shared by `c975l:site:create` and the new command below (02/08/2026)
- Added `c975l:config:user-create`, bootstrapping an admin on an app without a site foundation (02/08/2026)
- `ScaffoldInstaller` and `c975l:scaffold:install` moved here from SiteBundle (02/08/2026) [BC-Break]
- The failed-Messenger stack moved here: service, alert provider, controller, receiver, cleanup command (02/08/2026) [BC-Break]
- `c975l:site:messenger-cleanup` is now `c975l:config:messenger-cleanup`, and its routes `management_config_messenger_failed*` (02/08/2026) [BC-Break]
- `ExportTablesCommand` moved here, `c975l:site:export-tables` becoming `c975l:config:export-tables` (02/08/2026) [BC-Break]
- `ConfigShortcutProvider` gained the "Export tables" and "Enable/disable registration" tiles (02/08/2026)
- The `deployment` health check and `DeploymentClient` moved here (02/08/2026) [BC-Break]
- `ConfigMaintenanceTaskProvider` declares the messenger cleanup, `SiteMaintenanceTaskProvider` being removed (02/08/2026)
- The scaffolded `App\Scheduler\MaintenanceSchedule` is shipped by this bundle now (02/08/2026)
- Added `Management\ArchiveFileRegistrar`, replacing SiteBundle's `ArchiveFileTrait` (02/08/2026) [BC-Break]
- Added a `phpstan-baseline.neon` and a second PHPStan pass on the scaffold (02/08/2026)
- Added `symfonycasts/{reset-password,verify-email}-bundle` and `symfony/password-hasher` to the requirements (02/08/2026)
- Added `UserFormSeederTest`, `AdminUserCreatorTest` and the moved services' own tests (02/08/2026)
- The address configs `EmailService` resolves moved here: `email-to`, `email-to-name`, `email-reply-to`, `email-reply-to-name`, `email-from-name` (02/08/2026) [BC-Break]
- `email-from` is no longer declared twice, SiteBundle's identical copy being dropped (02/08/2026)
- `site-name`, `site-contact-email`, `site-director`, `site-made-by-logo`, `site-made-by-url` moved here (02/08/2026) [BC-Break]
- `url-terms-of-use` is declared here, its copies in Site/Shop/Payment being dropped (02/08/2026) [BC-Break]
- Added `ConfigsJsonTest`, guarding slug uniqueness and the translation of every label/description (02/08/2026)
- Added `Management\HealthCheckErrorRow`, replacing SiteBundle's trait (02/08/2026) [BC-Break]
- Its translation domain is a parameter, no longer hardcoded to `site` (02/08/2026) [BC-Break]
- `Twig\CanonicalUrlExtension` moved here from SiteBundle (02/08/2026) [BC-Break]
- The Messenger failure transport is optional, an app declaring none still compiling its container (02/08/2026)
- The failed-messages screen tolerates a `messenger_messages` table the transport hasn't created yet (02/08/2026)
- A role the edited user holds but `user-roles-available` no longer lists stays in `UserCrudController`'s choices (02/08/2026)
- `c975l:config:export-tables` writes no dump at all rather than a partial one (02/08/2026)
- Added the failed-Messenger stack's tests, plus `c975l:config:user-create`'s and `HealthCheckErrorRow`'s (02/08/2026)
- Every command's console output is in English, the eight that still spoke French included (02/08/2026)
- Documented the whole move in the readme and in UPGRADE.md (02/08/2026)

## v5.17.1

Status colors on the badges alone

- The health check table no longer tints a page's first row with that group's worst status (01/08/2026)
- A page's rows are now separated by a heavier top border instead (01/08/2026)
- Removed the `health-check-row-first--*` css classes and `verdictByUrl()` (01/08/2026)
- Updated the readme's health check section on reading the table (01/08/2026)

## v5.17.0

A queued health check now shows how far along it is

- Added `HealthCheckRunProgress`, following the run queued from the Health check page (01/08/2026)
- Added `HealthCheckResultRepository::findKindsCheckedSince()` (01/08/2026)
- Added the `management_health_check_progress` route, polled by the banner (01/08/2026)
- Added the `health-check-progress` Stimulus controller, reloading the page as results land (01/08/2026)
- Added the progress banner to the Health check page (01/08/2026)
- A run whose remaining kinds have recorded nothing after 15 minutes is now given up on (01/08/2026)
- Shortened the `flash.health_check_queued` message (01/08/2026)
- Added the `label.health_check_run_progress` and `label.health_check_run_timed_out` translations (01/08/2026)
- Updated the readme's health check section, and added the one on running it at deployment (01/08/2026)
- Added the `HealthCheckRunProgressTest` case (01/08/2026)
- `HealthCheckControllerTest` now covers the progress banner, and the order the run follows itself in (01/08/2026)
- An advice line's relative url now renders as an edit link back into the back office (01/08/2026)
- Added the `label.health_check_advice_fix_link` translation (01/08/2026)
- The management stylesheets now carry their own mtime as a cache-busting query param (01/08/2026)
- `DashboardControllerTest` now covers that cache-busting param (01/08/2026)

## v5.16.1

A shared config file no longer fails on the bundles a site doesn't install

- Added `--ignore-unknown` to `c975l:config:set`, skipping the slugs no installed bundle declares (01/08/2026)
- Added the `--ignore-unknown` case to `ConfigSetCommandTest` (01/08/2026)
- Updated the readme's `c975l:config:set` section (01/08/2026)

## v5.16.0

Bundles declare their scheduled tasks, sites report their status

- Added `DatabaseLoadHealthCheckProvider`, reading `SHOW GLOBAL STATUS` for the transaction rate (01/08/2026)
- `database-load` now reports the share of transactions holding no write (01/08/2026)
- Added `DatabaseLoadHealthCheckAdviceProvider`, telling background load from traffic (01/08/2026)
- `database-load` advice now reports slow queries, lock waits and refused connections (01/08/2026)
- The `database-load` row now shows in the Health check page's site-wide section (01/08/2026)
- `DevProfileCollector` now counts the transactions a page opens, and those that wrote nothing (01/08/2026)
- Transactions no longer count as duplicate queries (01/08/2026)
- Added the transaction thresholds to `DevProfileAnalyzer`, one per `flush()` (01/08/2026)
- `c975l:dev-profile:run` now prints the transaction count alongside the query count (01/08/2026)
- Added `symfony/clock` to the requirements (01/08/2026)
- Updated the readme's health check and dev profile sections (01/08/2026)
- Added the `DatabaseLoadHealthCheckProviderTest` and `DatabaseLoadHealthCheckAdviceProviderTest` cases (01/08/2026)
- `HealthCheckControllerTest` now covers the `database-load` site-wide row (01/08/2026)
- Added `MaintenanceTaskProviderInterface` and `MaintenanceTask`, letting a bundle declare its scheduled commands (01/08/2026)
- Added `MaintenanceScheduleBuilder`, collecting them into the app's schedule (01/08/2026)
- `MaintenanceScheduleBuilder::addTasks()` takes `$except`, dropping a command the site doesn't want run (01/08/2026)
- A command declared twice is now scheduled once (01/08/2026)
- Added `ConfigMaintenanceTaskProvider`, declaring this bundle's own sitemaps/backup/digest/health-check commands (01/08/2026)
- Added `ScheduleSpreader`, resolving Symfony's hashed cron expressions against the site's own identity (01/08/2026)
- `ScheduleSpreader` now falls back on the message's class when it isn't `Stringable` (01/08/2026)
- Added `symfony/scheduler` and `dragonmantank/cron-expression` to the requirements (01/08/2026)
- Added the `branch-alias` extra (01/08/2026)
- Updated the readme's backup scheduling section, and added the ones on spreading and on contributing maintenance tasks (01/08/2026)
- Added the `ScheduleSpreaderTest`, `MaintenanceScheduleBuilderTest` and `ConfigMaintenanceTaskProviderTest` cases (01/08/2026)
- Added `StatusReportBuilder`, gathering the site's versions, installed bundles and last health check run (01/08/2026)
- Added `StatusProviderInterface`, letting a bundle add its own section to that report (01/08/2026)
- A status provider that throws is now reported under its own key instead of costing the whole report (01/08/2026)
- Added `c975l:status:send`, posting the report to the configured url (01/08/2026)
- Added the `--dump` option to `c975l:status:send`, printing the report instead of sending it (01/08/2026)
- Added the `site-status-url` and `site-status-key` config entries, both empty by default (01/08/2026)
- A configured url without a key is now refused rather than sent unauthenticated (01/08/2026)
- The key travels in the `X-Status-Key` header, never in the query string (01/08/2026)
- Added `symfony/http-client` to the requirements (01/08/2026)
- Added the readme sections on the status report and on contributing status data (01/08/2026)
- Added the `StatusReportBuilderTest` and `StatusSendCommandTest` cases (01/08/2026)
- Excluded `MaintenanceTask` and `AsHealthCheck` from the container, autowiring them broke compilation (01/08/2026)

## v5.15.0

A health check provider declares its own cadence

- Added the `AsHealthCheck` attribute, declaring a provider's cadence, weekly by default (31/07/2026)
- Added `HealthCheckFrequencyAwareInterface`, declaring that cadence per instance (31/07/2026)
- `HealthCheckRunner::run()` now takes a `$frequency` filter (31/07/2026)
- Added the `--frequency` option to `c975l:health-check:run` (31/07/2026)
- An unknown `--frequency` now fails with `Command::INVALID` (31/07/2026)
- `c975l:health-check:run` now warns about a `--kind` no registered provider declares (31/07/2026)
- `c975l:health-check:run` now tells a filter matching nothing from a site having no provider (31/07/2026)
- Added the readme section on scheduling a provider (31/07/2026)
- Added the `AsHealthCheckTest` case (31/07/2026)
- Added the `HealthCheckRunnerTest` and `HealthCheckRunCommandTest` cases covering the cadence (31/07/2026)

## v5.14.1

Point the README's demo link at the ecosystem's dedicated site

- The README's demo link now points at `bundles.975l.com` (31/07/2026)

## v5.14

Drop the management authentication listener for an access_control rule

- Removed `ManagementAuthenticationListener`, see UPGRADE.md (31/07/2026) [BC-Break]
- Removed `ManagementAuthenticationListenerTest` (31/07/2026)
- Added the readme note on the `access_control` rule protecting `/management` (31/07/2026)
- Updated `MaintenanceListener`'s priority comment (31/07/2026)
- Changed `HealthCheckProviderInterface::runChecks()`' `details` to `?array<string, mixed>` (31/07/2026)
- Added a `tier` key to `MenuProviderInterface::getLinks()` (31/07/2026)
- The "Liens" section is no longer yielded when every link is advanced (31/07/2026)
- Added the readme note on the links' `tier` key (31/07/2026)
- Added the `MenuBuilderTest` cases covering advanced links (31/07/2026)

## v5.13.3

Keep the back office reachable when site-role-admin is missing

- `ConfigService::loadAll()` now falls back on `ROLE_ADMIN` when `site-role-admin` is missing from the database (31/07/2026)
- `ConfigImportProvider` now writes an imported sensitive value over an existing empty one (31/07/2026)
- Added the `ConfigServiceTest` cases covering the `site-role-admin` fallback (31/07/2026)
- Added the `ConfigImportProviderTest` cases covering the empty sensitive row (31/07/2026)
- `MaintenanceAlertProviderTest` no longer pins a fixed date ageing into a failure (31/07/2026)
- Added the readme note on the `site-role-admin` fallback (31/07/2026)
- Added the readme note on the sensitive rows' rule on a sync import (31/07/2026)

## v5.13.2

Add the Codacy grade badge to the README

- Added the Codacy grade badge to the README (30/07/2026)

## v5.13.1

- Added a `.stylelintrc.json`, read by Codacy in place of its default ruleset (30/07/2026)

## v5.13

Require PHP 8.4 and Symfony 8, bound every requirement

- `php` is now required in `>=8.4` instead of `>=8.0` (30/07/2026) [BC-Break]
- The `symfony/*` requirements are now constrained to `^8.0` instead of `*` (30/07/2026) [BC-Break]
- The third-party requirements left in `*` are now bounded on their installed version (30/07/2026)
- The `c975l/*` requirements are now bounded on their major (30/07/2026)
- Added `Contract\UserInterface`, the application's user entity as the c975L bundles relate to it (30/07/2026)
- `Config::$user` is now typed `Contract\UserInterface` instead of `App\Entity\User` (30/07/2026) [BC-Break]
- `c975LConfigBundle::prependExtension()` now maps `Contract\UserInterface` onto `App\Entity\User` through Doctrine's `resolve_target_entities` (30/07/2026)
- `ConfigCrudController::setUser()` now assigns the logged-in user only when it implements `Contract\UserInterface` (30/07/2026)
- Added `.codacy.yaml`, `phpcs.xml.dist` and `eslint.config.mjs` (30/07/2026)
- Applied PSR-12 to the codebase (30/07/2026)
- Added `.php-cs-fixer.dist.php`, applying the Symfony coding standards (30/07/2026)
- Added `phpstan.dist.neon`, running the static analysis at level 5 (30/07/2026)
- Added the `CI` GitHub Actions workflow, running PSR-12, the Symfony coding standards, the static analysis, the tests and the coverage upload (30/07/2026)
- Added the readme installation note on implementing `Contract\UserInterface` (30/07/2026)
- Added the `c975LConfigBundleTest` cases covering `prependExtension()` (30/07/2026)
- Added the `ConfigTest` and `ConfigCrudControllerTest` cases pinning the user relation to `Contract\UserInterface` (30/07/2026)
- `ContentImportController` no longer lets an unreadable zip entry past its path traversal check (30/07/2026)
- Added `ConfigRepository::findOneBySlug()`, replacing Doctrine's magic finder (30/07/2026)
- Fixed `ConfigService`'s `use` of `Config` written `C975L` instead of `c975L` (30/07/2026)
- The local Codacy CLI now runs `eslint@9.39.5` (30/07/2026)

## v5.12.1

Fixed the guided settings step highlighting no button

- The guided settings step now highlights `.action-saveAndReturn`, the class EasyAdmin gives that button (29/07/2026)
- Added the `ConfigGuidedProjectProviderTest` case pinning the highlighted EasyAdmin action classes (29/07/2026)

## v5.12.0

Moved the backup in from SiteBundle, added the guided projects

- Trimmed the sources', templates' and stylesheet's comments down to what the readme doesn't already say (29/07/2026)
- Replaced the provider interfaces' comments by PHPDoc blocks giving their array shapes (29/07/2026)
- Added the readme feature line on the guided projects (29/07/2026)
- Added `MaintenanceAlertProvider`, alerting while the site is closed and turning to `danger` past two days (29/07/2026)
- Added the `label.maintenance_alert*`/`label.maintenance_preview_link` translations and their `description.` pairs (29/07/2026)
- The maintenance page now sends `Retry-After` and `Cache-Control: no-store` (29/07/2026)
- Added the readme section on maintenance mode (29/07/2026)
- Added `MaintenanceAlertProviderTest` (29/07/2026)
- Added `ConfigGuidedProjectProvider`, contributing this bundle's own guided projects (29/07/2026)
- Added the "Régler la configuration du site", "Lancer un bilan de santé" and "Mettre le site en maintenance" projects (29/07/2026)
- Added the `label.guided_project_config_*`/`label.guided_step_config_*` translations and their `description.` pairs (29/07/2026)
- Added `ConfigGuidedProjectProviderTest` (29/07/2026)
- Added `GuidedProjectProviderInterface` to the bundle's tagged-interface test (29/07/2026)
- Added the readme note on `email-from` backing the backup emails (29/07/2026)
- Maintenance mode no longer lets every visitor through when `site-maintenance-hash` is empty (29/07/2026)
- The dashboard maintenance toggle now generates `site-maintenance-hash` when it is still empty (29/07/2026)
- `MaintenanceAlertProvider` now also gives the preview url built with `site-maintenance-hash` (29/07/2026)
- `c975l:config:backup` no longer moves its date markers past files no archive holds (29/07/2026)
- `c975l:config:backup` now stores the partial archives' members relative to the backed-up root (29/07/2026)
- `c975l:config:backup` and `c975l:config:backup:digest` no longer throw when `email-from` is empty (29/07/2026)
- `c975l:config:backup` now sends its failure report even without `site-url` (29/07/2026)
- Added the `email-from` config entry and its translations (29/07/2026)
- The health check page's "last checked" date now ignores the backup rows (29/07/2026)
- `ConfigRepositoryFindOneBySlugFixture` gained an optional per-slug map (29/07/2026)
- Added the `c975l:config:backup:digest` command, emailing a digest of the backups recorded over the last days (29/07/2026)
- `c975l:config:backup:digest` takes `--days` for the window and `--dry-run` to print the digest without sending it (29/07/2026)
- `c975l:config:backup:digest` reports the longest stretch without a backup (29/07/2026)
- `c975l:config:backup:digest` reports the archive size at both ends of the window (29/07/2026)
- `c975l:config:backup:digest` deduplicates the errors and warnings it lists, with their count (29/07/2026)
- `c975l:config:backup:digest` exits non-zero when no backup was recorded over the window, or when no recipient is configured (29/07/2026)
- `c975l:config:backup:digest` sends nothing when `site-backup-database` is empty (29/07/2026)
- Added `BackupDigestBuilder` and `HealthCheckResultRepository::findByKindSince()` (29/07/2026)
- `BackupAlertProvider::DEFAULT_MAX_AGE_HOURS` is now public (29/07/2026)
- Replaced the readme's weekly `c975l:config:backup --report` schedule by `c975l:config:backup:digest` (29/07/2026)
- Added the readme section on the weekly digest (29/07/2026)
- Added `BackupDigestBuilderTest` and `BackupDigestCommandTest` (29/07/2026)
- Removed `ManagementShortcutController` and its `/m` route (29/07/2026) [BC-Break]
- Added a "TL;DR" and a "Contents" section to the readme (29/07/2026)
- Moved the `site-url` config entry and its translations from SiteBundle, PaymentBundle and ShopBundle (29/07/2026) [BC-Break]
- Added an optional `role` key on `AlertProviderInterface` entries (29/07/2026)
- `AlertBuilder` now drops the alerts the current user lacks the `role` for (29/07/2026)
- `BackupAlertProvider`'s alerts are now restricted to `ROLE_SUPER_ADMIN` (29/07/2026)
- `AlertBuilder` gained a `Security` constructor argument (29/07/2026) [BC-Break]
- Added `symfony/console`, `symfony/finder` and `symfony/security-bundle` to the requirements (29/07/2026)
- Moved `BackupCommand` from SiteBundle (29/07/2026) [BC-Break]
- Renamed the command to `c975l:config:backup`, `c975l:site:backup` kept as an alias (29/07/2026)
- Moved the `site-backup-*` config entries and their translations from SiteBundle, slugs unchanged (29/07/2026) [BC-Break]
- Added `symfony/mailer` and `symfony/process` to the requirements (29/07/2026)
- `c975l:config:backup` now archives `private/` as well as `public/`, one archive per root (29/07/2026)
- `c975l:config:backup` now verifies every archive with `bzip2 --test` and records its size (29/07/2026)
- `c975l:config:backup` now reports a table only once its dump exists, with its size (29/07/2026)
- `c975l:config:backup` now compares the tables dumped against `INFORMATION_SCHEMA` and errors on the difference (29/07/2026)
- `c975l:config:backup` now names the empty files it discards instead of deleting them silently (29/07/2026)
- Added the `site-backup-retention-days` config (29/07/2026)
- `c975l:config:backup` now purges the runs older than `site-backup-retention-days` from the server (29/07/2026)
- Added `BackupRetentionPurger` (29/07/2026)
- Added `BackupResultRecorder`, every run recording a `HealthCheckResult` row of kind `backup` (29/07/2026)
- Added `backup` to the Health check page's site-wide kinds (29/07/2026)
- Added `BackupAlertProvider`, alerting on a backup that failed, that shrank, or that stopped running (29/07/2026)
- Added the `site-backup-max-age-hours` config, past which the dashboard alerts (29/07/2026)
- Added `BackupHealthCheckAdviceProvider` (29/07/2026)
- Added `ByteFormatter` (29/07/2026)
- Added `HealthCheckResultRepository::findLatestByKind()` (29/07/2026)
- `BackupCommand` gained `BackupRetentionPurger` and `BackupResultRecorder` constructor arguments (29/07/2026) [BC-Break]
- Added the readme section on the backup (29/07/2026)
- Added `BackupCommandTest`, `BackupRetentionPurgerTest`, `BackupResultRecorderTest`, `BackupAlertProviderTest`, `BackupHealthCheckAdviceProviderTest` and `ByteFormatterTest` (29/07/2026)
- Added `GuidedProjectProviderInterface` and the `c975l.guided_project_provider` tag, each bundle contributing its own guided projects (28/07/2026)
- Added `GuidedProjectBuilder`, merging the projects and sorting them by `order` (28/07/2026)
- `GuidedProjectBuilder` drops the projects the current user lacks the `role` for (28/07/2026)
- Added the `management_guided_project_steps` route, serving one project's steps as JSON (28/07/2026)
- Added the "Guided projects" dashboard button and its list (28/07/2026)
- Added `assets/js/guided-project.js`, walking a project across the admin screens it spans (28/07/2026)
- Added `GuidedProjectMountBuilder`, mounting that panel on every admin page (28/07/2026)
- Added `GuidedProjectKeyGenerator`, scoping the panel's browser storage per user (28/07/2026)
- Extracted `assets/js/guided-ui.js`, shared by the guided tour and the guided projects (28/07/2026)
- Renamed the `onboarding-tour-highlight` CSS class to `guided-highlight` (28/07/2026) [BC-Break]
- `DashboardController` gained `GuidedProjectBuilder` and `GuidedProjectMountBuilder` constructor arguments (28/07/2026) [BC-Break]
- Added the readme section on contributing guided projects (28/07/2026)
- Added `GuidedProjectBuilderTest`, `GuidedProjectKeyGeneratorTest`, `GuidedProjectMountBuilderTest` and `GuidedProjectControllerTest` (28/07/2026)

## v5.11.3

- The row opening each url group of the Health check table is now tinted with that page's own verdict - the worst status among the rows currently listed for it - the neutral blue kept as a fallback for an unknown status (28/07/2026)
- Those tints read Bootstrap's `--bs-*-bg-subtle` variables, so they follow EasyAdmin's light/dark theme (28/07/2026)
- `c975l:config:load-all` no longer lists the entries no `configs*.json` declares anymore (28/07/2026)
- `ConfigLoadAllCommand` lost its `ConfigRepository` constructor argument (28/07/2026) [BC-Break]

## v5.11.2

- Replaced ids by hash in translations (27/07/2026)

## v5.11.1

- Removed `ThemePresetProviderInterface`, `ThemePresetRegistry` and the `c975l.theme_preset_provider` tag (27/07/2026) [BC-Break]
- `c975l:config:check-importmap` now also adds the third-party packages the c975L bundles' JS imports by bare specifier (27/07/2026)
- `c975l:config:check-importmap` reports a bare specifier it can't resolve under `vendor/` instead of guessing at it (27/07/2026)
- Added `ImportmapSpecifierLocator`, resolving a bare specifier from the package's own `assets/package.json` (27/07/2026)
- The "Run health check now" button now queues one `c975l:health-check:run --kind=…` job per kind, instead of running every provider in the admin's own request (27/07/2026)
- `HealthCheckController` gained a `MessageBusInterface` constructor argument (27/07/2026) [BC-Break]
- Added `symfony/messenger` to the requirements (27/07/2026)
- Added `HealthCheckRunner::getKinds()` and `HealthCheckRunCommand::NAME` (27/07/2026)
- Added `HealthCheckAlertProvider`, alerting on the last run's errors and warnings, with its date (27/07/2026)
- Replaced the `flash.health_check_run_success` translation by `flash.health_check_queued` (27/07/2026) [BC-Break]
- Added the readme section on routing `RunCommandMessage` for the on-demand runs (27/07/2026)
- Split the long methods of the commands, controllers and services into single-purpose private ones, without behaviour change (27/07/2026)
- Added `HealthCheckAlertProviderTest` (27/07/2026)
- Added `ImportmapSpecifierLocatorTest` (27/07/2026)

## v5.11

- Added the `c975l:config:set` command, setting one entry from the command line or several from a JSON file (27/07/2026)
- `c975l:config:set` takes `--if-empty` to only fill entries still empty, and `--dry-run` to list the changes without writing (27/07/2026)
- `c975l:config:set` skips empty and unchanged values, so an incomplete file never blanks out a live setting (27/07/2026)
- `c975l:config:set` exits non-zero on an unknown slug or on a value not matching its entry `kind` (27/07/2026)
- `c975l:config:set` encrypts sensitive values and masks them in its output, and refuses them when `C975L_VAULT_KEY` is not defined (27/07/2026)
- Added the readme section on setting values from the command line (27/07/2026)
- Added `ConfigDeclarationLocator`, single source of truth for the `configs*.json` files declaring config entries (27/07/2026)
- `c975l:config:load-all` now also loads the consuming application's own `config/configs*.json`, out of reach of the `vendor/c975l/*` glob until now (27/07/2026)
- `c975l:config:load-all` now reports the entries in database no `configs*.json` declares anymore, and points at `c975l:config:prune` (27/07/2026)
- Added the `c975l:config:prune` command, listing undeclared entries and deleting them with `--force` (27/07/2026)
- `c975l:config:prune` refuses to run when no `configs*.json` is found, so an unfinished install can't turn every entry into an orphan (27/07/2026)
- `c975l:config:prune` asks for confirmation before deleting in interactive mode (27/07/2026)
- Added `ConfigRepository::findAllSlugs()` (27/07/2026)
- Added the readme sections on loading the application's own configs and on pruning undeclared entries (27/07/2026)
- `c975l:config:load-all` now re-syncs the `sensitive` flag from the declaration, encrypting or decrypting the stored value accordingly - an entry that stops being sensitive no longer keeps an unreadable `C975L:…` value (27/07/2026)
- The `sensitive` flag is left untouched when its value can't be converted (no `C975L_VAULT_KEY`, or value encrypted with another key), rather than storing something unusable (27/07/2026)
- Added the "SQL + secrets" export (`ROLE_SUPER_ADMIN`), upserting sensitive values too for environments sharing the same `C975L_VAULT_KEY` (27/07/2026)
- Added the readme section on exporting secrets between environments sharing a vault key (27/07/2026)
- The config list no longer masks an empty sensitive value as `••••••••`, which hid the very entries the dashboard alerts ask to fill in (27/07/2026)
- Added the "Obsolete configs" dashboard shortcut and page (`ROLE_SUPER_ADMIN`), listing undeclared entries and deleting the ticked ones (27/07/2026)
- The "Obsolete configs" page recomputes the orphans on submit, so a stale form can't delete an entry declared again since (27/07/2026)
- The "Obsolete configs" page hides a sensitive value, showing only that it would be lost (27/07/2026)
- Added an optional `method` key on `ShortcutProviderInterface`, `GET` rendering the tile as a link instead of a POST form (27/07/2026)
- `c975l:config:load-all` now points at the "Obsolete configs" page as well as at `c975l:config:prune` (27/07/2026)
- Added the readme sections on the "Obsolete configs" page and on `GET` shortcuts (27/07/2026)
- `c975l:config:prune` and the "Obsolete configs" page now refuse to run when a `configs*.json` can't be parsed (27/07/2026)
- `c975l:config:load-all` no longer reports the entries of an unparsable `configs*.json` as no longer declared (27/07/2026)
- `c975l:config:set` no longer accepts `--5` for an `int` entry (27/07/2026)
- The `deployment` health check kind is now shown in the Health check "Site" section instead of once per page (27/07/2026)
- Added the readme tip on `SitemapProviderInterface` implementations being health-checked by SiteBundle at no extra cost (27/07/2026)

## v5.10

- Added `DevProfilePathProviderInterface`/`DevProfileCollector`/`DevProfileAnalyzer`/`DevProfileRunner` and the `c975l:dev-profile:run` command, listing what the dev toolbar would flag on every declared page (26/07/2026)
- `c975l:dev-profile:run` and everything behind it are marked `#[When('dev')]` (26/07/2026)
- `c975l:dev-profile:run` takes `--path` (repeatable) and `--all` (26/07/2026)
- `c975l:dev-profile:run` accepts a `--path` no provider declares (26/07/2026)
- `c975l:dev-profile:run` exits non-zero on an error-level offence (26/07/2026)
- Added the readme sections on the dev profile and on contributing dev profile paths (26/07/2026)
- `c975l:dev-profile:run` reports the missing profiler instead of a clean page on a dev environment without `symfony/profiler-pack` (26/07/2026)

## v5.9.3

- Added `SitemapProviderInterface`/`SitemapWriter` and the `c975l:sitemaps:create` command, writing every bundle's sub-sitemap plus the sitemap index (26/07/2026)
- Added a "Create sitemaps" dashboard shortcut, and the `@c975LConfig/sitemaps/` sitemap templates, both moved over from SiteBundle (26/07/2026)
- `SitemapWriter` now writes through `symfony/filesystem`, no longer failing silently on an unwritable `public/` (26/07/2026)
- `SitemapWriter` now defaults a missing `lastmod`/`changefreq`/`priority` (26/07/2026)
- `SitemapProviderInterface`'s `priority` is now an integer on the 0-10 scale, converted and bounded to the protocol's 0.0-1.0 by `SitemapWriter` (26/07/2026)
- `SitemapWriter` now removes the file of a provider that no longer has any url (26/07/2026)
- `SitemapWriter` now throws when two providers declare the same `getSitemapName()` (26/07/2026)
- The "Create sitemaps" shortcut now shows an error flash instead of a success one when writing fails (26/07/2026)

## v5.9.2

- `site-made-by-logo` now accepts a relative AssetMapper path, not only an absolute URL (26/07/2026)
- `c975l:config:check-importmap` now warns when `assets/controllers.json` still enables `@symfony/ux-chartjs` (26/07/2026)
- Added a readme section on disabling ux-chartjs in `assets/controllers.json` (26/07/2026)
- Added `HealthCheckAdviceBuilder::key()`; `HealthCheckAdviceProviderInterface::buildAdvice()` is now keyed by it instead of by kind (26/07/2026) [BC-Break]
- Fixed every url's Health check row showing the last checked url's advice (26/07/2026)
- Added an optional `items` key on an advice line, listing the individual offenders behind it as a collapsed list (26/07/2026)
- `HealthCheckAdviceBuilder::build()` now appends two providers' lines for the same result instead of overwriting (26/07/2026)
- Fixed the readme's health check advice example, still keyed by kind (26/07/2026)
- Fixed the Health check table crashing under `strict_variables` on an advice item omitting its optional `url`/`label` (26/07/2026)

## v5.9.1.1

- Added link in readme (24/07/2026)

## v5.9.1

- Added `ImportmapProviderInterface`/`ImportmapRegistry` and `c975l:config:check-importmap` command, auto-adding bundle-contributed importmap.php entries on `composer update` (24/07/2026)
- ConfigBundle's own admin.js importmap entry is now contributed via `ImportmapProviderInterface`, no longer a manual `importmap.php` edit (24/07/2026)
- `ImportmapProviderInterface` now splits `getAdminImportmapEntries()`/`getImportmapEntries()`, mirroring UiBundle's admin/non-admin script contracts (24/07/2026)

## v5.9

- Fixed the Health check trend chart summing every run of the day instead of just the latest (24/07/2026)
- Added an `editUrl` key to `HealthCheckProviderInterface::runChecks()`, shown as an edit link on the Health check table (24/07/2026)
- Added `HealthCheckAdviceProviderInterface`/`HealthCheckAdviceBuilder` for per-row Health check advice (24/07/2026)
- Added a `pinned` key to `MenuProviderInterface::getLinks()`, pinning a link last regardless of label (24/07/2026)
- Added a "Visit the site" link to the dashboard's Links section (24/07/2026)
- Added an explanatory paragraph to the "What's new" page (24/07/2026)
- Removed the essential actions checklist's progress bar, keeping only its "X/Y configured" text (24/07/2026)
- Added `HealthCheckResult::STATUS_SKIPPED`, for a check that never ran (24/07/2026)
- Added a "skipped" filter option and neutral badge color to the Health check table (24/07/2026)
- `_table.html.twig` accepts a `showFilters` option to hide the search/status/kind bar (24/07/2026)
- Added `HealthCheckProviderInterface`/`HealthCheckRunner`/`HealthCheckResult` and a "Health check" dashboard page listing the latest per-page results (23/07/2026)
- Added `c975l:health-check:run` command, refreshing health check results without blocking a request (23/07/2026)
- Added a "Run health check now" button on the Health check page, running the same `HealthCheckRunner` as the command (23/07/2026)
- Removed the command name from the Health check page's text now that it has a "Run health check now" button (23/07/2026)
- Added a "Guided tour" button on the dashboard, highlighting sidebar items that declare a `description` (23/07/2026)
- The Health check page now also shows the dashboard's alerts (23/07/2026)
- `HealthCheckRunner::run()` accepts an `$onlyKinds` filter (24/07/2026)
- Added a "Health check" page "Export (CSV)" button (24/07/2026)
- Added `symfony/ux-chartjs` and a trend chart (`HealthCheckTrendChartBuilder`) on the Health check page (24/07/2026)
- The Health check table can now be sorted (click a column) and filtered (text/status/kind) (24/07/2026)
- Fixed the Health check page crashing (`Unknown "unique" filter`) - kind filter options now computed in `HealthCheckController` (24/07/2026)
- Fixed the guided tour and Health check table never connecting - both controllers now registered as `onboarding-tour`/`health-check-table` (24/07/2026)
- Fixed the guided tour's and Health check table's inline styles violating CSP - both now ship through `management.css`/`.min.css` (24/07/2026)
- `controllers-admin.js` now guards against running more than once per page (24/07/2026)
- Removed the raw JSON details dump from the Health check table's last column (24/07/2026)
- PageSpeed rows on the Health check table now show Lighthouse-style colored score gauges instead of plain text (24/07/2026)
- Reworked the PageSpeed score gauges: hollow center, thicker colored ring, category name and score as text underneath (24/07/2026)
- Fixed "Canvas is already in use" on the Health check trend chart - `controllers-admin.js` now destroys existing charts before recreating them (24/07/2026)
- Removed the per-row "Checked at" column from the Health check table - shown once above the table instead (24/07/2026)
- Extracted the Health check table's `status_badge`/`pagespeed_gauges` Twig macros into a shared `_macros.html.twig` (24/07/2026)
- Added `HealthCheckResultRepository::findLatestByUrl()` (24/07/2026)
- The "security-headers" result is now shown as its own line above the table instead of mixed in as a per-page row (24/07/2026)
- The Health check page now splits every site-wide kind into its own "Site" section, separate from the per-page table (24/07/2026)
- Guided tour steps now skip a link the current user lacks the role for, and pass a link's `label_parameters` to the translator (24/07/2026)
- Fixed the essential actions progress bar's fixed `id`, now randomized per render (24/07/2026)
- `HealthCheckController::run()` now shows an error flash on an invalid CSRF token (24/07/2026)
- `HealthCheckRunner::run()` now skips `flush()` on a run that persists nothing (24/07/2026)
- Added missing French/Spanish translations for `label.health_check_status_skipped` (24/07/2026)
- Centered the guided tour panel in the viewport instead of anchoring it to the bottom-right corner (24/07/2026)
- Fixed the guided tour never opening EasyAdmin's collapsed "Avancé" submenu (24/07/2026)
- Added `MenuBuilder::getOrderedMenus()`; guided tour steps now follow it instead of a flat alphabetical merge (24/07/2026)
- The "Importer du contenu" guided tour step now has a description (24/07/2026)
- The "site" guided tour step now has a description (24/07/2026)

## v5.8.9

- Added shortcut categories (`ShortcutProviderInterface::CATEGORY_*`) ordering same-themed dashboard shortcut tiles next to each other (23/07/2026)
- Removed the red "active" dashboard shortcut tile styling (23/07/2026)
- Fixed a dashboard shortcut with no `role` key never being shown, regardless of the user's role (23/07/2026)

## v5.8.8

- Added `EssentialActionProviderInterface`, `EssentialActionBuilder` and a dashboard "Essential actions" checklist (23/07/2026)
- Added `ConfigEssentialActionProvider`, ConfigBundle's own core essential actions (23/07/2026)
- Added `DashboardWidgetProviderInterface` and `DashboardWidgetBuilder` for bundles to contribute dashboard widgets (23/07/2026)
- Added an `advanced` menu tier collapsing opted-in items into one "Avancé" submenu (23/07/2026)
- Lowered `WhatsNewBuilder`'s default cap to 5 entries and made the dashboard section always visible (23/07/2026)
- Redesigned dashboard shortcuts as a tile grid instead of pill buttons (23/07/2026)

## v5.8.7

- Added `ExportProviderInterface` for bundles to contribute content to an "export sync all" shortcut (23/07/2026)
- Added `SyncAllExporter` and an "Export sync (everything)" dashboard shortcut bundling every registered export provider (23/07/2026)
- Added `ConfigExportProvider`, single source of truth for Config's exports (23/07/2026)
- `ContentImportController` now dispatches multi-kind "sync all" zips to their respective import providers (23/07/2026)
- Fixed EasyAdmin's sidebar menu picking up the content-list bullet style meant for regular `<ul>` lists (23/07/2026)
- Fixed the Config search bar being unusable on the "pick a group" screen (23/07/2026)

## v5.8.6

- Expanded the Config index/edit explanatory text to describe the page and its sensitive-value handling (22/07/2026)
- Removed the Config detail/view page (22/07/2026)
- Added a Cancel action to the Config edit page (22/07/2026)
- Added a "Sync" export producing a re-importable content zip, and an "Import content" dashboard screen to upload it (22/07/2026)
- Added `ImportProviderInterface` for bundles to accept synced content on another environment (22/07/2026)
- Lowered the SQL/JSON/Sync export permission from `ROLE_SUPER_ADMIN` to `site-role-admin` (22/07/2026)
- SQL export now excludes restricted configs for non-`ROLE_SUPER_ADMIN` users, matching CSV/JSON (22/07/2026)

## v5.8.5

- Added a `font` config kind, rendering a `<select>` built from UiBundle's `FontRegistry` instead of free text (21/07/2026)
- Added `Config::GENERIC_FONT_FAMILIES` (`serif`/`sans-serif`/`monospace`), always offered alongside custom fonts (21/07/2026)

## v5.8.4

- Added `ProcedureProviderInterface`, `ProcedureBuilder` and `ProcedureJsonReader` for bundles to contribute admin procedures to the consuming app's AI assistant (21/07/2026)

## v5.8.3

- Corrected Deprecations check (20/07/2026)

## v5.8.2

- Added `ConfigSqlExporter`, reused by the Config SQL export and a new dashboard shortcut (20/07/2026)
- Added a dashboard shortcut to export the SQL configuration without opening Config (20/07/2026)
- Shortened several config/site_config translation strings (en/es/fr) (20/07/2026)

## v5.8.1

- Corrected dependency (20/07/2026)

## v5.8

- `Config` list now opens on a "pick a group" screen instead of one flat table (19/07/2026)
- Removed the `group` EasyAdmin filter from the Config list (19/07/2026) [BC-Break]
- Added `Config::GROUP_MESSENGER` config group and translation (19/07/2026)
- Removed `ThemeCrudController` and its "Theme" menu entry, see UPGRADE.md (19/07/2026) [BC-Break]
- Added `/m` shortcut redirecting to `/management`, reachable during maintenance mode too (19/07/2026)

## v5.7.3

- Fixed `Config` index actions column wrapping Edit/Detail icons onto two lines (19/07/2026)
- Config list search now matches against translated label/description instead of raw translation keys (19/07/2026)
- `json` kind values are now pretty-printed on the edit page (19/07/2026)
- Added `ReadonlyTextType`, `description` now renders as plain text instead of a disabled input on the edit page (19/07/2026)
- Added `Config::GROUP_AI` config group (19/07/2026)

## v5.7.2

- `EasyAdminActionHelper::toIconOnly()` now merges with existing HTML attributes (eg. `target`) instead of overwriting them (17/07/2026)

## v5.7.1

- `c975l:deprecations:check` now distinguishes exact FQCN matches from namespace-only "possible" matches, shown separately (17/07/2026)
- `c975l:deprecations:check` no longer reports messages with no match at all in app/c975L source, since nothing can be done about a third-party package's own deprecations (17/07/2026)
- Temporarily hid the theme presets action group on the Theme page pending rework, `applyPreset()` stays reachable (17/07/2026)

## v5.7

- `ThemeCrudController::applyPreset()` now only ever overwrites `theme-stylesheet`, colors/fonts stay admin-owned (16/07/2026) [BC-Break]
- `ThemeCrudController` now translates a preset's label in the domain declared by its provider, falling back to `config` (16/07/2026) [BC-Break]
- Removed the now-misplaced `theme_preset_default`/`warm_artisan`/`blueprint` translations (moved to SiteBundle) (16/07/2026) [BC-Break]
- Added a "Preview" action per theme preset when the provider supplies a `previewUrl` (16/07/2026)
- Added `label.theme_preset_blueprint` translation for SiteBundle's new `blueprint` theme preset (16/07/2026)
- `ThemePresetProviderInterface`'s `previewUrl` is now a lazy callable, fixing a router deadlock that 500'd `/management` (16/07/2026) [BC-Break]
- Fixed `MenuBuilder` resolving a link's URL through EasyAdmin's `AdminUrlGenerator`, now uses the plain router (16/07/2026) [BC-Break]
- Added an optional `target` key to `MenuProviderInterface::getLinks()`, shown with an external-link glyph (16/07/2026)
- Added an optional `url` key to `MenuProviderInterface::getLinks()`, a literal absolute URL used as-is (16/07/2026)
- Added `EasyAdminActionHelper::toIconOnly()`, index-page inline row actions (Edit/Detail) now show icon-only with the label as hover title (16/07/2026)

## v5.6

- Added `theme` config group and `ThemeCrudController` for managing theme CSS variables (colors, fonts, light/dark mode) in their own dashboard view (15/07/2026)
- Added `ThemePresetProviderInterface` and `ThemePresetRegistry` so satellite bundles (e.g. SiteBundle) can contribute one-click theme presets, applied in a single flush via `ThemeCrudController::applyPreset()` (15/07/2026)
- `c975l:config:load-all` now matches `configs*.json` so a bundle can ship several config files, e.g. `configs.json` + `configs-css.json` (15/07/2026)
- `DashboardController::configureAssets()` links the compiled `bundles/build/admin.css` outside dev instead of each bundle's stylesheet separately (15/07/2026)
- Added `ConfigRepository::findByGroup()` (15/07/2026)
- `ThemeCrudController` now mirrors `ConfigCrudController`'s restricted-config protection: `site-role-editor` can view the Theme page and apply a preset, manual field editing requires `ROLE_SUPER_ADMIN`, and `restricted` theme entries stay hidden below `ROLE_SUPER_ADMIN` (15/07/2026)
- Reverted `vendor-dir` to the default `vendor/` (previously `.vendor`) in `composer.json` and `phpunit.xml.dist` (15/07/2026)

## v5.5.10

- Added app/src for triggering deprecations (14/07/2026)

## v5.5.9

- Added test to trigger deprecations (14/07/2026)

## v5.5.8

- Suppressed DependencyInjection as not needed (14/07/2026)

## v5.5.7

- Added LinkableRouteProvider for management Route (14/07/2026)

## v5.5.6

- Moved tests to the right place (13/07/2026)

## v5.5.5

- Added tests (12/07/2026)

## v5.5.4

- Added translations for configs label (12/07/2026)

## v5.5.3.1

- Removed target _blank for links in menu provider (11/07/2026)

## v5.5.3

- Tagged new version due to tag problem (11/07/2026)

## v5.5.2

- Added made by logo + link on easyadmin menu bar (11/07/2026)
- Reviewed the What's new section(11/07/2026)

## v5.5.1

- Added StylesheetProvider (11/07/2026)

## v5.5

- Replaced site-role-needed by site-role-admin (11/07/2026)
- Added site-role-editor (11/07/2026)
- Added ROLE_SUPER_ADMIN to allow access to config (11/07/2026)

## v5.4

- Suppressed actions buttons on dashoard (10/07/2026)
- Commented What's New display until review (10/07/2026)
- Added isRestricted column on Config, for ROLE_SUPER_ADMIN (10/07/2026) [DB-Migration]
- Added ROLE_SUPER_ADMIN filtering (10/07/2026)

## v5.3.13.1

- Corrected sensitive on config (09/07/2026)
- Corrected translation of description on EasyAdmin (09/07/2026)

## v5.3.13

- Made description of configs translated (09/07/2026)
- Changed default fcrufd field for config value (09/07/2026)
- Suppressed icon on alerts for dashboard (09/07/2026)

## v5.3.12

- Corrected responsive for maintenance page (05/07/2026)
- Made /management and /login routes available even in maintenance mode (05/07/2026)

## v5.3.11.1

- Fixed TaggedInterface (05/07/2026)

## v5.3.11

- Added a `ShortcutProviderInterface` so any bundle can contribute quick-action buttons to the dashboard (05/07/2026)
- Added `LinkableRouteProviderInterface` so bundles without a SiteBundle dependency can expose one of their own routes as a selectable target for menu items (05/07/2026)
- `MaintenanceListener` now runs at priority 6 and lets an already-authenticated admin (`isGranted` on `site-role-admin`) through (05/07/2026)
- Added a "Toggle maintenance mode" dashboard shortcut, flipping the existing `site-maintenance` config used by `MaintenanceListener` (05/07/2026)
- Reduced the height of the dashboard alerts list (dropped the redundant severity label, compact unstyled list) (05/07/2026)
- Added a `html` kind for config values needing rich content (EasyAdmin `TextEditorField`); plain `text` is now edited as a textarea instead (05/07/2026)
- Fixed the `@c975Config` Twig namespace typo (now `@c975LConfig`) that broke the maintenance page rendering, and moved its translations from the `site` to the `config` domain (05/07/2026)
- Replaced the three separate `MenuProviderPass`/`WhatsNewProviderPass`/`AlertProviderPass` compiler passes with a single generic `TaggedInterfacePass`, and factored the repeated provider-merge loop into `ProviderMerger` (05/07/2026)

## v5.3.10

- Factored dashboard alerts behind an `AlertProviderInterface`, any bundle can now contribute alerts; existing config-severity alerts moved to `ConfigAlertProvider` (05/07/2026)
- Config CRUD's own alert list now reuses the shared `_alerts.html.twig` partial (05/07/2026)
- What's new is now in three languages (05/07/2026)

## v5.3.9

- Added a What's new section on the dashboard + menu (04/07/2026)

## v5.3.8

- Added display of config alerts on ConfigCrud index (04/07/2026)
- Transformed config value textarea to TextEditorField (04/07/2026)

## v5.3.7

- Added severity field on config + message on dashboard (04/07/2026) [Needs db update]
- Added generic `TableExporter` service (SQL/CSV/JSON via Symfony Serializer) so other bundles' CRUD controllers can add the same export action (04/07/2026)

## v5.3.6

- Added `json` kind for config values, with JSON syntax validation (04/07/2026)

## v5.3.5

- Suppressed isSystem field as all config are system, and none should be added by user (02/07/2026)
- Added `group` field to categorize configs by theme, with EasyAdmin filter + default sort (02/07/2026)
- `c975l:config:load-all` now re-syncs all meta data, `value`/`is_sensitive` remain untouched on existing configs (02/07/2026)

## v5.3.4

- Added new field isSystem (01/07/2026)
- Added encryption for sensitive values (01/07/2026)
- Added field format specific to kind in crud (01/07/2026)
- Added grouped menus and only one Links section (01/07/2026)

## v5.3.3

- Added config due to conversion to Stimulus to c975L/UiBundle blocks.js (28/06/2026)

## v5.3.2

- Removed cache ttl for values as not needed (28/06/2026)
- Added request-scoped memoization to avoid redundant cache lookups within a single HTTP request (28/06/2026)

## v5.3.1

- Suppressed unused types (27/06/2026)
- Made int type auto cast (27/06/2026)
- Added ManagementAuthenticationListener to redirect to login when credentials expire on management routes (27/06/2026)

## v5.3

- Removed the isGranted['ROLE_ADMIN'] to use the value from ConfigBundle (26/06/2026)
- Suppressed the command to load confgi from one bundle as all is easier (26/06/2026)

## v5.2.2

- Re-added ConfigParamExtension (24/06/2026)

## v5.2.1

- Added Route possibility to Dashboard (22/06/2026)
- Renamed methods (22/06/2026)
- Added translated messages (22/06/2026)

## v5.2

- Removed use of Fixtures to load default values and replaced by a Command (22/06/2026)

## v5.1

- Added missing in composer.json (22/06/2026)

## v5.0

- Made use of database to store config parameters (20/06/2026)
- Added MaintenanceListener (22/06/2026)
- Added EasyAdmin main dashboard for managing config params and othe dashboards bundles (22/06/2026)

## v4.5.1

- Corrected errors related to Form (03/11/2025)

## v4.5

- Removed use of c975L/ToolbarBundle (03/11/2025)
- Removed form to allow config as done directly in .yaml files (03/11/2025)

## v4.4.1

- Added `c975L/SiteBundle` (09/03/2025)

## v4.4

- Removed use of`c975L/ServicesBundle` (09/03/2025)
- Removed use of`c975L/IncludeLibraryBundle` (09/03/2025)

## v4.3.1

- Added ? to avoid deprecation (09/03/2025)

## v4.3

- Suppressed spaceless filter as it's deprecated (12/09/2024)

## v4.2.3

- Changed DependencyInjection Extension (10/09/2024)

## v4.2.2

- Updated Command file (31/03/2024)

## v4.2.1

- Updated README (30/01/2024)

## v4.2

- Corrected calls for Resources folder (30/01/2024)

## v4.1

- Corrected config (30/01/2024)

## v4.0.1

- Corrected AbstractBundle (20/01/2024)

## v4.0

- Changed to new recomended bundle SF 7 structure (20/01/2024)

Upgrading from v3.x? **Check UPGRADE.md**

## v3.0.2

- Added TreeBuilder return type (29/05/2023)

## v3.0.1

- Added missing return type (06/04/2023)

## v3.0

- Changed compatibility to PHP 8 (25/07/2022)

Upgrading from v2.x? **Check UPGRADE.md**

## v2.6

- Made use of ParameterBag instead of container (24/07/2022)

## v2.5.8.1

- Suppressed (missed) use of container (24/04/2022)

## v2.5.8

- Suppressed use of container (24/04/2022)

## v2.5.7

- Changed composer versions constraints (24/07/2022)

## v2.5.6

- Corrected Command return for SF 4 (14/10/2021)

## v2.5.5

- Added return for console Command (08/10/2021)

## v2.5.4

- Added condition test if newDefindeValue is NOT null (20/09/2021)

## v2.5.3

- Added key `$name` (06/09/2021)

## v2.5.2

- Removed `../` after  `kernel.project_dir` (06/09/2021)

## v2.5.1

- Replaced `kernel.root_dir` by `kernel.project_dir` (03/09/2021)

## v2.5

- Removed versions constraints in composer (03/09/2021)

## v2.4.3

- Corrected unneded config in DependencyInjection (04/03/2020)
- Removed switch function to reduce Cyclomatic complexity (05/03/2020)

## v2.4.2

- Cosmetic changes dur to Codacy review (04/03/2020)

## v2.4.1

- Removed composer.lock from Git (19/02/2020)

## v2.4

- Made use of apply spaceless (05/08/2019)

## v2.3.6.1

- Changed Github's author reference url (08/04/2019)

## v2.3.6

- Made use of Twig namespace (11/03/2019)
- Added declaration of $formFactory (11/03/2019)

## v2.3.5

- Removed deprecations for @Method in `README.md` example (13/02/2019)
- Implemented AstractController instead of Controller in `README.md` example (13/02/2019)
- Modified Dependencyinjection rootNode to be not empty (13/02/2019)

## v2.3.4

- Modified required versions in `composer.json` (25/12/2018)

## v2.3.3

- Added missing use (25/12/2018)

## v2.3.2

- Added rector to composer dev part (23/12/2018)
- Modified required versions in composer (23/12/2018)

## v2.3.1

- Corrected error message when config file is not created (04/12/2018)

## v2.3

- Suppressed `ConfigFirstUseCommand` and replaced by `ConfigCreateCommand` (03/12/2018)

## v2.2.5

- Modified versions in `composer.json` (03/12/2018)

## v2.2.4

- Added information in README.md (28/10/2018)
- Added method `getContainerParameter()` (+Twig extension) as a shortcut to avoid injecting container when `ConfigService` is already injected (31/10/2018)

## v2.2.3

- Changed location for config folder for SF4 (18/10/2018)

## v2.2.2

- Fixed `getConfig()` that was setting all defined properties found instead of setting only those defined in bundle (03/09/2018)

## v2.2.1

- Added `date` field (02/09/2018)
- Changed behaviour of `ConfigFirstUseCommand` to also define fields not linked to root and not already defined (to not erase) (02/09/2018)

## v2.2

- Added possibility to define multiple roots in bundle.yaml (02/09/2018)
- Fixed `ConfigFirstUseCommand` to use `setConfig()` method (02/09/2018)

## v2.1.4

- Fixed exportation of arrays (01/09/2018)

## v2.1.3

- Updated composer.json (01/09/2018)
- Added root display in Command (01/09/2018)
- Fixed Twig extension (01/09/2018)

## v2.1.2

- Simplified `getConfigFolder()` (31/08/2018)
- Added `hasParameter()` method (31/08/2018)
- Fixed `getParameter()` (31/08/2018)

## v2.1.1

- Replaced `isset()` by `array_key_exists()` in `getParameter()` (31/08/2018)

## v2.1

- Updated `README.md` (30/08/2018)
- Added console Command to create the config from defaut values, to be used before first use (30/08/2018)

## v2.0.1

- Fixed missing returns of $parameters (30/08/2018)

## v2.0

- Created branch 1.x (30/08/2018)
- Modified files to use own sytem of key-value for config (30/08/2018)

Upgrading from v1.x? **Check UPGRADE.md**

## v1.2.2

- Updated `README.md` (30/08/2018)

## v1.2.1.1

- Updated `README.md` (29/08/2018)

## v1.2.1

- Fixed typo in `README.md` (29/08/2018)
- Added folder for SF4 (29/08/2018)

## v1.2

- Added the info field as title for label + field (29/08/2018)
- Re-designed `ConfigType` in a cleaner way (29/08/2018)

## v1.1.1

- Added test to check if the root node is already defined in the yaml file (28/08/2018)
- Updated `README.md`` (29/08/2018)

## v1.1

- Added core files (27/08/2018)

## v1.0

- Creation of bundle (26/08/2018)
