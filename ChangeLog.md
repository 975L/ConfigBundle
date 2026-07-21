# ChangeLog

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

## v5.2.1

- Added Route possibility to Dashboard (22/06/2026)
- Renamed methods (22/06/2026)
- Added translated messages (22/06/2026)

## v5.2

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

## v4.3.1

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

## v2.4.2

- Cosmetic changes dur to Codacy review (04/03/2020)

## v2.4.1

- Removed composer.lock from Git (19/02/2020)

## v2.4

- Made use of apply spaceless (05/08/2019)

## v2.3.6.1

- Changed Github's author reference url (08/04/2019)

## v2.3.6

- Made use of Twig namespace (11/03/2019)
- Added declaration of $formFactory (11/03/2019)

## v2.3.5

- Removed deprecations for @Method in `README.md` example (13/02/2019)
- Implemented AstractController instead of Controller in `README.md` example (13/02/2019)
- Modified Dependencyinjection rootNode to be not empty (13/02/2019)

## v2.3.4

- Modified required versions in `composer.json` (25/12/2018)

## v2.3.3

- Added missing use (25/12/2018)

## v2.3.2

- Added rector to composer dev part (23/12/2018)
- Modified required versions in composer (23/12/2018)

## v2.3.1

- Corrected error message when config file is not created (04/12/2018)

## v2.3

- Suppressed `ConfigFirstUseCommand` and replaced by `ConfigCreateCommand` (03/12/2018)

## v2.2.5

- Modified versions in `composer.json` (03/12/2018)

## v2.2.4

- Added information in README.md (28/10/2018)
- Added method `getContainerParameter()` (+Twig extension) as a shortcut to avoid injecting container when `ConfigService` is already injected (31/10/2018)

## v2.2.3

- Changed location for config folder for SF4 (18/10/2018)

## v2.2.2

- Fixed `getConfig()` that was setting all defined properties found instead of setting only those defined in bundle (03/09/2018)

## v2.2.1

- Added `date` field (02/09/2018)
- Changed behaviour of `ConfigFirstUseCommand` to also define fields not linked to root and not already defined (to not erase) (02/09/2018)

## v2.2

- Added possibility to define multiple roots in bundle.yaml (02/09/2018)
- Fixed `ConfigFirstUseCommand` to use `setConfig()` method (02/09/2018)

## v2.1.4

- Fixed exportation of arrays (01/09/2018)

## v2.1.3

- Updated composer.json (01/09/2018)
- Added root display in Command (01/09/2018)
- Fixed Twig extension (01/09/2018)

## v2.1.2

- Simplified `getConfigFolder()` (31/08/2018)
- Added `hasParameter()` method (31/08/2018)
- Fixed `getParameter()` (31/08/2018)

## v2.1.1

- Replaced `isset()` by `array_key_exists()` in `getParameter()` (31/08/2018)

## v2.1

- Updated `README.md` (30/08/2018)
- Added console Command to create the config from defaut values, to be used before first use (30/08/2018)

## v2.0.1

- Fixed missing returns of $parameters (30/08/2018)

## v2.0

- Created branch 1.x (30/08/2018)
- Modified files to use own sytem of key-value for config (30/08/2018)

Upgrading from v1.x? **Check UPGRADE.md**

## v1.2.2

- Updated `README.md` (30/08/2018)

## v1.2.1.1

- Updated `README.md` (29/08/2018)

## v1.2.1

- Fixed typo in `README.md` (29/08/2018)
- Added folder for SF4 (29/08/2018)

## v1.2

- Added the info field as title for label + field (29/08/2018)
- Re-designed `ConfigType` in a cleaner way (29/08/2018)

## v1.1.1

- Added test to check if the root node is already defined in the yaml file (28/08/2018)
- Updated `README.md`` (29/08/2018)

## v1.1

- Added core files (27/08/2018)

## v1.0

- Creation of bundle (26/08/2018)
